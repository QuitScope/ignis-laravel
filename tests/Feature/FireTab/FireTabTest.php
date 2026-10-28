<?php

use App\Auth\Permissions;
use App\Models\Role;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Settings;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\FireTab\Enums\FireIncidentStatus;
use Modules\FireTab\Models\FireIncident;
use Modules\FireTab\Models\FireIncidentVehicle;
use Modules\FireTab\Support\CrewVehicle;

uses(RefreshDatabase::class);

function ft_incident(array $over = []): FireIncident
{
    $i = (new FireIncident)->forceFill($over);
    $i->save();

    return $i->fresh();
}

function ft_qm(): User
{
    $role = Role::create(['name' => 'Q'.uniqid(), 'guard_name' => 'web', 'priority' => 500, 'color' => 'info']);
    $role->syncPermissions(['fire.incident.qm']);

    return User::factory()->withRole($role)->create();
}

it('enforces the unique number and the unique vehicle per incident, and cascades', function () {
    $vehicleId = Vehicle::factory()->create()->id;
    $a = ft_incident(['number' => 'E-1']);
    expect($a->status)->toBe(FireIncidentStatus::New);
    expect(fn () => ft_incident(['number' => 'E-1']))->toThrow(QueryException::class);
    ft_incident();
    ft_incident();

    $link = fn () => (new FireIncidentVehicle)->forceFill(['fire_incident_id' => $a->id, 'vehicle_id' => $vehicleId])->save();
    $link();
    expect($link)->toThrow(QueryException::class);

    DB::table('vehicles')->where('id', $vehicleId)->delete();
    expect(FireIncidentVehicle::first()->vehicle_id)->toBeNull();
    $a->delete();
    expect(FireIncidentVehicle::count())->toBe(0);
});

it('answers 404 with the module off, registers the QM right only while on, and keeps the role right', function () {
    $this->seed(RoleSeeder::class);
    $qm = ft_qm();
    expect(Permissions::groups())->toHaveKey('fire')
        ->and(Permissions::moduleOf('fire.incident.qm'))->toBe('firetab')
        ->and(app(Settings::class)->get('firetab.asu_start_bar'))->toBe(300);
    $this->actingAs($qm)->get('/firetab/qm')->assertOk()->assertHeader('Cache-Control', 'no-store, private');

    app(Settings::class)->set('modules.firetab.enabled', false);
    $this->actingAs($qm)->get('/firetab/qm')->assertNotFound();
    expect(Permissions::groups())->not->toHaveKey('fire')
        ->and($qm->fresh()->hasPermission('fire.incident.qm'))->toBeTrue();
});

it('lets QM and admin see any protocol, the crew vehicle its own, nobody else', function () {
    $this->seed(RoleSeeder::class);
    $vehicleId = Vehicle::factory()->create()->id;
    $incident = ft_incident();
    $other = User::factory()->create();
    $qm = ft_qm();

    expect($other->can('view', $incident))->toBeFalse()->and($other->can('create', FireIncident::class))->toBeFalse()
        ->and($qm->can('view', $incident))->toBeTrue()->and($qm->can('archive', $incident))->toBeTrue()
        ->and($qm->can('update', $incident))->toBeFalse();

    app()->instance(CrewVehicle::class, new class extends CrewVehicle
    {
        public function id(): ?int
        {
            return (int) DB::table('vehicles')->value('id');
        }
    });
    expect($other->can('view', $incident))->toBeFalse();
    (new FireIncidentVehicle)->forceFill(['fire_incident_id' => $incident->id, 'vehicle_id' => $vehicleId])->save();
    expect($other->can('view', $incident))->toBeTrue()->and($other->can('update', $incident))->toBeTrue()
        ->and($other->can('archive', $incident))->toBeFalse();
    $incident->forceFill(['finalized_at' => now()])->save();
    expect($other->can('update', $incident))->toBeFalse();
});
