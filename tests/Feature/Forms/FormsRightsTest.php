<?php

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Forms\Models\FormField;
use Modules\Forms\Models\FormType;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

function fr_user(string ...$perms): User
{
    $role = Role::create(['name' => 'F'.uniqid(), 'guard_name' => 'web', 'priority' => 500, 'color' => 'info']);
    $role->syncPermissions($perms);

    return User::factory()->withRole($role)->create();
}

/** @return list<array{string, string, array<string, mixed>}> */
function fr_routes(): array
{
    $t = FormType::first();
    $f = $t->fields()->first();

    return [
        ['get', '/settings/forms', []],
        ['get', '/settings/forms/create', []],
        ['post', '/settings/forms', ['name' => 'X', 'is_active' => true, 'is_absence' => false]],
        ['get', "/settings/forms/{$t->id}/edit", []],
        ['put', "/settings/forms/{$t->id}", ['name' => 'X', 'is_active' => true, 'is_absence' => false]],
        ['patch', "/settings/forms/{$t->id}/active", ['is_active' => false]],
        ['delete', "/settings/forms/{$t->id}", []],
        ['put', '/settings/forms/order', ['ids' => [$t->id]]],
        ['post', "/settings/forms/{$t->id}/fields", ['key' => 'k', 'label' => 'L', 'type' => 'text']],
        ['delete', "/settings/forms/{$t->id}/fields/{$f->id}", []],
        ['put', "/settings/forms/{$t->id}/fields/order", ['ids' => [$f->id]]],
    ];
}

it('sends guests to the login for every route', function () {
    foreach (fr_routes() as [$method, $url, $body]) {
        $this->{$method}($url, $body)->assertRedirect('/login');
    }
});

it('keeps everybody but system admins out, even with application.edit', function () {
    $before = [FormType::count(), FormField::count()];
    foreach ([fr_user('application.view', 'application.edit', 'personnel.documents.manage', 'users.edit'), User::factory()->create()] as $user) {
        foreach (fr_routes() as [$method, $url, $body]) {
            $this->actingAs($user)->{$method}($url, $body)->assertForbidden();
        }
    }
    expect([FormType::count(), FormField::count()])->toBe($before);
});

it('lets the admin permission and super admins in', function () {
    foreach ([fr_user('admin'), User::factory()->superAdmin()->create()] as $user) {
        $this->actingAs($user)->get('/settings/forms')->assertOk();
        $this->actingAs($user)->postJson('/settings/forms', ['name' => 'X', 'is_active' => true, 'is_absence' => false])->assertCreated();
    }
});

it('rejects non-numeric type ids as unknown routes', function () {
    $this->actingAs(User::factory()->superAdmin()->create())->get('/settings/forms/abc/edit')->assertNotFound();
});
