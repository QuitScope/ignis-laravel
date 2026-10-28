<?php

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

function mk(string $role, array $attrs = []): User
{
    return User::factory()->withRole(Role::findByName($role))->create($attrs);
}

function roleId(string $name): int
{
    return Role::findByName($name)->id;
}

it('redirects guests and forbids users without users.view', function () {
    $this->get('/users')->assertRedirect('/login');
    $this->actingAs(mk('Gast'))->get('/users')->assertForbidden();
    $this->actingAs(mk('Admin'))->get('/users')->assertOk();
    $this->actingAs(mk('SGL'))->get('/users')->assertOk();
});

it('lists, searches by username and name, filters by status and paginates 25', function () {
    $admin = mk('Admin', ['username' => 'boss', 'name' => 'Boss']);
    mk('Gast', ['username' => 'alpha_user', 'name' => 'Zed Zebra']);
    mk('Gast', ['username' => 'beta_user', 'name' => 'Quentin Quokka']);
    User::factory()->inactive()->create(['username' => 'gone', 'name' => 'Gone Gary']);

    $this->actingAs($admin)->get('/users?search=alpha')
        ->assertInertia(fn (Assert $p) => $p->component('Users/Index')->has('users.data', 1)->where('users.data.0.username', 'alpha_user'));
    $this->actingAs($admin)->get('/users?search=Quokka')
        ->assertInertia(fn (Assert $p) => $p->has('users.data', 1)->where('users.data.0.username', 'beta_user'));
    $this->actingAs($admin)->get('/users?status=inactive')
        ->assertInertia(fn (Assert $p) => $p->has('users.data', 1)->where('users.data.0.username', 'gone'));
    $this->actingAs($admin)->get('/users?status=active')
        ->assertInertia(fn (Assert $p) => $p->has('users.data', 3));

    User::factory()->count(30)->create();
    $this->actingAs($admin)->get('/users')
        ->assertInertia(fn (Assert $p) => $p->has('users.data', 25)->where('users.total', 34));
    $this->actingAs($admin)->get('/users?page=2&status=active')
        ->assertInertia(fn (Assert $p) => $p->has('users.data', 8)->where('users.current_page', 2)
            ->where('users.next_page_url', null)->where('users.prev_page_url', fn ($u) => str_contains($u, 'status=active')));
});

it('sends per-row abilities and the row fields', function () {
    $sgl = mk('SGL', ['username' => 'sgl_me']);
    mk('Gast', ['username' => 'low']);
    mk('Admin', ['username' => 'high']);

    $this->actingAs($sgl)->get('/users?search=low')->assertInertia(fn (Assert $p) => $p
        ->has('users.data.0', fn (Assert $r) => $r->hasAll(['id', 'username', 'name', 'role', 'is_active', 'created_at', 'can'])
            ->where('role.name', 'Gast')->where('can', ['update' => true, 'delete' => false, 'toggle' => false])));
    $this->actingAs($sgl)->get('/users?search=high')->assertInertia(fn (Assert $p) => $p
        ->where('users.data.0.can', ['update' => false, 'delete' => false, 'toggle' => false]));
    $this->actingAs($sgl)->get('/users?search=sgl_me')->assertInertia(fn (Assert $p) => $p
        ->where('users.data.0.can', ['update' => false, 'delete' => false, 'toggle' => false]));
});

it('forbids edit for same, higher and own rank, shows assignable roles otherwise', function () {
    $sgl = mk('SGL');
    $this->actingAs($sgl)->get('/users/'.mk('SGL')->id.'/edit')->assertForbidden();
    $this->actingAs($sgl)->get('/users/'.mk('Admin')->id.'/edit')->assertForbidden();
    $this->actingAs($sgl)->get('/users/'.$sgl->id.'/edit')->assertForbidden();
    $this->actingAs($sgl)->get('/users/'.User::factory()->superAdmin()->create()->id.'/edit')->assertForbidden();

    $this->actingAs($sgl)->get('/users/'.mk('Gast')->id.'/edit')
        ->assertInertia(fn (Assert $p) => $p->component('Users/Edit')
            ->where('assignableRoles', fn ($r) => collect($r)->pluck('name')->all() === ['TL', 'QM-RD', 'Ausbilder', 'Personaler', 'Gast'])
            ->where('actorRank', 100)->where('target.rank', 999));
});

it('changes the role and keeps exactly one', function () {
    $sgl = mk('SGL');
    $target = mk('Gast');

    $this->actingAs($sgl)->put("/users/{$target->id}", ['role' => roleId('TL')])->assertRedirect();

    $target->refresh()->load('roles');
    expect($target->roles)->toHaveCount(1)->and($target->role()->name)->toBe('TL');
});

it('rejects a crafted role at or above the own rank and changes nothing', function (string $actorRole, string $newRole) {
    $actor = mk($actorRole);
    $target = mk('Gast');

    $this->actingAs($actor)->put("/users/{$target->id}", ['role' => roleId($newRole)])->assertForbidden();

    expect($target->refresh()->role()->name)->toBe('Gast');
})->with([['SGL', 'SGL'], ['SGL', 'Admin'], ['Admin', 'Admin']]);

it('lets a super admin assign any role', function () {
    $target = mk('Gast');
    $this->actingAs(User::factory()->superAdmin()->create())->put("/users/{$target->id}", ['role' => roleId('Admin')])->assertRedirect();
    expect($target->refresh()->role()->name)->toBe('Admin');
});

it('forbids updating same-rank, higher-rank and own accounts', function () {
    $sgl = mk('SGL');
    foreach ([mk('SGL'), mk('Admin'), $sgl] as $target) {
        $before = $target->role()->name;
        $this->actingAs($sgl)->put("/users/{$target->id}", ['role' => roleId('Gast')])->assertForbidden();
        expect($target->refresh()->role()->name)->toBe($before);
    }
});

