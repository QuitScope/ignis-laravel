<?php

use App\Auth\ApiKey;
use App\Models\Role;
use App\Models\User;
use App\Support\Settings;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

function keyUser(string ...$perms): User
{
    $role = Role::create(['name' => 'K'.uniqid(), 'guard_name' => 'web', 'priority' => 500, 'color' => 'info']);
    $role->syncPermissions($perms);

    return User::factory()->withRole($role)->create();
}

it('requires login and the admin right', function () {
    $this->getJson('/settings/system/api-key')->assertUnauthorized();
    $this->postJson('/settings/system/api-key')->assertUnauthorized();

    $user = keyUser('personnel.view');
    $this->actingAs($user)->getJson('/settings/system/api-key')->assertForbidden();
    $this->actingAs($user)->postJson('/settings/system/api-key')->assertForbidden();
    $this->actingAs(keyUser('admin'))->getJson('/settings/system/api-key')->assertOk();
    $this->actingAs(User::factory()->superAdmin()->create())->getJson('/settings/system/api-key')->assertOk();
});

it('hands the key out only over the JSON route, never in page props, with no-store', function () {
    $key = app(ApiKey::class)->current();
    $admin = keyUser('admin');

    $page = $this->actingAs($admin)->get('/settings/system/config?tab=interfaces');
    $page->assertInertia(fn (Assert $p) => $p->where('apiKeySet', true));
    expect($page->getContent())->not->toContain($key);

    $res = $this->actingAs($admin)->getJson('/settings/system/api-key')->assertOk()->assertExactJson(['key' => $key]);
    expect($res->headers->get('Cache-Control'))->toContain('no-store');
});

it('returns a null key without minting one when none is configured', function () {
    $admin = keyUser('admin');
    $this->actingAs($admin)->get('/settings/system/config?tab=interfaces')->assertInertia(fn (Assert $p) => $p->where('apiKeySet', false));
    $this->actingAs($admin)->getJson('/settings/system/api-key')->assertExactJson(['key' => null]);

    expect(app(ApiKey::class)->isConfigured())->toBeFalse();
});

it('regenerates the key, invalidates the old one at once and audits without the value', function () {
    app(Settings::class)->set('auth.tablet_login', true);
    $old = app(ApiKey::class)->current();
    $login = fn (string $key) => $this->postJson('/api/tablet/login-token', ['discord_id' => '123456789012345678'], ['X-API-Key' => $key]);
    $login($old)->assertNotFound();

    $res = $this->actingAs(keyUser('admin'))->postJson('/settings/system/api-key')->assertOk();
    $new = $res->json('key');
    expect($new)->not->toBe($old)->and(strlen($new))->toBeGreaterThanOrEqual(32)->and($res->headers->get('Cache-Control'))->toContain('no-store');

    $login($old)->assertForbidden();
    $login($new)->assertNotFound();

    $a = Activity::where('description', 'API-Schlüssel neu erzeugt')->sole();
    expect($a->log_name)->toBe('System')->and($a->details)->toBeNull()->and((bool) $a->is_global)->toBeTrue()
        ->and(json_encode($a->toArray()))->not->toContain($new)->not->toContain($old);
});

it('throttles reading and regenerating', function () {
    $admin = keyUser('admin');
    foreach (range(1, 30) as $_) {
        $this->actingAs($admin)->getJson('/settings/system/api-key')->assertOk();
    }
    $this->actingAs($admin)->getJson('/settings/system/api-key')->assertStatus(429);

    foreach (range(1, 5) as $_) {
        $this->actingAs($admin)->postJson('/settings/system/api-key')->assertOk();
    }
    $this->actingAs($admin)->postJson('/settings/system/api-key')->assertStatus(429);
});

it('audits showing the key, without the value', function () {
    $key = app(ApiKey::class)->current();
    $this->actingAs(keyUser('admin'))->getJson('/settings/system/api-key')->assertOk();

    $a = Activity::where('description', 'API-Schlüssel angezeigt')->sole();
    expect($a->log_name)->toBe('System')->and($a->details)->toBeNull()->and(json_encode($a->toArray()))->not->toContain($key);
});

it('does not audit a read when no key exists', function () {
    $this->actingAs(keyUser('admin'))->getJson('/settings/system/api-key')->assertOk();
    expect(Activity::count())->toBe(0);
});
