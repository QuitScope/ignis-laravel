<?php

use App\Dashboard\SetupChecklist;
use App\Dashboard\Widgets\ChecklistWidget;
use App\Models\EmsQualification;
use App\Models\Personnel;
use App\Models\Rank;
use App\Models\Role;
use App\Models\User;
use App\Models\Vehicle;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

function sc_admin(): User
{
    $role = Role::create(['name' => 'A'.uniqid(), 'guard_name' => 'web', 'priority' => 500, 'color' => 'info']);
    $role->syncPermissions(['admin']);

    return User::factory()->withRole($role)->create();
}

function sc_done(array $skip = []): void
{
    $rank = Rank::factory()->create(['is_archive' => false]);
    $ems = EmsQualification::create(['priority' => 2, 'name' => 'RS', 'name_male' => 'RS', 'name_female' => 'RS', 'is_none' => false]);
    Personnel::factory()->create(['rank_id' => $rank->id, 'ems_qualification_id' => $ems->id]);
}

it('is allowed for admins and super admins only', function () {
    $w = new ChecklistWidget;
    $role = Role::create(['name' => 'V', 'guard_name' => 'web', 'priority' => 400, 'color' => 'info']);
    $role->syncPermissions(['vehicles.view']);

    expect($w->allowed(sc_admin()))->toBeTrue()
        ->and($w->allowed(User::factory()->superAdmin()->create()))->toBeTrue()
        ->and($w->allowed(User::factory()->withRole($role)->create()))->toBeFalse()
        ->and($w->allowed(User::factory()->create()))->toBeFalse();
});

it('lists the steps of a fresh install as open and flags the first required one as next', function () {
    $data = (new ChecklistWidget)->data(sc_admin());

    expect(array_column($data['steps'], 'key'))->toBe(['ranks', 'ems', 'personnel', 'vehicle'])
        ->and(array_column($data['steps'], 'done'))->toBe([false, false, false, false])
        ->and(array_column($data['steps'], 'required'))->toBe([true, true, true, false])
        ->and(array_column($data['steps'], 'next'))->toBe([true, false, false, false])
        ->and($data['steps'][0])->toMatchArray(['label' => 'Dienstgrade anlegen', 'href' => '/settings/personnel/ranks']);
});

it('moves next on to the first open required step', function () {
    Rank::factory()->create(['is_archive' => false]);
    $data = (new ChecklistWidget)->data(sc_admin());

    expect(array_column($data['steps'], 'done'))->toBe([true, false, false, false])
        ->and(array_column($data['steps'], 'next'))->toBe([false, true, false, false]);
});

it('does not count the archive rank or the none qualification', function () {
    Rank::factory()->create(['is_archive' => true]);
    EmsQualification::create(['priority' => 0, 'name' => 'Keine', 'name_male' => 'Keine', 'name_female' => 'Keine', 'is_none' => true]);

    expect(array_column((new SetupChecklist)->steps(), 'done'))->toBe([false, false, false, false]);
});

it('disappears once every required step is done; the optional vehicle step does not hold it back', function () {
    sc_done();
    expect((new ChecklistWidget)->data(sc_admin()))->toBeNull()
        ->and(array_column((new SetupChecklist)->steps(), 'done'))->toBe([true, true, true, false]);

    Vehicle::factory()->create();
    expect(array_column((new SetupChecklist)->steps(), 'done')[3])->toBeTrue();
});