it('forbids a super admin from modifying themselves or another super admin', function () {
    $sa = User::factory()->superAdmin()->create();
    $other = User::factory()->superAdmin()->create();
    foreach ([$sa, $other] as $t) {
        $this->actingAs($sa)->put("/users/{$t->id}", ['role' => roleId('Gast')])->assertForbidden();
        $this->actingAs($sa)->post("/users/{$t->id}/toggle-active")->assertForbidden();
        $this->actingAs($sa)->delete("/users/{$t->id}")->assertForbidden();
    }
    expect(User::count())->toBe(2)->and($other->refresh()->is_active)->toBeTrue();
});

it('validates the role', function () {
    $admin = mk('Admin');
    $target = mk('Gast');
    $this->actingAs($admin)->put("/users/{$target->id}", [])->assertSessionHasErrors('role');
    $this->actingAs($admin)->put("/users/{$target->id}", ['role' => 99999])->assertSessionHasErrors('role');
    $this->actingAs($admin)->put("/users/{$target->id}", ['role' => 'abc'])->assertSessionHasErrors('role');
    expect($target->refresh()->role()->name)->toBe('Gast');
});

it('ignores mass-assignment attempts', function () {
    $admin = mk('Admin');
    $target = mk('Gast', ['name' => 'Original', 'username' => 'orig']);

    $this->actingAs($admin)->put("/users/{$target->id}", [
        'role' => roleId('TL'), 'is_super_admin' => 1, 'is_active' => 0, 'name' => 'Hacked', 'username' => 'hacked', 'discord_id' => '1',
    ])->assertRedirect();

    $target->refresh();
    expect($target->is_super_admin)->toBeFalse()->and($target->is_active)->toBeTrue()
        ->and($target->name)->toBe('Original')->and($target->username)->toBe('orig')
        ->and($target->discord_id)->not->toBe('1')
        ->and($target->role()->name)->toBe('TL');
});

it('deactivates and reactivates, setting and clearing the three columns', function () {
    $admin = mk('Admin');
    $target = mk('Gast');

    $this->actingAs($admin)->post("/users/{$target->id}/toggle-active")->assertRedirect();
    $target->refresh();
    expect($target->is_active)->toBeFalse()->and($target->deactivated_at)->not->toBeNull()
        ->and($target->deactivated_by)->toBe($admin->id);

    $this->actingAs($admin)->post("/users/{$target->id}/toggle-active")->assertRedirect();
    $target->refresh();
    expect($target->is_active)->toBeTrue()->and($target->deactivated_at)->toBeNull()->and($target->deactivated_by)->toBeNull();
});

it('forbids toggle and delete without users.delete or without rank', function () {
    $sgl = mk('SGL');
    $gast = mk('Gast');
    $this->actingAs($sgl)->post("/users/{$gast->id}/toggle-active")->assertForbidden();
    $this->actingAs($sgl)->delete("/users/{$gast->id}")->assertForbidden();

    $admin = mk('Admin');
    $this->actingAs(mk('Admin'))->post("/users/{$admin->id}/toggle-active")->assertForbidden();
    $this->actingAs(mk('Admin'))->delete("/users/{$admin->id}")->assertForbidden();
    expect(User::whereKey([$gast->id, $admin->id])->count())->toBe(2)->and($gast->refresh()->is_active)->toBeTrue();
});

it('logs a deactivated user out on their next request', function () {
    $admin = mk('Admin');
    $target = mk('Gast');
    $this->actingAs($target)->get('/')->assertOk();

    $this->actingAs($admin)->post("/users/{$target->id}/toggle-active")->assertRedirect();
    Auth::forgetGuards();

    $this->actingAs($target->refresh())->get('/')
        ->assertRedirect('/login')->assertSessionHas('auth.error', 'auth.error.deactivated');
    expect(Auth::check())->toBeFalse();
});

it('deletes the account and nulls deactivated_by of others', function () {
    $admin = mk('Admin');
    $victim = mk('Gast');
    $other = mk('Gast');
    $other->forceFill(['is_active' => false, 'deactivated_at' => now(), 'deactivated_by' => $victim->id])->save();

    $this->actingAs($admin)->delete("/users/{$victim->id}")->assertRedirect();

    expect(User::find($victim->id))->toBeNull()->and($other->refresh()->deactivated_by)->toBeNull();
    $this->assertDatabaseMissing('model_has_roles', ['model_id' => $victim->id, 'model_type' => User::class]);
});

it('never lets an actor delete or deactivate themselves', function () {
    $admin = mk('Admin');
    $this->actingAs($admin)->delete("/users/{$admin->id}")->assertForbidden();
    $this->actingAs($admin)->post("/users/{$admin->id}/toggle-active")->assertForbidden();
    expect($admin->refresh()->is_active)->toBeTrue();
});

it('answers 403 before validation when the actor may not update the target', function () {
    $sgl = mk('SGL');
    $this->actingAs($sgl)->put('/users/'.mk('Admin')->id, ['role' => 'garbage'])->assertForbidden();
    $this->actingAs($sgl)->put('/users/'.mk('Gast')->id, ['role' => 'garbage'])->assertSessionHasErrors('role');
    $this->actingAs($sgl)->put('/users/'.mk('Gast')->id, ['role' => roleId('TL')])->assertRedirect();
});

it('sends a null rank for an account without role', function () {
    $this->actingAs(mk('Admin'))->get('/users/'.User::factory()->create()->id.'/edit')
        ->assertInertia(fn (Assert $page) => $page->where('target.rank', null)->where('target.role_id', null)->missing('can'));
});
