<?php

use App\Models\DashboardCategory;
use App\Models\DashboardLink;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

function dp_user(string ...$perms): User
{
    $role = Role::create(['name' => 'D'.uniqid(), 'guard_name' => 'web', 'priority' => 500, 'color' => 'info']);
    $role->syncPermissions($perms);

    return User::factory()->withRole($role)->create();
}

it('is a page of its own for every signed-in user and no longer redirects to /', function () {
    $this->get('/dashboard')->assertRedirect('/login');
    $this->actingAs(dp_user())->get('/dashboard')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('Dashboard')->where('categories', [])->where('can.configure', false));
});

it('offers Configure with admin, dashboard.manage and super admin only', function () {
    foreach ([[dp_user('dashboard.manage'), true], [dp_user('admin'), true], [User::factory()->superAdmin()->create(), true], [dp_user('vehicles.view'), false]] as [$user, $can]) {
        $this->actingAs($user)->get('/dashboard')->assertInertia(fn (Assert $p) => $p->where('can.configure', $can));
    }
});

it('lists categories and links in order and loads the links in one query', function () {
    $b = DashboardCategory::factory()->create(['title' => 'B', 'sort_order' => 2]);
    $a = DashboardCategory::factory()->create(['title' => 'A', 'sort_order' => 1]);
    DashboardCategory::factory()->create(['title' => 'Empty', 'sort_order' => 3]);
    DashboardLink::factory()->for($a, 'category')->create(['title' => 'second', 'sort_order' => 2, 'url' => 'https://example.org', 'icon' => null]);
    DashboardLink::factory()->for($a, 'category')->create(['title' => 'first', 'sort_order' => 1]);
    DashboardLink::factory()->for($b, 'category')->create(['title' => 'other']);
    $user = dp_user();

    DB::enableQueryLog();
    $this->actingAs($user)->get('/dashboard')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->where('categories.0.title', 'A')
        ->where('categories.0.links.0.title', 'first')
        ->where('categories.0.links.1.url', 'https://example.org')
        ->where('categories.0.links.1.icon', null)
        ->where('categories.1.title', 'B')
        ->where('categories.2.links', [])
        ->missing('categories.0.links.0.created_at'));
    $queries = collect(DB::getQueryLog())->pluck('query')->filter(fn ($q) => str_contains($q, 'dashboard_'));
    DB::disableQueryLog();

    expect($queries)->toHaveCount(2);
});
