<?php

use App\Models\Personnel;
use App\Models\Role;
use App\Models\User;
use App\Models\Vehicle;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Modules\FireTab\Models\FireIncident;
use Modules\FireTab\Models\FireIncidentVehicle;
use Modules\FireTab\Models\FireSitrep;
use Modules\FireTab\Models\FireStatusQueue;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

const P8A_ORDER = [
    'list', 'show:O', 'show:F', 'show:Z',
    'update:O', 'update:F', 'update:Z', 'report:O', 'report:F', 'report:Z',
    'vehicle:O', 'vehicle:F', 'vehicle:Z', 'sitrep:O', 'sitrep:F', 'sitrep:Z',
    'status', 'logbook', 'qmlist', 'qmshow', 'qmstatus', 'finalize:O', 'finalize:F', 'finalize:Z', 'reopen',
];

const P8A_EXPECTED = [
    'guest' => ['*' => 'login'],
    'no-session' => ['*' => 'crew', 'qmlist' => 403, 'qmshow' => 403, 'qmstatus' => 403, 'reopen' => 403],
    'crew' => [
        'list' => 200, 'show:O' => 200, 'show:F' => 403, 'show:Z' => 200,
        'update:O' => 'ok', 'update:F' => 403, 'update:Z' => 403, 'report:O' => 'ok', 'report:F' => 403, 'report:Z' => 403,
        'vehicle:O' => 'ok', 'vehicle:F' => 403, 'vehicle:Z' => 403, 'sitrep:O' => 'ok', 'sitrep:F' => 403, 'sitrep:Z' => 403,
        'status' => 'ok', 'logbook' => 200, 'qmlist' => 403, 'qmshow' => 200, 'qmstatus' => 403,
        'finalize:O' => 'ok', 'finalize:F' => 403, 'finalize:Z' => 409, 'reopen' => 403,
    ],
    'qm' => ['*' => 'crew', 'qmlist' => 200, 'qmshow' => 200, 'qmstatus' => 'ok', 'reopen' => 'ok'],
    'admin' => ['*' => 'crew', 'qmlist' => 200, 'qmshow' => 200, 'qmstatus' => 'ok', 'reopen' => 'ok'],
    'super-admin' => [
        'list' => 200, 'show:O' => 200, 'show:F' => 200, 'show:Z' => 200,
        'update:O' => 'ok', 'update:F' => 403, 'update:Z' => 403, 'report:O' => 'ok', 'report:F' => 403, 'report:Z' => 403,
        'vehicle:O' => 'ok', 'vehicle:F' => 403, 'vehicle:Z' => 403, 'sitrep:O' => 'ok', 'sitrep:F' => 403, 'sitrep:Z' => 403,
        'status' => 'ok', 'logbook' => 200, 'qmlist' => 200, 'qmshow' => 200, 'qmstatus' => 'ok',
        'finalize:O' => 'ok', 'finalize:F' => 403, 'finalize:Z' => 409, 'reopen' => 'ok',
    ],
];

function p8a_incident(Vehicle $vehicle, array $over = []): FireIncident
{
    $i = (new FireIncident)->forceFill(['location' => 'Hauptstr. 1', 'keyword' => 'B2', 'started_at' => now(), 'leader_personnel_id' => Personnel::factory()->create()->id, ...$over]);
    $i->save();
    (new FireIncidentVehicle)->forceFill(['fire_incident_id' => $i->id, 'vehicle_id' => $vehicle->id, 'name' => $vehicle->name, 'radio_name' => $vehicle->name])->save();

    return $i->fresh();
}

function p8a_user(?string $permission = null, bool $super = false): User
{
    $attrs = ['personnel_id' => Personnel::factory()->create()->id];
    if ($super) {
        return User::factory()->superAdmin()->create($attrs);
    }
    $role = Role::create(['name' => 'X'.uniqid(), 'guard_name' => 'web', 'priority' => 500, 'color' => 'info']);
    $role->syncPermissions($permission ? [$permission] : []);

    return User::factory()->withRole($role)->create($attrs);
}

dataset('p8a_actors', array_keys(P8A_EXPECTED));

