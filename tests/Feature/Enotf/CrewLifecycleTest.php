<?php

use App\Models\Personnel;
use App\Models\User;
use App\Models\Vehicle;
use App\Vehicles\DefectReporter;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Enotf\Models\EnotfCrew;
use Modules\Enotf\Models\EnotfProtocol;

uses(RefreshDatabase::class)->group('mariadb');

function clUser(): User
{
    return User::factory()->create(['personnel_id' => Personnel::factory()->create()->id]);
}

function clVehicle(): Vehicle
{
    return Vehicle::factory()->create(['service' => Vehicle::SERVICE_EMS]);
}

function clOpen(User $user, Vehicle $vehicle): EnotfCrew
{
    test()->actingAs($user)->post('/enotf/login', ['vehicle_id' => $vehicle->id, 'pin' => '1234'])->assertRedirect('/enotf/overview');

    return EnotfCrew::query()->whereNull('ended_at')->where('vehicle_id', $vehicle->id)->sole();
}

function clActive(int $personnelId): int
{
    return DB::table('enotf_crew_members')->where('personnel_id', $personnelId)->whereNotNull('active_key')->count();
}

it('joins a free seat and refuses an occupied one', function () {
    $vehicle = clVehicle();
    $crew = clOpen(clUser(), $vehicle);
    $b = clUser();

    $this->actingAs($b)->postJson('/enotf/crew/join', ['vehicle_id' => $vehicle->id, 'position' => 1, 'pin' => '1234'])
        ->assertStatus(422)->assertJsonValidationErrors('position');
    $this->actingAs($b)->post('/enotf/crew/join', ['vehicle_id' => $vehicle->id, 'position' => 2, 'pin' => '1234'])->assertRedirect('/enotf/overview');
    expect($crew->members()->whereNotNull('active_key')->pluck('position')->sort()->values()->all())->toBe([1, 2]);
    $this->getJson('/enotf/status')->assertOk()->assertJsonPath('crew.members.1.self', true);
});

it('keeps one person in one active crew when a join races another one', function () {
    [$v1, $v2] = [clVehicle(), clVehicle()];
    clOpen(clUser(), $v1);
    $other = clOpen(clUser(), $v2);
    $b = clUser();

    $raced = false;
    DB::listen(function (QueryExecuted $q) use (&$raced, $b, $other) {
        if (! $raced && str_contains(str_replace('`', '"', $q->sql), '"position" = ?') && str_contains($q->sql, 'enotf_crew_members')) {
            $raced = true;
            DB::table('enotf_crew_members')->insert(['enotf_crew_id' => $other->id, 'position' => 3, 'personnel_id' => $b->personnel_id,
                'active_key' => $b->personnel_id, 'joined_at' => now()]);
        }
    });

    $this->actingAs($b)->postJson('/enotf/crew/join', ['vehicle_id' => $v1->id, 'position' => 2, 'pin' => '1234'])
        ->assertStatus(422)->assertJsonValidationErrors('members');
    expect($raced)->toBeTrue()->and(clActive($b->personnel_id))->toBe(0);
});

it('leaves, ends for every device and drops stale sessions', function () {
    $vehicle = clVehicle();
    $a = clUser();
    $crew = clOpen($a, $vehicle);
    $p = (new EnotfProtocol)->forceFill(['enr' => '123456', 'vehicle_transport_id' => $vehicle->id]);
    $p->save();
    [$b, $c] = [clUser(), clUser()];
    $this->actingAs($b)->post('/enotf/crew/join', ['vehicle_id' => $vehicle->id, 'position' => 2, 'pin' => '1234']);
    $this->post('/enotf/crew/leave')->assertRedirect('/enotf/loggedout');
    expect(clActive($b->personnel_id))->toBe(0)->and($crew->fresh()->ended_at)->toBeNull();

    $this->actingAs($c)->post('/enotf/crew/join', ['vehicle_id' => $vehicle->id, 'position' => 3, 'pin' => '1234']);
    $this->post('/enotf/crew/end')->assertRedirect('/enotf/loggedout');
    expect($crew->fresh()->ended_reason)->toBe('ended')->and(clActive($a->personnel_id))->toBe(0);

    session()->put('crew.enotf', ['user_id' => $a->id, 'vehicle_id' => $vehicle->id, 'personnel_id' => $a->personnel_id, 'since' => time()]);
    $this->actingAs($a)->getJson('/enotf/status')->assertOk()->assertJsonPath('crew', null);
    $this->getJson("/enotf/print/{$p->enr}")->assertForbidden();
});

it('ends the crews of a vehicle that goes out of service', function () {
    $vehicle = clVehicle();
    $crew = clOpen(clUser(), $vehicle);

    app(DefectReporter::class)->report($vehicle, ['title' => 'Motor', 'is_operable' => false], null);

    expect($crew->fresh()->ended_reason)->toBe('vehicle_out_of_service')
        ->and(DB::table('enotf_crew_members')->whereNotNull('active_key')->count())->toBe(0);
});
