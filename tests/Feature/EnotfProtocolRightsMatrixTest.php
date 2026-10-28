<?php

use App\Models\Personnel;
use App\Models\Role;
use App\Models\User;
use App\Models\Vehicle;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Modules\Enotf\Enums\ProtocolStatus;
use Modules\Enotf\Models\EnotfProtocol;
use Modules\Enotf\Support\FieldSchema;
use Modules\Enotf\Support\ProtocolMerger;

uses(RefreshDatabase::class);

const P9A_ACTORS = ['guest', 'account', 'crew_own', 'crew_other', 'qm', 'admin', 'super'];

/**
 * route => [open, released] per actor (P9A_ACTORS order). `302>/login` guest, `302>/enotf/login` no crew session.
 *
 * @return array<string, array<string, array{0: string, 1: string}>>
 */
function p9aMatrix(): array
{
    $noCrew = ['302>/enotf/login', '302>/enotf/login'];
    $page = ['302>/login', '302>/login'];
    $guestJson = ['401', '401'];
    $deny = ['403', '403'];

    return [
        'overview' => ['guest' => $page, 'account' => $noCrew, 'crew_own' => ['200', '200'], 'crew_other' => ['200', '200'], 'qm' => $noCrew, 'admin' => $noCrew, 'super' => $noCrew],
        'create' => ['guest' => $page, 'account' => $noCrew, 'crew_own' => ['200', '200'], 'crew_other' => ['200', '200'], 'qm' => $noCrew, 'admin' => $noCrew, 'super' => $noCrew],
        'store' => ['guest' => $guestJson, 'account' => $noCrew, 'crew_own' => ['201', '201'], 'crew_other' => ['201', '201'], 'qm' => $noCrew, 'admin' => $noCrew, 'super' => $noCrew],
        'show' => ['guest' => $page, 'account' => $deny, 'crew_own' => ['200', '200'], 'crew_other' => $deny, 'qm' => ['200', '200'], 'admin' => ['200', '200'], 'super' => ['200', '200']],
        'patch' => ['guest' => $guestJson, 'account' => $deny, 'crew_own' => ['200', '409'], 'crew_other' => $deny, 'qm' => ['403', '409'], 'admin' => ['200', '409'], 'super' => ['403', '409']],
        'plausibility' => ['guest' => $guestJson, 'account' => $deny, 'crew_own' => ['200', '200'], 'crew_other' => $deny, 'qm' => ['200', '200'], 'admin' => ['200', '200'], 'super' => ['200', '200']],
        'release' => ['guest' => $guestJson, 'account' => $deny, 'crew_own' => ['200', '409'], 'crew_other' => $deny, 'qm' => ['403', '409'], 'admin' => ['200', '409'], 'super' => ['403', '409']],
        'withdraw' => ['guest' => $page, 'account' => $noCrew, 'crew_own' => ['302>/enotf/overview', '403'], 'crew_other' => $deny, 'qm' => $noCrew, 'admin' => $noCrew, 'super' => $noCrew],
        'print' => ['guest' => $page, 'account' => $deny, 'crew_own' => ['200', '200'], 'crew_other' => $deny, 'qm' => ['200', '200'], 'admin' => ['200', '200'], 'super' => ['200', '200']],
        'admin' => ['guest' => $page, 'account' => $deny, 'crew_own' => $deny, 'crew_other' => $deny, 'qm' => ['200', '200'], 'admin' => ['200', '200'], 'super' => ['200', '200']],
        'hide' => ['guest' => $page, 'account' => $deny, 'crew_own' => $deny, 'crew_other' => $deny, 'qm' => $deny, 'admin' => ['302>*', '302>*'], 'super' => $deny],
    ];
}

function p9aProtocol(Vehicle $vehicle, string $state): EnotfProtocol
{
    $p = (new EnotfProtocol)->forceFill(['enr' => (string) random_int(100000, 999999), 'vehicle_transport_id' => $vehicle->id, 'vehicle_transport_label' => $vehicle->identifier]);
    $p->save();
    $full = json_decode((string) file_get_contents(base_path('tests/Fixtures/enotf-conditions.json')), true)['full'];
    unset($full['medis']);
    app(ProtocolMerger::class)->apply($p, 1, FieldSchema::validate(['awsicherung_neu' => 1, 'prot_by' => 0] + $full)['values']);
    if ($state === 'released') {
        $p->forceFill(['status' => ProtocolStatus::Released, 'released_at' => now()])->save();
    }

    return $p->fresh();
}

