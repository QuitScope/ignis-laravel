<?php

use App\Models\RegistrationCode;
use App\Models\Role;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\Settings;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Socialite\Contracts\Factory;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use SocialiteProviders\Manager\OAuth2\User as SocialiteUser;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

function au_user(string $role = 'Admin', array $attrs = []): User
{
    return User::factory()->withRole(Role::findByName($role))->create($attrs);
}

function au_entry(string $action): Activity
{
    return Activity::where('description', $action)->latest('id')->firstOrFail();
}

function au_discord(string $id, string $name = 'newbie'): void
{
    $account = (new SocialiteUser)->setRaw(['id' => $id])
        ->map(['id' => $id, 'nickname' => $name, 'name' => $name, 'email' => null, 'avatar' => null]);
    $provider = Mockery::mock(AbstractProvider::class);
    $provider->shouldReceive('setScopes')->andReturnSelf();
    $provider->shouldReceive('user')->andReturn($account);
    $factory = Mockery::mock(Factory::class);
    $factory->shouldReceive('driver')->with('discord')->andReturn($provider);
    Socialite::swap($factory);
    $s = app(Settings::class);
    $s->set('discord.client_id', '123456789012345678');
    $s->set('discord.client_secret', 'client-secret-value', encrypted: true);
}

const AU_CB = '/auth/discord/callback?code=x&state=s';

it('writes module, action, details, context and global', function () {
    $u = au_user();
    AuditLogger::log($u, 'Rolle erstellt', 'Name: X', 'Rollen', true, ['role_id' => 3]);

    $a = au_entry('Rolle erstellt');
    expect($a->log_name)->toBe('Rollen')
        ->and($a->causer_id)->toBe($u->id)
        ->and($a->properties->all())->toBe(['context' => ['role_id' => 3]])
        ->and($a->details)->toBe('Name: X')->and((bool) $a->is_global)->toBeTrue();
});

it('never throws when the write fails and logs only the exception class', function () {
    Schema::drop('activity_log');
    Log::shouldReceive('warning')->once()->with('Audit log failed: '.QueryException::class);

    AuditLogger::log(null, 'Irgendwas', 'secret-detail');

    expect(true)->toBeTrue();
});

it('lists the audit log only for admin or audit.view', function () {
    $this->get('/users/audit-log')->assertRedirect('/login');
    $this->actingAs(au_user('Gast'))->get('/users/audit-log')->assertForbidden();
    $this->actingAs(au_user('Admin'))->get('/users/audit-log')->assertOk();
    $role = Role::create(['name' => 'Aud', 'guard_name' => 'web', 'priority' => 500, 'color' => 'info']);
    $role->syncPermissions(['audit.view']);
    $this->actingAs(User::factory()->withRole($role)->create())->get('/users/audit-log')->assertOk();
});

it('lists only global entries, newest first, filtered by module', function () {
    $u = au_user('Admin', ['username' => 'boss']);
    AuditLogger::log($u, 'Alt', null, 'Rollen', true);
    AuditLogger::log($u, 'Versteckt', null, 'Rollen', false);
    AuditLogger::log($u, 'Neu', 'Details hier', 'Benutzer', true);
    Activity::where('description', 'Alt')->update(['created_at' => now()->subDay()]);

    $this->actingAs($u)->get('/users/audit-log')->assertInertia(fn (Assert $p) => $p
        ->component('Audit/Index')->has('entries.data', 2)
        ->where('entries.data.0.action', 'Neu')->where('entries.data.0.user', 'boss')->where('entries.data.1.action', 'Alt')
        ->where('modules', ['Benutzer', 'Rollen']));
    $this->actingAs($u)->get('/users/audit-log?module=Rollen')->assertInertia(fn (Assert $p) => $p
        ->has('entries.data', 1)->where('entries.data.0.action', 'Alt'));
});

it('searches action, details and username, and escapes LIKE wildcards', function () {
    $u = au_user('Admin', ['username' => 'boss']);
    $other = au_user('Gast', ['username' => 'zara']);
    AuditLogger::log($u, 'Rolle erstellt', 'Name: Alpha', 'Rollen', true);
    AuditLogger::log($other, 'Benutzer deaktiviert', '100% sicher', 'Benutzer', true);
    AuditLogger::log($other, 'Einladung erstellt', 'a_b', 'Einladungen', true);

    $count = fn (string $q) => $this->actingAs($u)->get('/users/audit-log?search='.urlencode($q))->viewData('page')['props']['entries']['total'];
    expect($count('deaktiviert'))->toBe(1)
        ->and($count('Alpha'))->toBe(1)
        ->and($count('zara'))->toBe(2)
        ->and($count('%'))->toBe(1)
        ->and($count('_'))->toBe(1)
        ->and($count('!'))->toBe(0)
        ->and($count('\\'))->toBe(0);
});

it('escapes LIKE wildcards in the user search too', function () {
    $admin = au_user('Admin', ['username' => 'boss']);
    au_user('Gast', ['username' => 'a_b']);
    au_user('Gast', ['username' => 'axb']);

    $this->actingAs($admin)->get('/users?search='.urlencode('a_b'))
        ->assertInertia(fn (Assert $p) => $p->has('users.data', 1)->where('users.data.0.username', 'a_b'));
    $this->actingAs($admin)->get('/users?search='.urlencode('%'))
        ->assertInertia(fn (Assert $p) => $p->has('users.data', 0));
});

