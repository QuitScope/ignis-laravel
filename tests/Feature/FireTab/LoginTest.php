<?php

use App\Models\Personnel;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleDefect;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Modules\FireTab\Support\CrewVehicle;

uses(RefreshDatabase::class);

function crew_user(): User
{
    return User::factory()->create(['personnel_id' => Personnel::factory()->create()->id]);
}

function fire_vehicle(array $over = []): Vehicle
{
    return Vehicle::factory()->create(['service' => Vehicle::SERVICE_FIRE, ...$over]);
}

it('signs in to a fire vehicle with the own record, renews the session id and keeps the CSRF token', function () {
    $user = crew_user();
    $vehicle = fire_vehicle();
    $this->actingAs($user)->get('/firetab/login')->assertOk();
    [$id, $token] = [session()->getId(), session()->token()];

    $this->withCookie(config('session.cookie'), $id)->post('/firetab/login', ['vehicle_id' => $vehicle->id])->assertRedirect('/firetab/list');

    expect(session('crew.firetab'))->toMatchArray(['user_id' => $user->id, 'vehicle_id' => $vehicle->id, 'personnel_id' => $user->personnel_id])
        ->and(session('crew.firetab.since'))->toBeInt()
        ->and(session()->getId())->not->toBe($id)
        ->and(session()->token())->toBe($token)
        ->and(app(CrewVehicle::class)->id())->toBe($vehicle->id);
    $this->get('/firetab/list')->assertOk();
    $this->get('/firetab')->assertRedirect('/firetab/list');
    $this->get('/einsatz/list.php')->assertOk();
});

it('takes the operator from the account, never from the request', function () {
    $user = crew_user();
    $other = Personnel::factory()->create();

    $this->actingAs($user)->post('/firetab/login', [
        'vehicle_id' => fire_vehicle()->id, 'personnel_id' => $other->id, 'operator_id' => $other->id, 'operator_name' => 'X',
    ])->assertRedirect('/firetab/list');

    expect(session('crew.firetab.personnel_id'))->toBe($user->personnel_id)
        ->and(session('crew.firetab'))->not->toHaveKey('operator_name');
});

it('rejects an account without a personnel record, a super-admin without one with 403', function () {
    $vehicle = fire_vehicle();

    $this->actingAs(User::factory()->create())->post('/firetab/login', ['vehicle_id' => $vehicle->id])
        ->assertSessionHasErrors(['vehicle_id' => __('crew.no_personnel')]);
    $this->get('/firetab/login')->assertInertia(fn (AssertableInertia $p) => $p->component('FireTab/Login')->where('hasPersonnel', false));
    expect(session()->has('crew.firetab'))->toBeFalse();

    $admin = User::factory()->superAdmin()->create();
    $this->actingAs($admin)->post('/firetab/login', ['vehicle_id' => $vehicle->id])->assertForbidden();
    $this->actingAs($admin)->get('/firetab/login')->assertForbidden();
    expect(session()->has('crew.firetab'))->toBeFalse();
});

it('rejects a non-fire, inactive or blocked vehicle and a list as vehicle id', function () {
    $user = crew_user();
    $blocked = fire_vehicle();
    VehicleDefect::factory()->blocking()->create(['vehicle_id' => $blocked->id]);

    foreach ([Vehicle::factory()->create(['service' => Vehicle::SERVICE_EMS]), fire_vehicle(['is_active' => false]), $blocked] as $v) {
        $this->actingAs($user)->post('/firetab/login', ['vehicle_id' => $v->id])
            ->assertSessionHasErrors(['vehicle_id' => __('crew.vehicle_unavailable')]);
    }
    $this->actingAs($user)->post('/firetab/login', ['vehicle_id' => [fire_vehicle()->id]])->assertSessionHasErrors('vehicle_id');
    expect(session()->has('crew.firetab'))->toBeFalse();
});

it('searches fire vehicles only, at most 20', function () {
    fire_vehicle(['identifier' => 'HLF-1', 'name' => 'Florian 1']);
    Vehicle::factory()->create(['identifier' => 'RTW-1', 'name' => 'Florian RTW']);
    foreach (range(1, 25) as $i) {
        fire_vehicle(['name' => "Florian X$i"]);
    }

    $this->actingAs(crew_user())->get('/firetab/login?q=HLF')
        ->assertInertia(fn (AssertableInertia $p) => $p->has('vehicles', 1)->where('vehicles.0.identifier', 'HLF-1'));
    $this->get('/firetab/login?q=Florian')->assertInertia(fn (AssertableInertia $p) => $p->has('vehicles', 20));
    $this->get('/firetab/login')->assertInertia(fn (AssertableInertia $p) => $p->has('vehicles', 0));
});

it('ends the crew session when the vehicle becomes inoperable or the record is unlinked', function () {
    $user = crew_user();
    $vehicle = fire_vehicle();
    $this->actingAs($user)->post('/firetab/login', ['vehicle_id' => $vehicle->id]);

    VehicleDefect::factory()->blocking()->create(['vehicle_id' => $vehicle->id]);
    $this->get('/firetab/list')->assertRedirect('/firetab/login');
    expect(session()->has('crew.firetab'))->toBeFalse();
    $this->get('/firetab')->assertRedirect('/firetab/login');

    $other = fire_vehicle();
    $this->post('/firetab/login', ['vehicle_id' => $other->id]);
    $this->get('/firetab/list')->assertOk();
    $user->forceFill(['personnel_id' => null])->save();
    $this->get('/firetab/list')->assertRedirect('/firetab/login');
});

it('logs out by POST only and keeps the account signed in', function () {
    $user = crew_user();
    $this->actingAs($user)->post('/firetab/login', ['vehicle_id' => fire_vehicle()->id]);

    $this->get('/firetab/logout')->assertMethodNotAllowed();
    expect(session()->has('crew.firetab'))->toBeTrue();

    $this->post('/firetab/logout')->assertRedirect('/firetab/login');
    expect(session()->has('crew.firetab'))->toBeFalse();
    $this->assertAuthenticatedAs($user);
    $this->get('/firetab/list')->assertRedirect('/firetab/login');
});

it('needs an account: guests go to the account login', function () {
    $this->get('/firetab/list')->assertRedirect('/login');
    $this->post('/firetab/login', ['vehicle_id' => fire_vehicle()->id])->assertRedirect('/login');
});
