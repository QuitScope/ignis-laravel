<?php

use App\Models\Personnel;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Modules\Enotf\Enums\ProtocolOrigin;
use Modules\Enotf\Enums\ProtocolStatus;
use Modules\Enotf\Models\EnotfCrew;
use Modules\Enotf\Models\EnotfProtocol;

uses(RefreshDatabase::class)->group('mariadb');

function ec_user(): User
{
    return User::factory()->create(['personnel_id' => Personnel::factory()->create()->id]);
}

function ec_vehicle(int $service = Vehicle::SERVICE_EMS): Vehicle
{
    return Vehicle::factory()->create(['service' => $service]);
}

function ec_protocol(array $over): EnotfProtocol
{
    $p = (new EnotfProtocol)->forceFill($over + ['enr' => (string) random_int(100000, 999999)]);
    $p->save();

    return $p->fresh();
}

function ec_login(User $user, Vehicle $vehicle, array $extra = []): void
{
    test()->actingAs($user)->post('/enotf/login', ['pin' => '1234', 'vehicle_id' => $vehicle->id, ...$extra])->assertRedirect('/enotf/overview');
}

it('signs in with the own record as driver, picked records only, and a new session id', function () {
    $user = ec_user();
    $codriver = Personnel::factory()->create();
    $vehicle = ec_vehicle(Vehicle::SERVICE_EMS_PHYSICIAN);
    $this->actingAs($user)->get('/enotf/login')->assertOk();
    $id = session()->getId();

    $this->withCookie(config('session.cookie'), $id)->post('/enotf/login', [
        'pin' => '1234', 'vehicle_id' => $vehicle->id, 'codriver_id' => $codriver->id, 'fahrername' => 'Freitext', 'personnel_id' => $codriver->id,
    ])->assertRedirect('/enotf/overview');

    expect(session()->getId())->not->toBe($id)
        ->and(session('crew.enotf'))->toMatchArray(['vehicle_id' => $vehicle->id, 'personnel_id' => $user->personnel_id]);
    $crew = EnotfCrew::query()->where('vehicle_id', $vehicle->id)->whereNull('ended_at')->sole();
    expect($crew->members()->orderBy('position')->pluck('personnel_id', 'position')->all())->toBe([1 => $user->personnel_id, 2 => $codriver->id]);
    $this->get('/enotf')->assertRedirect('/enotf/overview');

    $this->post('/enotf/login', ['pin' => '1234', 'vehicle_id' => $vehicle->id, 'codriver_id' => $user->personnel_id])->assertSessionHasErrors('members');
});

it('rejects accounts without a record, a super-admin without one and non-rescue vehicles', function () {
    $ems = ec_vehicle();

    $this->actingAs(User::factory()->create())->post('/enotf/login', ['pin' => '1234', 'vehicle_id' => $ems->id])
        ->assertSessionHasErrors(['vehicle_id' => __('crew.no_personnel')]);
    $admin = User::factory()->superAdmin()->create();
    $this->actingAs($admin)->post('/enotf/login', ['pin' => '1234', 'vehicle_id' => $ems->id])->assertForbidden();
    $this->actingAs($admin)->get('/enotf/login')->assertForbidden();
    $this->actingAs(ec_user())->post('/enotf/login', ['pin' => '1234', 'vehicle_id' => ec_vehicle(Vehicle::SERVICE_FIRE)->id])
        ->assertSessionHasErrors(['vehicle_id' => __('crew.vehicle_unavailable')]);

    expect(session()->has('crew.enotf'))->toBeFalse()->and(EnotfCrew::query()->count())->toBe(0);
});

it('lists only the open, visible protocols of the own vehicle', function () {
    $user = ec_user();
    $vehicle = ec_vehicle();
    $other = ec_vehicle();
    ec_protocol(['enr' => '1', 'vehicle_transport_id' => $vehicle->id]);
    ec_protocol(['enr' => '2', 'vehicle_na_id' => $vehicle->id]);
    ec_protocol(['enr' => '3', 'vehicle_transport_id' => $other->id]);
    ec_protocol(['enr' => '4', 'vehicle_transport_id' => $vehicle->id, 'status' => ProtocolStatus::Released]);
    ec_protocol(['enr' => '5', 'vehicle_transport_id' => $vehicle->id, 'hidden_at' => now()]);
    ec_login($user, $vehicle);

    $this->get('/enotf/overview')->assertInertia(fn (AssertableInertia $p) => $p->component('Enotf/Overview')
        ->where('protocols.0.enr', '1')->where('protocols.1.enr', '2')->count('protocols', 2));
});

