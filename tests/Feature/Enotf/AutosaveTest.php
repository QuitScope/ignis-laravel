<?php

use App\Models\Role;
use App\Models\User;
use App\Models\Vehicle;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Modules\Enotf\Models\EnotfProtocol;
use Modules\Enotf\Support\CrewContext;

uses(RefreshDatabase::class);

function as_crew(?int $vehicle): void
{
    app()->instance(CrewContext::class, new class($vehicle) extends CrewContext
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
}

function as_protocol(array $over = []): EnotfProtocol
{
    $p = (new EnotfProtocol)->forceFill($over + ['enr' => (string) random_int(100000, 999999)]);
    $p->save();

    return $p->fresh();
}

function as_patch(EnotfProtocol $p, int $revision, array $fields)
{
    return test()->patchJson("/enotf/p/{$p->enr}", ['revision' => $revision, 'fields' => $fields]);
}

it('merges two devices field-wise: different fields both persist, the same field resolves to the later write', function () {
    $vehicle = Vehicle::factory()->create();
    $p = as_protocol(['vehicle_transport_id' => $vehicle->id]);
    as_crew($vehicle->id);
    $this->actingAs(User::factory()->create());

    as_patch($p, 1, ['pat_vorname' => 'Anna', 'awfrei_1' => '1'])
        ->assertOk()->assertExactJson(['revision' => 2, 'applied' => ['pat_vorname', 'awfrei_1'], 'conflicts' => [], 'changes_since' => []]);
    as_patch($p, 1, ['diagnose' => 'Sturz', 'psych' => [2, 3]])
        ->assertOk()->assertJson(['revision' => 3, 'applied' => ['diagnose', 'psych'], 'conflicts' => []])
        ->assertJsonPath('changes_since', ['pat_vorname' => 'Anna', 'awfrei_1' => 1]);

    as_patch($p, 1, ['pat_vorname' => 'Berta'])
        ->assertOk()->assertJsonPath('revision', 4)
        ->assertJsonPath('conflicts', [['key' => 'pat_vorname', 'value' => 'Anna', 'revision' => 2]])
        ->assertJsonPath('changes_since', ['awfrei_1' => 1, 'diagnose' => 'Sturz', 'psych' => [2, 3]]);
    as_patch($p, 2, ['temp' => '36,8'])
        ->assertOk()->assertJsonPath('revision', 5)->assertJsonPath('conflicts', [])
        ->assertJsonPath('changes_since', ['pat_vorname' => 'Berta', 'diagnose' => 'Sturz', 'psych' => [2, 3]]);
    as_patch($p, 3, ['pat_vorname' => 'Berta'])->assertOk()->assertJsonPath('conflicts', []);

    $p->refresh();
    expect($p->revision)->toBe(6)
        ->and($p->patient_first_name)->toBe('Berta')
        ->and($p->data)->toMatchArray(['awfrei_1' => 1, 'diagnose' => 'Sturz', 'psych' => [2, 3], 'temp' => 36.8])
        ->and($p->field_revisions)->toMatchArray(['pat_vorname' => 6, 'awfrei_1' => 2, 'temp' => 5]);

    as_patch($p, 6, ['diagnose' => '', 'salarm' => '2026-10-23T14:05'])->assertOk();
    $this->get("/enotf/p/{$p->enr}/diagnose")->assertInertia(fn (AssertableInertia $page) => $page->component('Enotf/Protocol')
        ->where('section', 'diagnose')->where('readonly', false)->where('protocol.revision', 7)
        ->where('values.diagnose', null)->where('values.salarm', '2026-10-23T14:05')->where('values.pat_vorname', 'Berta')
        ->has('schema')->where('pfname_suggestions', fn ($names) => collect($names)->contains(auth()->user()->name)));
    expect($p->fresh()->data)->not->toHaveKey('diagnose');
});

it('rejects unknown keys, off-catalog codes and oversized bodies without writing', function () {
    $vehicle = Vehicle::factory()->create();
    $p = as_protocol(['vehicle_transport_id' => $vehicle->id]);
    as_crew($vehicle->id);
    $this->actingAs(User::factory()->create());

    as_patch($p, 1, ['pat_vorname' => 'A', 'status' => 'released'])->assertStatus(422)->assertJsonValidationErrors('fields.status');
    as_patch($p, 1, ['awfrei_1' => 7])->assertStatus(422)->assertJsonValidationErrors('fields.awfrei_1');
    as_patch($p, 1, ['c_zugang' => [['art' => 'pvk', 'groesse' => '99G']]])->assertStatus(422);
    as_patch($p, 1, ['anmerkungen' => str_repeat('x', 10001)])->assertStatus(422);
    as_patch($p, 1, ['anmerkungen' => str_repeat('x', 200_001)])->assertStatus(413);

    expect($p->fresh()->revision)->toBe(1)->and($p->fresh()->patient_first_name)->toBeNull();
});

it('answers 409 once not open, 403 for another vehicle and QM viewers, 404 for unknown ENRs', function () {
    $this->seed(RoleSeeder::class);
    [$own, $other] = Vehicle::factory()->count(2)->create();
    $released = as_protocol(['vehicle_transport_id' => $own->id, 'status' => 'released']);
    $open = as_protocol(['vehicle_transport_id' => $own->id]);
    $crew = User::factory()->create();

    as_crew($own->id);
    $this->actingAs($crew);
    as_patch($released, 1, ['pat_vorname' => 'X'])->assertStatus(409)->assertJson(['status' => 'released', 'revision' => 1]);
    $this->get("/enotf/p/{$released->enr}")->assertInertia(fn (AssertableInertia $page) => $page->where('readonly', true)->where('section', 'rettdaten'));
    $this->get('/enotf/p/404404')->assertNotFound();
    $this->get("/enotf/p/{$open->enr}/unknown")->assertStatus(301)->assertRedirect("/enotf/p/{$open->enr}");

    $role = Role::create(['name' => 'QM'.uniqid(), 'guard_name' => 'web', 'priority' => 500, 'color' => 'info']);
    $role->syncPermissions(['edivi.edit']);
    $released->forceFill(['status' => 'withdrawn'])->save();
    $this->actingAs(User::factory()->withRole($role)->create());
    as_crew(null);
    as_patch($released, 1, ['pat_vorname' => 'X'])->assertStatus(409)->assertJsonPath('status', 'withdrawn');

    as_crew($other->id);
    $this->actingAs($crew);
    as_patch($open, 1, ['pat_vorname' => 'X'])->assertForbidden();
    $this->get("/enotf/p/{$open->enr}")->assertForbidden();

    $viewer = Role::create(['name' => 'V'.uniqid(), 'guard_name' => 'web', 'priority' => 500, 'color' => 'info']);
    $viewer->syncPermissions(['edivi.view']);
    as_crew(null);
    $this->actingAs(User::factory()->withRole($viewer)->create());
    as_patch($open, 1, ['pat_vorname' => 'X'])->assertForbidden();
    $this->get("/enotf/p/{$open->enr}")->assertInertia(fn (AssertableInertia $page) => $page->where('readonly', true));

    expect($open->fresh()->revision)->toBe(1)->and($released->fresh()->patient_first_name)->toBeNull();
});
