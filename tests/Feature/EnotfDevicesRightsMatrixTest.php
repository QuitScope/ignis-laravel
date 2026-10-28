<?php

use App\Models\Personnel;
use App\Models\Role;
use App\Models\User;
use App\Models\Vehicle;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Enotf\Models\EnotfCrew;
use Modules\Enotf\Models\EnotfProtocol;
use Modules\Enotf\Models\ShareRequest;

uses(RefreshDatabase::class);

const P9C_ACTORS = ['guest', 'account', 'crew_own', 'crew_locked', 'crew_other', 'qm', 'super'];

/** @return array<string, list<string>> route => code per actor (P9C_ACTORS order) */
function p9cMatrix(): array
{
    $login = '302>/enotf/login';

    return [
        'status' => ['401', '200', '200', '200', '200', '200', '200'],
        'changes' => ['401', '403', '200', '423', '403', '200', '200'],
        'patient-sync' => ['401', '403', '200', '423', '403', '403', '403'],
        'share.vehicles' => ['401', $login, '200', '423', '200', $login, $login],
        'share.store' => ['401', $login, '201', '423', '403', $login, $login],
        'crew.end' => ['401', '403', '302>/enotf/loggedout', '423', '302>/enotf/loggedout', '403', '403'],
    ];
}

function p9cActor(string $actor, Vehicle $own, Vehicle $other): void
{
    test()->actingAs(User::factory()->create(['personnel_id' => Personnel::factory()->create()->id]))
        ->post('/enotf/login', ['pin' => '1234', 'vehicle_id' => $other->id])->assertRedirect('/enotf/overview');
    test()->flushSession();
    app('auth')->forgetGuards();
    $user = match ($actor) {
        'guest' => null,
        'qm' => User::factory()->withRole(tap(Role::create(['name' => 'R'.uniqid(), 'guard_name' => 'web', 'priority' => 500, 'color' => 'info']))->syncPermissions(['edivi.view']))->create(),
        'super' => User::factory()->superAdmin()->create(),
        default => User::factory()->create(['personnel_id' => Personnel::factory()->create()->id]),
    };
    if ($user === null) {
        return;
    }
    test()->actingAs($user);
    if (str_starts_with($actor, 'crew_')) {
        test()->post('/enotf/login', ['pin' => '1234', 'vehicle_id' => ($actor === 'crew_other' ? $other : $own)->id])->assertRedirect('/enotf/overview');
        if ($actor === 'crew_locked') {
            EnotfCrew::query()->where('vehicle_id', $own->id)->update(['locked_at' => now()]);
        }
    }
}

it('enforces the devices and sessions rights matrix with concrete codes and post-states', function () {
    $this->seed(RoleSeeder::class);
    $mismatches = [];

    foreach (p9cMatrix() as $route => $codes) {
        foreach (P9C_ACTORS as $i => $actor) {
            [$own, $other] = Vehicle::factory()->count(2)->create(['service' => Vehicle::SERVICE_EMS]);
            $p = (new EnotfProtocol)->forceFill(['enr' => (string) random_int(100000, 999999), 'vehicle_transport_id' => $own->id, 'patient_last_name' => 'Muster', 'data' => ['diagnose' => 'Sturz']]);
            $p->save();
            p9cActor($actor, $own, $other);
            $base = "/enotf/p/{$p->enr}";
            $res = match ($route) {
                'status' => $this->getJson('/enotf/status'),
                'changes' => $this->getJson("{$base}/changes?since=0"),
                'patient-sync' => $this->postJson("{$base}/patient-sync"),
                'share.vehicles' => $this->getJson('/enotf/share/vehicles'),
                'share.store' => $this->postJson("{$base}/share", ['to_vehicle_id' => $other->id]),
                'crew.end' => $this->postJson('/enotf/crew/end'),
            };
            $code = (string) $res->getStatusCode();
            if ($res->getStatusCode() >= 300 && $res->getStatusCode() < 400) {
                $code .= '>'.parse_url((string) $res->headers->get('Location'), PHP_URL_PATH);
            }
            if ($code !== $codes[$i]) {
                $mismatches[] = "{$route} {$actor}: expected {$codes[$i]}, got {$code}";
            }

            $ok = str_starts_with($code, '2') || $code === '302>/enotf/loggedout';
            $after = match ($route) {
                'patient-sync' => ($p->fresh()->patient_sync->value === 'pending') === $ok,
                'share.store' => ShareRequest::query()->count() === ($ok ? 1 : 0),
                'crew.end' => EnotfCrew::query()->whereNull('ended_at')->whereIn('vehicle_id', [$own->id, $other->id])->count()
                    === (['crew_own' => 1, 'crew_locked' => 2, 'crew_other' => 0][$actor] ?? 1),
                default => $p->fresh()->revision === $p->revision && ShareRequest::query()->doesntExist() && $p->fresh()->patient_sync->value === 'none',
            };
            if (! $after) {
                $mismatches[] = "{$route} {$actor}: wrong post-state ({$code})";
            }
            ShareRequest::query()->delete();
        }
    }

    expect($mismatches)->toBe([]);
});
