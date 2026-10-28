<?php

use App\Dashboard\Widgets\HintsWidget;
use App\Models\Personnel;
use App\Models\Role;
use App\Models\User;
use App\Support\Settings;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Enotf\Enums\ProtocolStatus;
use Modules\Enotf\Models\EnotfProtocol;
use Modules\Enotf\Models\ProtocolCrew;
use Modules\Enotf\Widgets\OwnProtocolsWidget;
use Modules\FireTab\Models\FireIncident;

uses(RefreshDatabase::class);

function sp_protocol(array $over = [], ?User $crew = null): EnotfProtocol
{
    $p = (new EnotfProtocol)->forceFill($over + ['enr' => (string) random_int(100000, 999999)]);
    $p->save();
    if ($crew !== null) {
        (new ProtocolCrew)->forceFill(['enotf_protocol_id' => $p->id, 'slot' => 'transport', 'position' => 1, 'personnel_id' => $crew->personnel_id, 'name' => 'X'])->save();
    }

    return $p;
}

function sp_user(): User
{
    return User::factory()->create(['personnel_id' => Personnel::factory()->create()->id]);
}

it('finds protocols for QM only, without hidden ones and without patient data', function () {
    $this->seed(RoleSeeder::class);
    $role = Role::create(['name' => 'Q', 'guard_name' => 'web', 'priority' => 500, 'color' => 'info']);
    $role->syncPermissions(['edivi.view']);
    $qm = User::factory()->withRole($role)->create();
    $shown = sp_protocol(['enr' => '777001', 'vehicle_transport_label' => 'RTW Suchtest', 'patient_last_name' => 'Geheimname', 'patient_birthdate' => '1980-01-01']);
    sp_protocol(['enr' => '777002', 'vehicle_transport_label' => 'RTW Suchtest', 'hidden_at' => now()]);

    $res = $this->actingAs($qm)->getJson('/api/system/global-search?q=Suchtest')->assertOk()
        ->assertJsonFragment(['href' => '/enotf/p/777001'])->assertJsonMissing(['href' => '/enotf/p/777002']);
    expect($res->getContent())->not->toContain('Geheimname')->not->toContain('1980');
    $this->actingAs(sp_user())->getJson('/api/system/global-search?q=Suchtest')->assertOk()->assertJsonMissing(['href' => "/enotf/p/{$shown->enr}"]);
    $this->actingAs($qm)->getJson('/api/system/global-search?q=Geheimname')->assertOk()->assertJsonMissing(['href' => '/enotf/p/777001']);
});

it('lists only own protocols in the widget, the hint and /me/protocols (with a source filter)', function () {
    $me = sp_user();
    $mine = sp_protocol([], $me);
    sp_protocol([], sp_user());
    sp_protocol(['status' => ProtocolStatus::Withdrawn], $me);
    sp_protocol(['hidden_at' => now()], $me);
    FireIncident::query()->forceCreate(['number' => 'E-1', 'started_at' => now(), 'leader_personnel_id' => $me->personnel_id]);
    FireIncident::query()->forceCreate(['number' => 'E-2', 'started_at' => now(), 'leader_personnel_id' => sp_user()->personnel_id]);

    $items = (new OwnProtocolsWidget)->data($me)['items'];
    expect($items)->toHaveCount(1)->and($items[0]['label'])->toBe($mine->enr);
    expect(collect(app(HintsWidget::class)->data($me)['hints'])->pluck('title')->implode(' '))->toContain($mine->enr);

    $groups = collect($this->actingAs($me)->get('/me/protocols')->assertOk()->viewData('page')['props']['groups'])->keyBy('key');
    expect($groups)->toHaveCount(2)->and(array_column($groups['firetab']['rows'], 'title'))->toBe(['E-1'])
        ->and(array_column($groups['enotf']['rows'], 'title'))->toBe([$mine->enr]);
    $this->get('/me/protocols?source=enotf')->assertInertia(fn ($p) => $p->has('groups', 1)->where('groups.0.key', 'enotf'));
    $this->get('/me/protocols?source[]=x')->assertOk();
});

it('prunes patient name and birthdate after the retention period only, with dry-run and 0 = never', function () {
    $data = ['diagnose' => 'Klinisch'];
    $names = ['patient_first_name' => 'Max', 'patient_last_name' => 'Muster', 'patient_birthdate' => '1980-01-01', 'data' => $data];
    $old = sp_protocol($names + ['status' => ProtocolStatus::Released, 'released_at' => now()->subMonths(13), 'legacy' => ['patname' => 'Muster,Max', 'bearbeiter' => 'QM']]);
    $legacyOnly = sp_protocol(['status' => ProtocolStatus::Released, 'released_at' => now()->subMonths(13), 'legacy' => ['patgebdat' => '1980-02-30']]);
    $recent = sp_protocol($names + ['status' => ProtocolStatus::Released, 'released_at' => now()->subMonths(2)]);
    $open = sp_protocol($names);

    $this->artisan('enotf:prune', ['--dry-run' => true])->expectsOutputToContain('2 protocol(s) would be cleared')->assertSuccessful();
    expect($old->fresh()->patient_last_name)->toBe('Muster');

    app(Settings::class)->set('enotf.personal_data_months', 0);
    $this->artisan('enotf:prune')->expectsOutputToContain('nothing to do')->assertSuccessful();
    expect($old->fresh()->patient_last_name)->toBe('Muster');

    app(Settings::class)->set('enotf.personal_data_months', 12);
    $this->artisan('enotf:prune')->expectsOutputToContain('2 protocol(s) cleared')->assertSuccessful();
    $o = $old->fresh();
    expect($o->patient_first_name)->toBeNull()->and($o->patient_last_name)->toBeNull()->and($o->patient_birthdate)->toBeNull()
        ->and($o->data)->toBe($data)->and($o->legacy)->toBe(['bearbeiter' => 'QM'])->and($legacyOnly->fresh()->legacy)->toBeNull()
        ->and($recent->fresh()->patient_last_name)->toBe('Muster')->and($open->fresh()->patient_last_name)->toBe('Muster');
});
