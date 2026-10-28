<?php

use App\Jobs\SendDiscordWebhook;
use App\Models\Personnel;
use App\Models\Role;
use App\Models\User;
use App\Models\Vehicle;
use App\Notifications\InboxNotification;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Modules\Enotf\Enums\ProtocolOrigin;
use Modules\Enotf\Enums\ProtocolStatus;
use Modules\Enotf\Events\ProtocolReleased;
use Modules\Enotf\Models\EnotfProtocol;
use Modules\Enotf\Models\Medication;
use Modules\Enotf\Models\MedicationGiven;
use Modules\Enotf\Models\ProtocolCrew;
use Modules\Enotf\Support\FieldSchema;
use Modules\Enotf\Support\ProtocolMerger;
use Modules\Enotf\Support\ProtocolValues;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

function enotfLifecycleUser(): User
{
    return User::factory()->create(['personnel_id' => Personnel::factory()->create()->id]);
}

function lc_protocol(array $over = [], array $set = []): EnotfProtocol
{
    $p = (new EnotfProtocol)->forceFill($over + ['enr' => (string) random_int(100000, 999999)]);
    $p->save();
    $full = json_decode((string) file_get_contents(base_path('tests/Fixtures/enotf-conditions.json')), true)['full'];
    unset($full['medis']);
    $full = ['awsicherung_neu' => 1, 'prot_by' => 0] + $full;
    $checked = FieldSchema::validate($set + $full);
    expect($checked['errors'])->toBe([]);
    app(ProtocolMerger::class)->apply($p, $p->revision, $checked['values']);

    return $p->fresh();
}

function lc_login(User $user, Vehicle $vehicle): void
{
    test()->actingAs($user)->post('/enotf/login', ['pin' => '1234', 'vehicle_id' => $vehicle->id])->assertRedirect('/enotf/overview');
}

function lc_role(string ...$perms): User
{
    test()->seed(RoleSeeder::class);
    $role = Role::create(['name' => 'R'.uniqid(), 'guard_name' => 'web', 'priority' => 500, 'color' => 'info']);
    $role->syncPermissions($perms);

    return User::factory()->withRole($role)->create();
}

it('releases once: 409 on repeat, one event, one bell per other crew account, one webhook, all after the commit', function () {
    Queue::fake();
    Notification::fake();
    $vehicle = Vehicle::factory()->create(['service' => Vehicle::SERVICE_EMS]);
    $user = enotfLifecycleUser();
    $mate = enotfLifecycleUser();
    $p = lc_protocol(['vehicle_transport_id' => $vehicle->id, 'vehicle_transport_label' => 'RTW 1']);
    foreach ([[1, $user], [2, $mate]] as [$pos, $u]) {
        (new ProtocolCrew)->forceFill(['enotf_protocol_id' => $p->id, 'slot' => 'transport', 'position' => $pos, 'personnel_id' => $u->personnel_id, 'name' => 'X'])->save();
    }
    lc_login($user, $vehicle);

    $this->postJson("/enotf/p/{$p->enr}/release")->assertOk()->assertJsonPath('status', 'released');
    $this->postJson("/enotf/p/{$p->enr}/release")->assertStatus(409)->assertJsonPath('status', 'released');

    $fresh = $p->fresh();
    expect($fresh->status)->toBe(ProtocolStatus::Released)->and($fresh->revision)->toBe($p->revision + 1)
        ->and($fresh->released_by)->toBe($user->id)->and($fresh->getAttribute('released_by_name'))->toBe('Mustermann');
    Queue::assertPushed(SendDiscordWebhook::class, 1);
    Notification::assertSentToTimes($mate, InboxNotification::class, 1);
    Notification::assertNothingSentTo($user);
    $this->patchJson("/enotf/p/{$p->enr}", ['revision' => $fresh->revision, 'fields' => ['pfname' => 'Y']])->assertStatus(409);

    Event::fake([ProtocolReleased::class]);
    $q = lc_protocol(['vehicle_transport_id' => $vehicle->id], ['pfname' => null]);
    $this->postJson("/enotf/p/{$q->enr}/release")->assertStatus(422)->assertJsonPath('violations.0.rule', 'pfname');
    expect($q->fresh()->status)->toBe(ProtocolStatus::Open);
    Event::assertNotDispatched(ProtocolReleased::class);
});

it('enforces plausibility, except Fehleinsatz and the real edivi.edit right (pfname always)', function () {
    Queue::fake();
    $vehicle = Vehicle::factory()->create(['service' => Vehicle::SERVICE_EMS]);
    lc_login(enotfLifecycleUser(), $vehicle);

    $gap = lc_protocol(['vehicle_transport_id' => $vehicle->id], ['patsex' => null, 'spo2' => null]);
    $this->postJson("/enotf/p/{$gap->enr}/release")->assertStatus(422)->assertJsonCount(2, 'violations');
    $miss = lc_protocol(['vehicle_transport_id' => $vehicle->id], ['patsex' => null, 'spo2' => null, 'transportziel' => 4]);
    $this->postJson("/enotf/p/{$miss->enr}/release")->assertOk();

    $this->flushSession();
    $editor = lc_role('edivi.edit');
    $this->actingAs($editor)->postJson("/enotf/p/{$gap->enr}/release")->assertOk();
    $noAuthor = lc_protocol([], ['patsex' => null, 'pfname' => null]);
    $this->actingAs($editor)->postJson("/enotf/p/{$noAuthor->enr}/release")->assertStatus(422)->assertJsonCount(1, 'violations');
    $super = User::factory()->create(['is_super_admin' => true]);
    $this->actingAs($super)->postJson("/enotf/p/{$noAuthor->enr}/release")->assertForbidden();
});

