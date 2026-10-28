<?php

use App\Jobs\SendDiscordWebhook;
use App\Models\Personnel;
use App\Models\Role;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Settings;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Modules\FireTab\Enums\FireIncidentStatus;
use Modules\FireTab\Models\FireIncident;
use Modules\FireTab\Models\FireIncidentVehicle;

uses(RefreshDatabase::class);

/** @return array{0: User, 1: Vehicle, 2: User} crew user, its vehicle, leader account */
function qm_crew(object $t): array
{
    $leaderPerson = Personnel::factory()->create();
    $leader = User::factory()->create(['personnel_id' => $leaderPerson->id]);
    $user = User::factory()->create(['personnel_id' => Personnel::factory()->create()->id]);
    $vehicle = Vehicle::factory()->create(['service' => Vehicle::SERVICE_FIRE]);
    $t->actingAs($user)->withSession(['crew.firetab' => ['user_id' => $user->id, 'vehicle_id' => $vehicle->id, 'personnel_id' => $user->personnel_id, 'since' => time()]]);

    return [$user, $vehicle, $leader];
}

function qm_incident(?Vehicle $vehicle = null, array $over = []): FireIncident
{
    $i = (new FireIncident)->forceFill(['location' => 'Hauptstr. 1', 'keyword' => 'B2', 'started_at' => now(), 'leader_personnel_id' => Personnel::factory()->create()->id, ...$over]);
    $i->save();
    if ($vehicle !== null) {
        (new FireIncidentVehicle)->forceFill(['fire_incident_id' => $i->id, 'vehicle_id' => $vehicle->id, 'name' => $vehicle->name])->save();
    }

    return $i->fresh();
}

function fireTabQmUser(): User
{
    $role = Role::create(['name' => 'Q'.uniqid(), 'guard_name' => 'web', 'priority' => 500, 'color' => 'info']);
    $role->syncPermissions(['fire.incident.qm']);

    return User::factory()->withRole($role)->create();
}

it('finalizes once: requires assigned crew, open and mandatory fields; repeat is a 409 without second bell or webhook', function () {
    $this->seed(RoleSeeder::class);
    Queue::fake();
    [, $vehicle, $leader] = qm_crew($this);
    $incident = qm_incident($vehicle, ['leader_personnel_id' => $leader->personnel_id]);
    $url = "/firetab/incidents/{$incident->id}/finalize";

    $this->post('/firetab/incidents/'.qm_incident(Vehicle::factory()->create(['service' => Vehicle::SERVICE_FIRE]))->id.'/finalize')->assertForbidden();
    $empty = qm_incident($vehicle, ['keyword' => null]);
    $this->post("/firetab/incidents/{$empty->id}/finalize")->assertSessionHasErrors('keyword');
    expect($empty->fresh()->finalized_at)->toBeNull();
    Queue::assertNothingPushed();

    $this->post($url)->assertRedirect();
    $this->post($url)->assertStatus(409);

    expect($incident->fresh()->finalized_at)->not->toBeNull()
        ->and($leader->notifications()->count())->toBe(1)
        ->and($leader->notifications()->first()->data['link'])->toBe("/firetab/incidents/{$incident->id}");
    Queue::assertPushed(SendDiscordWebhook::class, 1);

    $this->put("/firetab/incidents/{$incident->id}/report", ['notes' => 'x'])->assertForbidden();
});

it('limits status, archive and reopen to QM; QM reads without a crew session; leader gets the bell with the editor name', function () {
    $this->seed(RoleSeeder::class);
    $leader = User::factory()->create(['personnel_id' => Personnel::factory()->create()->id]);
    $incident = qm_incident(null, ['leader_personnel_id' => $leader->personnel_id, 'finalized_at' => now()]);
    $base = "/firetab/qm/{$incident->id}";

    $plain = User::factory()->create();
    $this->actingAs($plain);
    $this->get('/firetab/qm')->assertForbidden();
    $this->get("/firetab/qm/incidents/{$incident->id}")->assertForbidden();
    $this->patch("$base/status", ['status' => 'approved'])->assertForbidden();
    $this->post("$base/archive")->assertForbidden();
    $this->post("$base/reopen")->assertForbidden();
    $this->post('/firetab/qm/bulk-archive', ['ids' => [$incident->id]])->assertForbidden();

    $qm = fireTabQmUser();
    $this->actingAs($qm);
    $this->get('/firetab/qm')->assertInertia(fn (AssertableInertia $p) => $p->component('FireTab/Qm/Index')->where('counts.active', 1)->has('incidents.data', 1));
    $this->get("/firetab/qm/incidents/{$incident->id}")->assertInertia(fn (AssertableInertia $p) => $p->component('FireTab/Qm/Show')->where('can.review', true));
    $this->actingAs(User::factory()->superAdmin()->create())->get("/firetab/qm/incidents/{$incident->id}")->assertOk();
    $this->actingAs($qm);

    $this->patch("$base/status", ['status' => 'approved'])->assertRedirect();
    expect($incident->fresh()->status)->toBe(FireIncidentStatus::Approved)
        ->and($leader->notifications()->count())->toBe(1)
        ->and($leader->notifications()->first()->data['title'])->toContain($qm->displayName());
    $this->patch("$base/status", ['status' => 'bogus'])->assertSessionHasErrors('status');

    $this->post("$base/archive")->assertRedirect();
    expect($incident->fresh())->archived_at->not->toBeNull()->status->toBe(FireIncidentStatus::Hidden);
    $at = $incident->fresh()->getRawOriginal('archived_at');
    $this->travel(5)->minutes();
    $this->post("$base/archive")->assertRedirect();
    expect($incident->fresh()->getRawOriginal('archived_at'))->toBe($at)->and($incident->log()->where('action', 'archived')->count())->toBe(1);
    $this->delete("$base/archive")->assertRedirect();
    $this->delete("$base/archive")->assertRedirect();
    expect($incident->fresh()->archived_at)->toBeNull()->and($incident->log()->where('action', 'unarchived')->count())->toBe(1);

    $this->post("$base/reopen")->assertRedirect();
    expect($incident->fresh())->finalized_at->toBeNull()->status->toBe(FireIncidentStatus::New);
    $this->post("$base/reopen")->assertStatus(409);
    $this->post('/firetab/qm/bulk-archive', ['ids' => [$incident->id]])->assertRedirect();
    expect($incident->fresh()->archived_at)->not->toBeNull();
});

