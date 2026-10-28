<?php

use App\Models\Personnel;
use App\Models\User;
use App\Models\Vehicle;
use App\Vehicles\CrewSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class)->group('mariadb');

beforeEach(function () {
    Route::middleware(['web', 'auth', 'crew:enotf'])->get('/_crew/enotf', fn () => 'enotf');
    Route::middleware(['web', 'auth', 'firetab.crew'])->get('/_crew/firetab', fn () => 'firetab');
    $this->actingAs($this->user = User::factory()->create(['personnel_id' => Personnel::factory()->create()->id]));
});

it('keeps the contexts apart: one context does not open the other, both can coexist', function () {
    $fire = Vehicle::factory()->create(['service' => Vehicle::SERVICE_FIRE]);
    $ems = Vehicle::factory()->create(['service' => Vehicle::SERVICE_EMS]);

    CrewSession::for('firetab')->login($this->user, $fire);
    $this->get('/_crew/firetab')->assertOk();
    $this->get('/_crew/enotf')->assertRedirect('/enotf/login');
    expect(session('crew.firetab.vehicle_id'))->toBe($fire->id);

    CrewSession::for('enotf')->login($this->user, $ems);
    $this->get('/_crew/enotf')->assertOk();
    $this->get('/_crew/firetab')->assertOk();
    expect(session('crew.enotf.vehicle_id'))->toBe($ems->id)->and(session('crew.firetab.vehicle_id'))->toBe($fire->id);

    CrewSession::for('firetab')->logout();
    $this->get('/_crew/firetab')->assertRedirect('/firetab/login');
    $this->get('/_crew/enotf')->assertOk();
});

it('lets each context sign in only to its own services', function (string $context, int $service, bool $ok) {
    $vehicle = Vehicle::factory()->create(['service' => $service]);
    $attempt = fn () => CrewSession::for($context)->login($this->user, $vehicle);

    $ok ? $attempt() : expect($attempt)->toThrow(ValidationException::class);
    expect(session()->has('crew.'.$context))->toBe($ok);
})->with([
    ['enotf', Vehicle::SERVICE_EMS_PHYSICIAN, true],
    ['enotf', Vehicle::SERVICE_EMS, true],
    ['enotf', Vehicle::SERVICE_FIRE, false],
    ['firetab', Vehicle::SERVICE_EMS, false],
    ['firetab', Vehicle::SERVICE_EMS_PHYSICIAN, false],
]);
