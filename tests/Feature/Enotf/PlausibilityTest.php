<?php

use App\Models\Role;
use App\Models\User;
use App\Models\Vehicle;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Enotf\Models\EnotfProtocol;
use Modules\Enotf\Support\CrewContext;

uses(RefreshDatabase::class);

it('lists open rules grouped by section for the crew and QM, nobody else', function () {
    $this->seed(RoleSeeder::class);
    [$own, $other] = Vehicle::factory()->count(2)->create();
    $p = (new EnotfProtocol)->forceFill([
        'enr' => '4711', 'vehicle_transport_id' => $own->id, 'destination_code' => '4', 'mission_date' => '2026-01-01',
        'data' => ['prot_by' => 2, 'pfname' => 'Muster'],
    ]);
    $p->save();
    $crewOn = fn (?int $vehicle) => app()->instance(CrewContext::class, new class($vehicle) extends CrewContext
    {
        public function __construct(private ?int $v) {}

        public function vehicleId(): ?int
        {
            return $this->v;
        }

        public function personnelId(): ?int
        {
            return null;
        }
    });

    $crewOn($own->id);
    $this->actingAs(User::factory()->create())->getJson('/enotf/p/4711/plausibility')
        ->assertOk()
        ->assertJsonPath('count', 7)
        ->assertJsonPath('sections.anamnese.0', [
            'field' => 'elokation', 'rule' => 'elokation', 'message_key' => 'enotf.plausibility.elokation',
            'section' => 'anamnese', 'message' => 'Einsatzort ist nicht gesetzt.',
        ])
        ->assertJsonCount(5, 'sections.rettdaten')
        ->assertJsonCount(1, 'sections.abschluss');

    $crewOn($other->id);
    $this->actingAs(User::factory()->create())->getJson('/enotf/p/4711/plausibility')->assertForbidden();

    $crewOn(null);
    $role = Role::create(['name' => 'QM', 'guard_name' => 'web', 'priority' => 500, 'color' => 'info']);
    $role->syncPermissions(['edivi.view']);
    $this->actingAs(User::factory()->withRole($role)->create())->getJson('/enotf/p/4711/plausibility')->assertOk()->assertJsonPath('count', 7);
});
