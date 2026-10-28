<?php

use App\Models\Personnel;
use App\Models\Poi;
use App\Models\Role;
use App\Models\User;
use App\Models\Vehicle;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Enotf\Models\EnotfProtocol;
use Modules\Enotf\Models\Medication;
use Modules\Enotf\Models\MedicationGiven;
use Modules\Enotf\Models\VitalSign;

uses(RefreshDatabase::class);

const P9B_ACTORS = ['guest', 'account', 'crew_own', 'crew_other', 'qm', 'super'];

/** @return array<string, list<string>> route => code per actor (P9B_ACTORS order) */
function p9bMatrix(): array
{
    $rows = ['401', '403', '200', '403', '200', '200'];
    $write = ['401', '403', '201', '403', '403', '403'];
    $remove = ['401', '403', '200', '403', '403', '403'];
    $settings = ['401', '403', '403', '403', '403'];

    return [
        'vitals.index' => $rows,
        'vitals.store' => $write,
        'vitals.destroy' => $remove,
        'medications.index' => $rows,
        'medications.store' => $write,
        'medications.destroy' => $remove,
        'address' => ['401', '403', '200', '403', '403', '403'],
        'pois.search' => ['401', '302>/enotf/login', '200', '200', '302>/enotf/login', '302>/enotf/login'],
        'settings.index' => ['302>/login', '403', '403', '403', '403', '200'],
        'settings.store' => [...$settings, '302>*'],
    ];
}

function p9bActor(string $actor, Vehicle $own, Vehicle $other): void
{
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
        test()->post('/enotf/login', ['pin' => '1234', 'vehicle_id' => ($actor === 'crew_own' ? $own : $other)->id])->assertRedirect('/enotf/overview');
    }
}

it('enforces the clinical data rights matrix with concrete codes and post-states', function () {
    $this->seed(RoleSeeder::class);
    [$own, $other] = Vehicle::factory()->count(2)->create(['service' => Vehicle::SERVICE_EMS]);
    Poi::factory()->create(['name' => 'Klinikum Nord', 'type' => 'Krankenhaus']);
    $medication = Medication::query()->value('id');
    $mismatches = [];

    foreach (p9bMatrix() as $route => $codes) {
        foreach (P9B_ACTORS as $i => $actor) {
            $p = (new EnotfProtocol)->forceFill(['enr' => (string) random_int(100000, 999999), 'vehicle_transport_id' => $own->id]);
            $p->save();
            $vital = (new VitalSign)->forceFill(['enotf_protocol_id' => $p->id, 'parameter' => 'spo2', 'value' => 97, 'measured_at' => now()]);
            $vital->save();
            $given = (new MedicationGiven)->forceFill(['enotf_protocol_id' => $p->id, 'name' => 'X', 'given_at' => now()]);
            $given->save();
            $catalog = Medication::query()->count();
            p9bActor($actor, $own, $other);
            $base = "/enotf/p/{$p->enr}";
            $res = match ($route) {
                'vitals.index' => $this->getJson("{$base}/vitals"),
                'vitals.store' => $this->postJson("{$base}/vitals", ['values' => ['herzfreq' => '80']]),
                'vitals.destroy' => $this->deleteJson("{$base}/vitals/{$vital->id}"),
                'medications.index' => $this->getJson("{$base}/medications"),
                'medications.store' => $this->postJson("{$base}/medications", ['medication_id' => $medication, 'dose' => '1', 'unit' => 'mg', 'route' => 'i.v.']),
                'medications.destroy' => $this->deleteJson("{$base}/medications/{$given->id}"),
                'address' => $this->putJson("{$base}/address/transport", ['revision' => $p->revision, 'address' => ['strasse' => 'Weg', 'hnr' => '1', 'ort' => 'Stadt', 'ortsteil' => null]]),
                'pois.search' => $this->getJson('/enotf/pois/search?q=Klinik'),
                'settings.index' => $actor === 'guest' ? $this->get('/settings/medications') : $this->getJson('/settings/medications'),
                'settings.store' => $this->postJson('/settings/medications', ['substance' => 'Matrixin', 'sort' => 1, 'is_active' => true]),
            };
            $code = (string) $res->getStatusCode();
            if ($res->getStatusCode() >= 300 && $res->getStatusCode() < 400) {
                $code .= '>'.parse_url((string) $res->headers->get('Location'), PHP_URL_PATH);
            }
            $expected = $codes[$i];
            if (str_ends_with($expected, '>*') ? ! str_starts_with($code, substr($expected, 0, -1)) : $code !== $expected) {
                $mismatches[] = "{$route} {$actor}: expected {$expected}, got {$code}";
            }

            $ok = str_starts_with($code, '2') || ($route === 'settings.store' && str_starts_with($code, '302'));
            $after = match ($route) {
                'vitals.store' => VitalSign::query()->where('enotf_protocol_id', $p->id)->count() === ($ok ? 2 : 1),
                'vitals.destroy' => ($vital->fresh()->deleted_at !== null) === $ok,
                'medications.store' => MedicationGiven::query()->where('enotf_protocol_id', $p->id)->count() === ($ok ? 2 : 1),
                'medications.destroy' => ($given->fresh()->deleted_at !== null) === $ok,
                'address' => ($p->fresh()->revision === $p->revision + 1) === $ok,
                'settings.store' => Medication::query()->count() === $catalog + ($ok ? 1 : 0),
                default => VitalSign::query()->whereNotNull('deleted_at')->doesntExist() && MedicationGiven::query()->count() === 1 && $p->fresh()->revision === $p->revision,
            };
            if (! $after) {
                $mismatches[] = "{$route} {$actor}: wrong post-state ({$code})";
            }
            EnotfProtocol::query()->delete();
            Medication::query()->where('substance', 'Matrixin')->delete();
        }
    }

    expect($mismatches)->toBe([]);
});