it('archives empty protocols only with a valid preview token bound to the same filter', function () {
    $this->seed(RoleSeeder::class);
    $this->actingAs($qm = fireTabQmUser());
    $empty = qm_incident(null, ['location' => null]);
    $full = qm_incident();
    $filter = ['fields' => ['location'], 'days' => '30', 'state' => 'all'];

    $this->post('/firetab/qm/empty', $filter + ['token' => str_repeat('a', 40)])->assertSessionHasErrors('token');
    expect($empty->fresh()->archived_at)->toBeNull();

    $token = null;
    $this->post('/firetab/qm/empty', $filter)->assertInertia(function (AssertableInertia $p) use (&$token) {
        $p->component('FireTab/Qm/EmptyPreview')->where('preview.count', 1);
        $token = $p->toArray()['props']['preview']['token'];
    });
    $this->post('/firetab/qm/empty', ['fields' => ['keyword']] + $filter + ['token' => $token])->assertSessionHasErrors('token');
    $this->post('/firetab/qm/empty', $filter + ['token' => $token])->assertSessionHasErrors('token');

    $this->post('/firetab/qm/empty', $filter)->assertInertia(function (AssertableInertia $p) use (&$token) {
        $token = $p->toArray()['props']['preview']['token'];
    });
    $this->post('/firetab/qm/empty', $filter + ['token' => $token])->assertRedirect('/firetab/qm');
    expect($empty->fresh()->archived_at)->not->toBeNull()->and($full->fresh()->archived_at)->toBeNull();
});

it('prunes caller and owner data after the retention period (dry run changes nothing, 0 = never)', function () {
    $pii = ['caller_name' => 'A', 'caller_contact' => '1', 'owner_name' => 'B', 'owner_contact' => '2'];
    $old = qm_incident(null, $pii + ['finalized_at' => now()->subMonths(13)]);
    $recent = qm_incident(null, $pii + ['finalized_at' => now()->subMonths(11)]);
    $open = qm_incident(null, $pii);

    Artisan::call('firetab:prune-personal-data', ['--dry-run' => true]);
    expect($old->fresh()->caller_name)->toBe('A');

    Artisan::call('firetab:prune-personal-data');
    expect($old->fresh())->caller_name->toBeNull()->owner_contact->toBeNull()->and($recent->fresh()->caller_name)->toBe('A')->and($open->fresh()->owner_name)->toBe('B');

    app(Settings::class)->set('firetab.personal_data_months', 0);
    $recent->forceFill(['finalized_at' => now()->subYears(5)])->save();
    Artisan::call('firetab:prune-personal-data');
    expect($recent->fresh()->caller_name)->toBe('A');
});

it('links the leader bell to the QM read route only for QM recipients, and locks status and reopen on archived protocols', function () {
    $this->seed(RoleSeeder::class);
    $qmLeader = fireTabQmUser();
    $qmLeader->forceFill(['personnel_id' => Personnel::factory()->create()->id])->save();
    $plainLeader = User::factory()->create(['personnel_id' => Personnel::factory()->create()->id]);
    $a = qm_incident(null, ['leader_personnel_id' => $qmLeader->personnel_id]);
    $b = qm_incident(null, ['leader_personnel_id' => $plainLeader->personnel_id, 'finalized_at' => now()]);

    $this->actingAs(fireTabQmUser());
    $this->patch("/firetab/qm/{$a->id}/status", ['status' => 'approved'])->assertRedirect();
    $this->patch("/firetab/qm/{$b->id}/status", ['status' => 'approved'])->assertRedirect();
    expect($qmLeader->notifications()->first()->data['link'])->toBe("/firetab/qm/incidents/{$a->id}")
        ->and($plainLeader->notifications()->first()->data['link'])->toBe("/firetab/incidents/{$b->id}");

    $this->post("/firetab/qm/{$b->id}/archive")->assertRedirect();
    $this->patch("/firetab/qm/{$b->id}/status", ['status' => 'approved'])->assertSessionHasErrors('status');
    $this->post("/firetab/qm/{$b->id}/reopen")->assertSessionHasErrors('reopen');
    expect($b->fresh())->finalized_at->not->toBeNull()->status->toBe(FireIncidentStatus::Hidden);
});
