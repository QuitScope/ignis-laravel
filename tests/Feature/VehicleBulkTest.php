<?php

use App\Models\Role;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleDefect;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

function vb_manager(): User
{
    $role = Role::create(['name' => 'B'.uniqid(), 'guard_name' => 'web', 'priority' => 500, 'color' => 'info']);
    $role->syncPermissions(['vehicles.manage']);

    return User::factory()->withRole($role)->create();
}

function vb_audit(): array
{
    return Activity::where('log_name', 'Fahrzeuge')->orderBy('id')->get()
        ->map(fn (Activity $a) => [$a->description, $a->getAttribute('details'), $a->properties['context'], (bool) $a->getAttribute('is_global')])->all();
}

it('needs vehicles.manage (or admin)', function () {
    $v = Vehicle::factory()->create();
    $role = Role::create(['name' => 'X', 'guard_name' => 'web', 'priority' => 500, 'color' => 'info']);
    $role->syncPermissions(['vehicles.view']);

    $this->post('/vehicles/bulk', ['action' => 'deactivate', 'ids' => [$v->id]])->assertRedirect('/login');
    $this->actingAs(User::factory()->withRole($role)->create())
        ->post('/vehicles/bulk', ['action' => 'deactivate', 'ids' => [$v->id]])->assertForbidden();
    expect($v->fresh()->is_active)->toBeTrue();
});

it('activates and deactivates with one legacy audit entry per vehicle', function () {
    $a = Vehicle::factory()->create();
    $b = Vehicle::factory()->inactive()->create();
    $untouched = Vehicle::factory()->create();
    $u = vb_manager();

    $this->actingAs($u)->from('/vehicles?search=x')->post('/vehicles/bulk', ['action' => 'deactivate', 'ids' => [$a->id, (string) $b->id]])
        ->assertRedirect('/vehicles?search=x');
    expect([$a->fresh()->is_active, $b->fresh()->is_active, $untouched->fresh()->is_active])->toBe([false, false, true]);

    $this->actingAs($u)->post('/vehicles/bulk', ['action' => 'activate', 'ids' => [$a->id]]);
    expect($a->fresh()->is_active)->toBeTrue();

    expect(vb_audit())->toBe([
        ['Fahrzeug aktualisiert [ID: '.$a->id.']', 'Status: inaktiv (Sammelaktion)', ['id' => $a->id], true],
        ['Fahrzeug aktualisiert [ID: '.$b->id.']', 'Status: inaktiv (Sammelaktion)', ['id' => $b->id], true],
        ['Fahrzeug aktualisiert [ID: '.$a->id.']', 'Status: aktiv (Sammelaktion)', ['id' => $a->id], true],
    ]);
});

it('keeps a blocked vehicle active and a deactivated one inactive', function () {
    $blocked = Vehicle::factory()->create();
    $defect = VehicleDefect::factory()->for($blocked)->blocking()->create();

    $this->actingAs(vb_manager())->post('/vehicles/bulk', ['action' => 'activate', 'ids' => [$blocked->id]]);

    expect($blocked->fresh()->is_active)->toBeTrue()->and($blocked->fresh()->is_operable)->toBeFalse();
    expect($defect->fresh()->status)->toBe(VehicleDefect::OPEN);
});

it('sets the FMS status by hand with time and source', function () {
    Carbon::setTestNow('2026-10-19 12:00:00');
    $v = Vehicle::factory()->create(['fms_status' => '2', 'fms_status_source' => 'no_dispatch']);

    $this->actingAs(vb_manager())->post('/vehicles/bulk', ['action' => 'fms_status', 'ids' => [$v->id], 'fms_status' => '3']);

    $v->refresh();
    expect([$v->fms_status, $v->fms_status_source, $v->fms_status_at?->toDateTimeString()])->toBe(['3', 'manual', '2026-10-19 12:00:00']);
    expect(vb_audit())->toBe([['Fahrzeug aktualisiert [ID: '.$v->id.']', 'EMD-Status: 3 (Einsatz übernommen) (Sammelaktion)', ['id' => $v->id], true]]);
});

it('rejects an FMS status outside 0-6 or a missing one', function (mixed $status) {
    $v = Vehicle::factory()->create(['fms_status' => '2']);

    $this->actingAs(vb_manager())->post('/vehicles/bulk', ['action' => 'fms_status', 'ids' => [$v->id]] + ($status === null ? [] : ['fms_status' => $status]))
        ->assertSessionHasErrors('fms_status');
    expect($v->fresh()->fms_status)->toBe('2');
})->with([null, '7', '-1', 'x', '1.5', [['3']]]);

it('deletes with one audit entry per vehicle', function () {
    $a = Vehicle::factory()->create();
    $b = Vehicle::factory()->create();
    VehicleDefect::factory()->for($a)->create();
    $keep = Vehicle::factory()->create();

    $this->actingAs(vb_manager())->post('/vehicles/bulk', ['action' => 'delete', 'ids' => [$a->id, $b->id]]);

    expect(Vehicle::pluck('id')->all())->toBe([$keep->id])->and(VehicleDefect::count())->toBe(0);
    expect(array_column(vb_audit(), 0))->toBe(['Fahrzeug gelöscht [ID: '.$a->id.']', 'Fahrzeug gelöscht [ID: '.$b->id.']']);
});

it('ignores malformed and unknown ids and answers 422 when none is left', function () {
    $v = Vehicle::factory()->create();
    $u = vb_manager();

    $this->actingAs($u)->post('/vehicles/bulk', ['action' => 'deactivate', 'ids' => ['x', '-3', '0', '1.5', ['1'], '99999999999999999999', 999, $v->id, $v->id]])
        ->assertSessionHasNoErrors();
    expect($v->fresh()->is_active)->toBeFalse()->and(vb_audit())->toHaveCount(1);

    foreach ([['x', 999], [], 'abc'] as $ids) {
        $this->actingAs($u)->post('/vehicles/bulk', ['action' => 'activate', 'ids' => $ids])->assertSessionHasErrors('ids');
    }
    $this->actingAs($u)->postJson('/vehicles/bulk', ['action' => 'activate', 'ids' => [999]])->assertStatus(422);
    expect($v->fresh()->is_active)->toBeFalse();
});

it('rejects more than 200 ids and unknown actions', function () {
    $v = Vehicle::factory()->create();
    $u = vb_manager();

    $this->actingAs($u)->post('/vehicles/bulk', ['action' => 'deactivate', 'ids' => array_merge([$v->id], range(1000, 1200))])
        ->assertSessionHasErrors('ids');
    foreach (['explode', ['delete'], null] as $action) {
        $this->actingAs($u)->post('/vehicles/bulk', ['action' => $action, 'ids' => [$v->id]])->assertSessionHasErrors('action');
    }
    expect($v->fresh()->is_active)->toBeTrue();
});
