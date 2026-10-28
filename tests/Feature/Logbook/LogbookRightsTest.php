<?php

use App\Auth\Permissions;
use App\Models\Role;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Settings;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Logbook\Models\LogbookEntry;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

function lb_user(string ...$perms): User
{
    $role = Role::create(['name' => 'L'.uniqid(), 'guard_name' => 'web', 'priority' => 500, 'color' => 'info']);
    $role->syncPermissions($perms);

    return User::factory()->withRole($role)->create();
}

/** @return array<string, mixed> */
function lb_body(Vehicle $v, array $over = []): array
{
    return $over + ['vehicle_id' => $v->id, 'trip_date' => '2026-10-20', 'departed_at' => '08:30', 'arrived_at' => '09:15',
        'distance_km' => '12.5', 'reason' => 'Übung', 'trip_type' => 'uebung', 'driver_name' => 'Max Muster'];
}

function lb_entry(Vehicle $v, array $over = []): LogbookEntry
{
    return (new LogbookEntry)->forceFill($over + ['vehicle_id' => $v->id, 'vehicle_identifier' => $v->identifier, 'trip_date' => '2026-10-20',
        'departed_at' => '08:30', 'arrived_at' => '09:00', 'distance_km' => 10, 'trip_type' => 'dienst', 'driver_name' => 'A', 'source' => 'admin']);
}

it('sends guests to the login for every route', function () {
    $e = lb_entry(Vehicle::factory()->create());
    $e->save();
    $this->get('/logbook')->assertRedirect('/login');
    $this->post('/logbook')->assertRedirect('/login');
    $this->put("/logbook/{$e->id}")->assertRedirect('/login');
    $this->delete("/logbook/{$e->id}")->assertRedirect('/login');
});

it('lets view read, manage read and write (manage without view sees the page), and keeps others out', function () {
    $v = Vehicle::factory()->create();
    $e = lb_entry($v);
    $e->save();
    $none = lb_user('audit.view');
    $viewer = lb_user('logbook.view');
    $manager = lb_user('logbook.manage');

    $this->actingAs($none)->get('/logbook')->assertForbidden();
    $this->actingAs($viewer)->get('/logbook')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Logbook/Index')->where('can.manage', false));
    $this->actingAs($manager)->get('/logbook')->assertOk()->assertInertia(fn (Assert $p) => $p->where('can.manage', true));

    foreach ([$none, $viewer] as $user) {
        $this->actingAs($user)->post('/logbook', lb_body($v))->assertForbidden();
        $this->actingAs($user)->put("/logbook/{$e->id}", lb_body($v))->assertForbidden();
        $this->actingAs($user)->delete("/logbook/{$e->id}")->assertForbidden();
    }
    $this->actingAs($manager)->post('/logbook', lb_body($v))->assertRedirect();
    $this->actingAs($manager)->put("/logbook/{$e->id}", lb_body($v, ['driver_name' => 'B']))->assertRedirect();
    $this->actingAs($manager)->delete("/logbook/{$e->id}")->assertRedirect();
});

it('lets admin and super admin do everything', function () {
    $v = Vehicle::factory()->create();
    foreach ([lb_user('admin'), User::factory()->superAdmin()->create()] as $user) {
        $this->actingAs($user)->get('/logbook')->assertOk()->assertInertia(fn (Assert $p) => $p->where('can.manage', true));
        $this->actingAs($user)->post('/logbook', lb_body($v))->assertRedirect();
    }
    expect(LogbookEntry::count())->toBe(2);
});

it('answers 404 with the module switched off, whatever the rights', function () {
    $v = Vehicle::factory()->create();
    $e = lb_entry($v);
    $e->save();
    app(Settings::class)->set('modules.logbook.enabled', false);
    $admin = User::factory()->superAdmin()->create();

    $this->get('/logbook')->assertNotFound();
    $this->actingAs($admin)->get('/logbook')->assertNotFound();
    $this->actingAs($admin)->post('/logbook', lb_body($v))->assertNotFound();
    $this->actingAs($admin)->put("/logbook/{$e->id}", lb_body($v))->assertNotFound();
    $this->actingAs($admin)->delete("/logbook/{$e->id}")->assertNotFound();
    expect(LogbookEntry::count())->toBe(1);
});

it('lists the permissions in the role editor only while the module is on', function () {
    $admin = User::factory()->superAdmin()->create();
    expect(Permissions::groups())->toHaveKey('logbook');
    app(Settings::class)->set('modules.logbook.enabled', false);
    expect(Permissions::groups())->not->toHaveKey('logbook');
    expect(Permissions::known())->toContain('logbook.view', 'logbook.manage');
    $this->actingAs($admin)->get('/logbook')->assertNotFound();
});

it('puts the module into app.modules so the navigation entry can show for view or manage', function () {
    $this->actingAs(lb_user('logbook.view'))->get('/dashboard')->assertInertia(fn (Assert $p) => $p
        ->where('app.modules', fn ($m) => collect($m)->contains('logbook'))
        ->where('auth.user.permissions', fn ($perms) => collect($perms)->contains('logbook.view')));
});