it('answers every FireTab route with the exact status and leaves the right state', function (string $actor) {
    Queue::fake();
    $mine = Vehicle::factory()->create(['service' => Vehicle::SERVICE_FIRE]);
    $O = p8a_incident($mine);
    $F = p8a_incident(Vehicle::factory()->create(['service' => Vehicle::SERVICE_FIRE]));
    $Z = p8a_incident($mine, ['finalized_at' => now()]);
    $inc = ['O' => $O, 'F' => $F, 'Z' => $Z];

    $user = match ($actor) {
        'guest' => null,
        'no-session', 'crew' => p8a_user(),
        'qm' => p8a_user('fire.incident.qm'),
        'admin' => p8a_user('admin'),
        'super-admin' => p8a_user(super: true),
    };
    $session = in_array($actor, ['crew', 'super-admin'], true)
        ? ['crew.firetab' => ['user_id' => $user->id, 'vehicle_id' => $mine->id, 'personnel_id' => $user->personnel_id, 'since' => time()]]
        : [];

    $form = ['location' => 'Neuer Ort', 'keyword' => 'B3', 'started_date' => '2026-07-01', 'started_time' => '14:30', 'leader_personnel_id' => Personnel::factory()->create()->id];
    $request = function (string $key) use ($inc, $form): array {
        [$name, $v] = array_pad(explode(':', $key), 2, null);
        $base = $v ? '/firetab/incidents/'.$inc[$v]->id : null;
        $link = $v ? $inc[$v]->vehicles()->value('id') : null;
        $z = $inc['Z']->id;

        return match ($name) {
            'list' => ['GET', '/firetab/list', []],
            'show' => ['GET', $base, []],
            'update' => ['PUT', $base, $form],
            'report' => ['PUT', "$base/report", ['notes' => 'Bericht']],
            'vehicle' => ['POST', "$base/vehicles", ['radio_name' => 'Florian Nachbar']],
            'sitrep' => ['POST', "$base/sitreps", ['date' => '2026-07-01', 'time' => '10:15', 'text' => 'Lage', 'incident_vehicle_id' => $link]],
            'finalize' => ['POST', "$base/finalize", []],
            'status' => ['POST', '/firetab/status', ['status' => '2']],
            'logbook' => ['GET', '/firetab/logbook', []],
            'qmlist' => ['GET', '/firetab/qm', []],
            'qmshow' => ['GET', "/firetab/qm/incidents/$z", []],
            'qmstatus' => ['PATCH', "/firetab/qm/$z/status", ['status' => 'approved']],
            'reopen' => ['POST', "/firetab/qm/$z/reopen", []],
        };
    };

    $bad = [];
    foreach (P8A_ORDER as $key) {
        $expected = P8A_EXPECTED[$actor][$key] ?? P8A_EXPECTED[$actor]['*'];
        [$method, $url, $data] = $request($key);
        app('auth')->forgetGuards();
        $http = $user === null ? test() : test()->actingAs($user);
        $response = $http->withSession($session)->call($method, $url, $data);
        $got = match (true) {
            $response->isRedirect(url('/login')) => 'login',
            $response->isRedirect(url('/firetab/login')) => 'crew',
            $response->getStatusCode() === 302 && ! $response->getSession()->get('errors')?->any() => 'ok',
            default => $response->getStatusCode(),
        };
        if ($got !== $expected) {
            $bad[] = "{$key}: {$method} {$url} expected {$expected}, got {$got}";
        }
    }
    expect($bad)->toBe([]);

    $writes = in_array($actor, ['crew', 'super-admin'], true);
    $qmWork = in_array($actor, ['qm', 'admin', 'super-admin'], true);
    expect($O->fresh())->location->toBe($writes ? 'Neuer Ort' : 'Hauptstr. 1')->notes->toBe($writes ? 'Bericht' : null);
    expect($O->fresh()->finalized_at !== null)->toBe($writes);
    expect($F->fresh())->location->toBe('Hauptstr. 1')->notes->toBeNull()->finalized_at->toBeNull()
        ->and($F->vehicles()->count())->toBe(1)->and(FireSitrep::where('fire_incident_id', $F->id)->count())->toBe(0)
        ->and($O->vehicles()->count())->toBe($writes ? 2 : 1)
        ->and(FireSitrep::where('fire_incident_id', $O->id)->count())->toBe($writes ? 1 : 0)
        ->and(FireStatusQueue::count())->toBe($writes ? 1 : 0)
        ->and($Z->vehicles()->count())->toBe(1)->and(FireSitrep::where('fire_incident_id', $Z->id)->count())->toBe(0);
    expect($Z->fresh()->finalized_at === null)->toBe($qmWork);
})->with('p8a_actors');

it('lets a crew member finalize only once: a repeat answers 409', function () {
    Queue::fake();
    $mine = Vehicle::factory()->create(['service' => Vehicle::SERVICE_FIRE]);
    $O = p8a_incident($mine);
    $user = p8a_user();
    $this->actingAs($user)->withSession(['crew.firetab' => ['user_id' => $user->id, 'vehicle_id' => $mine->id, 'personnel_id' => $user->personnel_id, 'since' => time()]]);

    $this->post("/firetab/incidents/{$O->id}/finalize")->assertRedirect();
    $this->post("/firetab/incidents/{$O->id}/finalize")->assertStatus(409);
    expect($O->fresh()->finalized_at)->not->toBeNull();
});
