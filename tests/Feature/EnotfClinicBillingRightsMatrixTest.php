<?php

use App\Auth\ApiKey;
use App\Models\Personnel;
use App\Models\Poi;
use App\Models\Role;
use App\Models\User;
use App\Models\Vehicle;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\Enotf\Models\ClinicCode;
use Modules\Enotf\Models\EnotfProtocol;
use Modules\Enotf\Models\HospitalAccessCode;
use Modules\Enotf\Models\HospitalDepartment;
use Modules\Enotf\Models\ProtocolReviewLog;

uses(RefreshDatabase::class);

/** @return array{0: EnotfProtocol, 1: Vehicle, 2: Poi, 3: string} protocol (released, unbilled), its vehicle, a hospital, a clinic code for the protocol */
function p9m_fixture(): array
{
    test()->seed(RoleSeeder::class);
    $vehicle = Vehicle::factory()->create(['service' => Vehicle::SERVICE_EMS, 'name' => 'RTW 1']);
    $p = (new EnotfProtocol)->forceFill(['enr' => '9001', 'status' => 'released', 'released_at' => now(), 'vehicle_transport_id' => $vehicle->id,
        'vehicle_transport_label' => 'RTW 1', 'patient_last_name' => 'Geheim']);
    $p->save();
    $hospital = Poi::factory()->create(['type' => 'Krankenhaus', 'is_active' => true]);
    (new HospitalAccessCode)->forceFill(['poi_id' => $hospital->id, 'code_hash' => Hash::make('ABCDEFGHJKLMNPQR'), 'rotated_at' => now()])->save();
    (new HospitalDepartment)->forceFill(['poi_id' => $hospital->id, 'name' => 'ZNA', 'created_at' => now()])->save();
    $code = (new ClinicCode)->forceFill(['enotf_protocol_id' => $p->id, 'code_hash' => Hash::make('SECRET2345'), 'expires_at' => now()->addHour()]);
    $code->save();

    return [$p, $vehicle, $hospital, $code->id.'-SECRET2345'];
}

function p9m_crew(Vehicle $vehicle): User
{
    $user = User::factory()->create(['personnel_id' => Personnel::factory()->create()->id]);
    test()->actingAs($user)->post('/enotf/login', ['pin' => '1234', 'vehicle_id' => $vehicle->id])->assertRedirect('/enotf/overview');

    return $user;
}

dataset('p9m_callers', [
    'guest' => ['guest', [302, 401, 401, 401, 302, 302, 302, 401, 302]],
    'account without crew session' => ['account', [403, 403, 403, 403, 302, 302, 302, 302, 302]],
    'crew of the vehicle' => ['crew', [403, 403, 403, 403, 302, 302, 302, 201, 200]],
    'crew of another vehicle' => ['crew_other', [403, 403, 403, 403, 302, 302, 302, 403, 403]],
    'QM (edivi.edit)' => ['qm', [200, 200, 201, 200, 302, 302, 302, 302, 302]],
    'super-admin' => ['super', [200, 403, 403, 403, 302, 302, 302, 302, 302]],
    'hospital device' => ['hospital', [302, 401, 401, 401, 200, 302, 200, 401, 302]],
    'clinic code' => ['clinic', [302, 401, 401, 401, 302, 200, 302, 401, 302]],
]);

it('answers every clinic, QM and billing route per caller with the expected status and changes only what the caller may', function (string $caller, array $expected) {
    [$p, $vehicle, $hospital, $clinicCode] = p9m_fixture();
    match ($caller) {
        'guest' => null,
        'account' => test()->actingAs(User::factory()->create(['personnel_id' => Personnel::factory()->create()->id])),
        'crew' => p9m_crew($vehicle),
        'crew_other' => p9m_crew(Vehicle::factory()->create(['service' => Vehicle::SERVICE_EMS])),
        'qm' => test()->actingAs(tap(Role::create(['name' => 'QM', 'guard_name' => 'web', 'priority' => 500, 'color' => 'info']), fn ($r) => $r->syncPermissions(['edivi.edit']))
            ->users()->save(User::factory()->create())),
        'super' => test()->actingAs(User::factory()->superAdmin()->create()),
        'hospital' => test()->post('/enotf/schnittstelle/login', ['poi_id' => $hospital->id, 'code' => 'ABCDEFGHJKLMNPQR'])->assertRedirect('/enotf/schnittstelle'),
        'clinic' => test()->post('/enotf/schnittstelle/klinikcode', ['code' => $clinicCode])->assertRedirect('/enotf/schnittstelle/protocol'),
    };

    $got = [
        $this->get("/enotf/qm/{$p->enr}")->status(),
        $this->patchJson("/enotf/qm/{$p->enr}/status", ['qm_status' => 2])->status(),
        $this->postJson("/enotf/qm/{$p->enr}/comments", ['text' => 'Bitte ergänzen'])->status(),
        $this->postJson("/enotf/qm/{$p->enr}/reopen")->status(),
        $this->get('/enotf/schnittstelle/availability')->status(),
        $this->get('/enotf/schnittstelle/protocol')->status(),
        $this->get('/enotf/schnittstelle')->status(),
        $this->postJson("/enotf/p/{$p->enr}/clinic-code")->status(),
        $this->get("/enotf/p/{$p->enr}/prereg")->status(),
    ];
    expect($got)->toBe($expected);

    $p->refresh();
    $qm = $caller === 'qm';
    expect(ProtocolReviewLog::query()->count())->toBe($qm ? 3 : 0)
        ->and($p->qm_status->value)->toBe($qm ? 2 : 0)
        ->and($p->status->value)->toBe($qm ? 'open' : 'released')
        ->and(ClinicCode::query()->whereNotNull('created_by_user_id')->count())->toBe($caller === 'crew' ? 1 : 0);
})->with('p9m_callers');

it('serves billing only with the API key and never with a session', function () {
    [$p] = p9m_fixture();
    $key = app(ApiKey::class)->current();
    $send = fn (array $body) => test()->call('POST', '/api/enotf/billing.php', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($body + ['timestamp' => time()]));

    $send(['intraRP_API_Key' => 'falsch'])->assertForbidden();
    $this->actingAs(User::factory()->superAdmin()->create());
    $send([])->assertForbidden();
    expect($p->fresh()->billed_at)->toBeNull();

    $send(['intraRP_API_Key' => $key])->assertOk()->assertJsonPath('count', 1);
    expect($p->fresh()->billed_at)->not->toBeNull();
});