it('creates, reopens, refuses foreign ENRs with 409 and attaches to an open protocol of the same dispatch', function () {
    $user = ec_user();
    $rtw = ec_vehicle();
    ec_login($user, $rtw);

    $this->postJson('/enotf/protocols', ['enr' => '2026_1', 'prot_by' => 0])->assertCreated()->assertJson(['url' => '/enotf/p/2026_1']);
    $p = EnotfProtocol::query()->where('enr', '2026_1')->sole();
    expect($p->origin)->toBe(ProtocolOrigin::Manual)->and($p->vehicle_transport_id)->toBe($rtw->id)->and($p->revision)->toBe(1)
        ->and($p->crew()->pluck('personnel_id')->all())->toBe([$user->personnel_id]);
    $this->postJson('/enotf/protocols', ['enr' => '2026_1', 'prot_by' => 0])->assertOk();

    $other = ec_user();
    ec_login($other, ec_vehicle());
    $this->postJson('/enotf/protocols', ['enr' => '2026_1', 'prot_by' => 0])->assertStatus(409)->assertJson(['forceable' => true]);
    $this->postJson('/enotf/protocols', ['enr' => '2026_1', 'prot_by' => 0, 'force' => true])->assertCreated()->assertJson(['enr' => '2026_1_1']);

    $nef = ec_vehicle(Vehicle::SERVICE_EMS_PHYSICIAN);
    $dispatch = ec_protocol(['enr' => '777', 'origin' => ProtocolOrigin::Dispatch, 'vehicle_transport_id' => $rtw->id]);
    $foreign = ec_protocol(['enr' => '779', 'origin' => ProtocolOrigin::Dispatch, 'vehicle_transport_id' => $rtw->id]);
    $closed = ec_protocol(['enr' => '888', 'vehicle_transport_id' => $rtw->id, 'status' => ProtocolStatus::Released]);
    ec_protocol(['enr' => '777_1', 'origin' => ProtocolOrigin::Dispatch, 'vehicle_na_id' => $nef->id]);
    $doc = ec_user();
    ec_login($doc, $nef);
    $this->postJson('/enotf/protocols', ['enr' => '2026_1', 'prot_by' => 1])->assertStatus(409);
    $this->postJson('/enotf/protocols', ['enr' => '779', 'prot_by' => 1])->assertStatus(409);
    expect($foreign->fresh()->vehicle_na_id)->toBeNull();
    $this->postJson('/enotf/protocols', ['enr' => '777', 'prot_by' => 1])->assertOk();
    expect($dispatch->fresh()->vehicle_na_id)->toBe($nef->id)
        ->and($dispatch->crew()->where('slot', 'na')->pluck('personnel_id')->all())->toBe([$doc->personnel_id]);
    $this->postJson('/enotf/protocols', ['enr' => '888', 'prot_by' => 1])->assertStatus(409);
    expect($closed->fresh()->vehicle_na_id)->toBeNull();

    $this->postJson('/enotf/protocols', ['enr' => 'abc', 'prot_by' => 0])->assertJsonValidationErrors('enr');
    $this->postJson('/enotf/protocols', ['enr' => str_repeat('1', 65), 'prot_by' => 0])->assertJsonValidationErrors('enr');
});

it('turns a lost ENR race into a validation error', function () {
    ec_login(ec_user(), ec_vehicle());
    $raced = false;
    DB::listen(function (QueryExecuted $q) use (&$raced) {
        if (! $raced && str_contains(str_replace('`', '"', $q->sql), 'from "enotf_protocols" where "enr" = ?')) {
            $raced = true;
            DB::table('enotf_protocols')->insert(['enr' => '4242', 'status' => 'open', 'origin' => 'manual', 'qm_status' => 0, 'revision' => 1, 'patient_sync' => 'none']);
        }
    });

    $this->postJson('/enotf/protocols', ['enr' => '4242', 'prot_by' => 0])->assertUnprocessable()->assertJsonValidationErrors('enr');
});

it('withdraws all own open protocols except dispatch ones, and signs out by POST only', function () {
    $user = ec_user();
    $vehicle = ec_vehicle();
    $manual = ec_protocol(['vehicle_transport_id' => $vehicle->id]);
    $dispatch = ec_protocol(['vehicle_transport_id' => $vehicle->id, 'origin' => ProtocolOrigin::Dispatch]);
    $foreign = ec_protocol(['vehicle_transport_id' => ec_vehicle()->id]);
    ec_login($user, $vehicle);

    $this->post('/enotf/protocols/withdraw-all')->assertRedirect('/enotf/overview');
    expect($manual->fresh()->status)->toBe(ProtocolStatus::Withdrawn)->and($manual->fresh()->withdrawn_at)->not->toBeNull()
        ->and($dispatch->fresh()->status)->toBe(ProtocolStatus::Open)
        ->and($foreign->fresh()->status)->toBe(ProtocolStatus::Open);

    $this->get('/enotf/logout')->assertStatus(405);
    $this->post('/enotf/logout')->assertRedirect('/enotf/loggedout');
    expect(session()->has('crew.enotf'))->toBeFalse()->and(EnotfCrew::query()->where('vehicle_id', $vehicle->id)->whereNull('ended_at')->first())->toBeNull();
    $this->get('/enotf/overview')->assertRedirect('/enotf/login');
});