it('derives medis from the given medication rows or the confirmation medis_keine (legacy 0), other rules still block', function () {
    Queue::fake();
    $vehicle = Vehicle::factory()->create(['service' => Vehicle::SERVICE_EMS]);
    lc_login(enotfLifecycleUser(), $vehicle);
    $a = lc_protocol(['vehicle_transport_id' => $vehicle->id], ['medis_keine' => false]);
    $b = lc_protocol(['vehicle_transport_id' => $vehicle->id], ['patsex' => null, 'medis_keine' => null]);
    $medId = Medication::query()->value('id');
    $give = fn (EnotfProtocol $p) => $this->postJson("/enotf/p/{$p->enr}/medications", ['medication_id' => $medId, 'dose' => '1', 'unit' => 'mg', 'route' => 'i.v.'])->assertCreated();
    $give($b);
    $give($b);
    $this->deleteJson("/enotf/p/{$b->enr}/medications/".MedicationGiven::query()->value('id'))->assertOk();

    expect(ProtocolValues::from($a)['medis'])->toBe([])->and(ProtocolValues::from($b)['medis'])->toHaveCount(1);
    $this->postJson("/enotf/p/{$a->enr}/release")->assertStatus(422)->assertJsonCount(1, 'violations')->assertJsonPath('violations.0.rule', 'medis');
    $this->patchJson("/enotf/p/{$a->enr}", ['revision' => $a->fresh()->revision, 'fields' => ['medis_keine' => true]])->assertOk();
    $this->postJson("/enotf/p/{$a->enr}/release")->assertOk();
    $this->postJson("/enotf/p/{$b->enr}/release")->assertStatus(422)->assertJsonCount(1, 'violations')->assertJsonPath('violations.0.rule', 'patsex');
});

it('withdraws open manual protocols of the own vehicle only, never dispatch or released ones', function () {
    $vehicle = Vehicle::factory()->create(['service' => Vehicle::SERVICE_EMS]);
    $manual = lc_protocol(['vehicle_transport_id' => $vehicle->id]);
    $dispatch = lc_protocol(['vehicle_transport_id' => $vehicle->id, 'origin' => ProtocolOrigin::Dispatch]);
    $released = lc_protocol(['vehicle_transport_id' => $vehicle->id, 'status' => ProtocolStatus::Released]);
    lc_login(enotfLifecycleUser(), $vehicle);

    $this->delete("/enotf/p/{$dispatch->enr}")->assertForbidden();
    $this->delete("/enotf/p/{$released->enr}")->assertForbidden();
    $this->delete("/enotf/p/{$manual->enr}")->assertRedirect('/enotf/overview');
    expect($manual->fresh()->status)->toBe(ProtocolStatus::Withdrawn)
        ->and($dispatch->fresh()->status)->toBe(ProtocolStatus::Open)
        ->and(EnotfProtocol::query()->count())->toBe(3);
});

it('shows the print view only through the policy', function () {
    [$own, $other] = Vehicle::factory()->count(2)->create(['service' => Vehicle::SERVICE_EMS]);
    $named = enotfLifecycleUser();
    $p = lc_protocol(['vehicle_transport_id' => $own->id, 'status' => ProtocolStatus::Released]);
    (new ProtocolCrew)->forceFill(['enotf_protocol_id' => $p->id, 'slot' => 'transport', 'position' => 2, 'personnel_id' => $named->personnel_id, 'name' => 'N'])->save();
    $url = "/enotf/print/{$p->enr}";

    $this->get($url)->assertRedirect();
    $this->assertGuest();

    lc_login(enotfLifecycleUser(), $own);
    $this->get($url)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('Enotf/Print')
        ->where('values.pfname', 'Mustermann')->where('vitals', []));

    lc_login(enotfLifecycleUser(), $other);
    $this->get($url)->assertForbidden();

    lc_login($named, $other);
    $this->get($url)->assertOk();

    $this->flushSession();
    $this->actingAs(lc_role('edivi.view'))->get($url)->assertOk();
    $this->actingAs(User::factory()->create(['is_super_admin' => true]))->get($url)->assertOk();
    $this->actingAs(User::factory()->create())->get($url)->assertForbidden();
});

it('redirects the legacy print urls', function () {
    $p = lc_protocol();
    $this->get("/enotf/print?enr={$p->enr}")->assertStatus(301)->assertRedirect("/enotf/print/{$p->enr}");
    $this->get("/enotf/print/index.php?enr={$p->enr}")->assertStatus(301)->assertRedirect("/enotf/print/{$p->enr}");
    $this->get('/enotf/print?enr=999999999')->assertStatus(301)->assertRedirect('/enotf/print/999999999');
    $this->get('/enotf/print?enr=%3Cx%3E')->assertStatus(301)->assertRedirect('/enotf/overview');
    expect(fn () => app('router')->getRoutes()->match(Request::create('/enotf/print/index')))->toThrow(HttpException::class);
});