it('logs login, registration, redemption and denial without secrets', function () {
    app(Settings::class)->set('auth.registration_mode', 'code');
    $known = User::factory()->create(['discord_id' => '11111111111111111']);
    au_discord('11111111111111111');
    $this->get(AU_CB)->assertRedirect('/');
    expect(au_entry('Benutzer angemeldet')->causer_id)->toBe($known->id);
    $this->post('/logout');
    expect(au_entry('Benutzer abgemeldet')->log_name)->toBe('Anmeldung');

    $code = RegistrationCode::generate(null, null, null);
    au_discord('22222222222222222');
    $this->withSession(['registration_code' => $code->code])->get(AU_CB)->assertRedirect('/');
    $new = User::where('discord_id', '22222222222222222')->sole();
    expect(au_entry('Benutzer registriert')->causer_id)->toBe($new->id)
        ->and(au_entry('Einladung eingelöst')->properties['context'])->toBe(['registration_code_id' => $code->id, 'user_id' => $new->id]);

    auth()->logout();
    au_discord('33333333333333333');
    $this->get(AU_CB)->assertRedirect('/login');
    $denied = au_entry('Anmeldung abgelehnt');
    expect($denied->causer_id)->toBeNull()
        ->and($denied->properties['context']['discord_id'])->toBe('33333333333333333');

    foreach (Activity::all() as $a) {
        expect(json_encode($a->toArray()))->not->toContain($code->code)->not->toContain('client-secret-value');
    }
});

it('logs role create, update with permission diff, and delete', function () {
    $sa = User::factory()->create(['is_super_admin' => true]);
    $this->actingAs($sa)->post('/users/roles', ['name' => 'Neu', 'priority' => 300, 'color' => 'success', 'permissions' => ['users.view']]);
    $role = Role::findByName('Neu');
    $c = au_entry('Rolle erstellt');
    expect($c->log_name)->toBe('Rollen')->and($c->properties['context'])->toBe(['role_id' => $role->id]);

    $this->actingAs($sa)->put("/users/roles/{$role->id}", ['name' => 'Neu', 'priority' => 300, 'color' => 'success', 'permissions' => ['users.edit']]);
    $u = au_entry('Rolle aktualisiert');
    expect($u->details)->toContain('hinzugefügt: users.edit')->toContain('entfernt: users.view')
        ->and($u->properties['context'])->toBe(['role_id' => $role->id]);

    $this->actingAs($sa)->delete("/users/roles/{$role->id}");
    expect(au_entry('Rolle gelöscht')->properties['context'])->toBe(['role_id' => $role->id]);
});

it('logs user role change, deactivate, reactivate and delete', function () {
    $admin = au_user('Admin');
    $target = au_user('Gast', ['username' => 'tgt']);
    $sgl = Role::findByName('SGL');

    $this->actingAs($admin)->put("/users/{$target->id}", ['role' => $sgl->id]);
    $a = au_entry('Benutzer aktualisiert');
    expect($a->details)->toBe('Rolle: Gast → SGL')
        ->and($a->properties['context'])->toBe(['user_id' => $target->id, 'role_id' => $sgl->id]);

    $this->actingAs($admin)->post("/users/{$target->id}/toggle-active");
    expect(au_entry('Benutzer deaktiviert')->properties['context'])->toBe(['user_id' => $target->id]);
    $this->actingAs($admin)->post("/users/{$target->id}/toggle-active");
    expect(au_entry('Benutzer reaktiviert')->properties['context'])->toBe(['user_id' => $target->id]);
    $this->actingAs($admin)->delete("/users/{$target->id}");
    expect(au_entry('Benutzer endgültig gelöscht')->properties['context'])->toBe(['user_id' => $target->id]);
});

it('logs invitation create and delete with the id, never the code', function () {
    $admin = au_user('Admin');
    $this->actingAs($admin)->post('/users/invitations', ['label' => 'Max']);
    $code = RegistrationCode::sole();
    $e = au_entry('Einladung erstellt');
    expect($e->properties['context'])->toBe(['registration_code_id' => $code->id]);

    $this->actingAs($admin)->delete("/users/invitations/{$code->id}");
    expect(au_entry('Einladung gelöscht')->properties['context'])->toBe(['registration_code_id' => $code->id]);
    foreach (Activity::all() as $a) {
        expect(json_encode($a->toArray()))->not->toContain($code->code);
    }
});

it('adds real is_global and details columns with defaults', function () {
    expect(Schema::hasColumns('activity_log', ['is_global', 'details']))->toBeTrue();
    $a = Activity::create(['description' => 'x']);
    expect($a->fresh()->getAttribute('is_global'))->toEqual(0)->and($a->fresh()->getAttribute('details'))->toBeNull();
});

it('stores global and details in columns and only context in properties', function () {
    AuditLogger::log(null, 'A', 'D', 'Rollen', true, ['role_id' => 1]);
    $a = au_entry('A');
    expect((bool) $a->is_global)->toBeTrue()->and($a->details)->toBe('D')
        ->and($a->properties->all())->toBe(['context' => ['role_id' => 1]]);
});

it('uses no JSON functions in audit page queries and finds unicode details', function () {
    $u = au_user('Admin');
    AuditLogger::log($u, 'Benutzer aktualisiert', 'Rolle: Gäste → SGL', 'Benutzer', true);
    AuditLogger::log($u, 'Versteckt', 'Gäste', 'Benutzer', false);

    DB::enableQueryLog();
    $this->actingAs($u)->get('/users/audit-log?search='.urlencode('Gäste →'))
        ->assertInertia(fn (Assert $p) => $p->has('entries.data', 1)->where('entries.data.0.details', 'Rolle: Gäste → SGL'));
    $sql = collect(DB::getQueryLog())->pluck('query')->implode("\n");
    expect(strtolower($sql))->not->toContain('json_');
});