function p9aActor(string $actor, Vehicle $own, Vehicle $other): void
{
    test()->flushSession();
    app('auth')->forgetGuards();
    $role = function (string $perm) {
        $r = Role::create(['name' => 'R'.uniqid(), 'guard_name' => 'web', 'priority' => 500, 'color' => 'info']);
        $r->syncPermissions([$perm]);

        return User::factory()->withRole($r)->create();
    };
    $user = match ($actor) {
        'guest' => null,
        'qm' => $role('edivi.view'),
        'admin' => $role('admin'),
        'super' => User::factory()->superAdmin()->create(),
        default => User::factory()->create(['personnel_id' => Personnel::factory()->create()->id]),
    };
    if ($user === null) {
        return;
    }
    test()->actingAs($user);
    if (str_starts_with($actor, 'crew_')) {
        test()->post('/enotf/login', ['pin' => '1234', 'vehicle_id' => ($actor === 'crew_own' ? $own : $other)->id])->assertRedirect('/enotf/overview');
    }
}

it('enforces the protocol core rights matrix with concrete codes and post-states', function () {
    Queue::fake();
    $this->seed(RoleSeeder::class);
    [$own, $other] = Vehicle::factory()->count(2)->create(['service' => Vehicle::SERVICE_EMS]);
    $mismatches = [];

    foreach (p9aMatrix() as $route => $byActor) {
        foreach (P9A_ACTORS as $actor) {
            foreach (['open', 'released'] as $i => $state) {
                $p = p9aProtocol($own, $state);
                p9aActor($actor, $own, $other);
                $enr = $p->enr;
                $res = match ($route) {
                    'overview' => $this->get('/enotf/overview'),
                    'create' => $this->get('/enotf/create'),
                    'store' => $this->postJson('/enotf/protocols', ['enr' => (string) random_int(1000000, 9999999), 'prot_by' => 0]),
                    'show' => $this->get("/enotf/p/{$enr}"),
                    'patch' => $this->patchJson("/enotf/p/{$enr}", ['revision' => $p->revision, 'fields' => ['diagnose' => 'Matrix']]),
                    'plausibility' => $this->getJson("/enotf/p/{$enr}/plausibility"),
                    'release' => $this->postJson("/enotf/p/{$enr}/release"),
                    'withdraw' => $this->delete("/enotf/p/{$enr}"),
                    'print' => $this->get("/enotf/print/{$enr}"),
                    'admin' => $this->get('/enotf/admin'),
                    'hide' => $this->post('/enotf/admin/hide', ['ids' => [$p->id]]),
                };
                $code = (string) $res->getStatusCode();
                if ($res->getStatusCode() >= 300 && $res->getStatusCode() < 400) {
                    $code .= '>'.parse_url((string) $res->headers->get('Location'), PHP_URL_PATH);
                }
                $expected = $byActor[$actor][$i];
                $matches = str_ends_with($expected, '>*') ? str_starts_with($code, substr($expected, 0, -1)) : $code === $expected;
                if (! $matches) {
                    $mismatches[] = "{$route} {$actor} {$state}: expected {$expected}, got {$code}";
                    EnotfProtocol::query()->delete();

                    continue;
                }

                $fresh = $p->fresh();
                $ok = str_starts_with($code, '2') || $code === '302>/enotf/overview' || ($route === 'hide' && $expected === '302>*');
                $after = match ($route) {
                    'patch' => $ok ? ($fresh->data['diagnose'] ?? null) === 'Matrix' && $fresh->revision === $p->revision + 1 : $fresh->revision === $p->revision,
                    'release' => $fresh->status === ($ok || $state === 'released' ? ProtocolStatus::Released : ProtocolStatus::Open),
                    'withdraw' => $fresh->status === ($ok ? ProtocolStatus::Withdrawn : $p->status),
                    'hide' => ($fresh->hidden_at !== null) === $ok,
                    'store' => EnotfProtocol::query()->count() === ($ok ? 2 : 1),
                    default => $fresh->revision === $p->revision && $fresh->status === $p->status,
                };
                if (! $after) {
                    $mismatches[] = "{$route} {$actor} {$state}: wrong post-state";
                }
                EnotfProtocol::query()->delete();
            }
        }
    }

    expect($mismatches)->toBe([]);
});
