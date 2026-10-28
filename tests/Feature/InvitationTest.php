<?php

use App\Models\RegistrationCode;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Support\Settings;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use SocialiteProviders\Manager\OAuth2\User as SocialiteUser;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $s = app(Settings::class);
    $s->set('discord.client_id', '123456789012345678');
    $s->set('discord.client_secret', 'client-secret-value', encrypted: true);
    $s->set('auth.registration_mode', 'code');
});

function inv_discord(string $id = '22222222222222222'): void
{
    $account = (new SocialiteUser)->setRaw(['id' => $id])
        ->map(['id' => $id, 'nickname' => 'newbie', 'name' => 'newbie', 'email' => null, 'avatar' => null]);
    $provider = Mockery::mock(AbstractProvider::class);
    $provider->shouldReceive('setScopes')->andReturnSelf();
    $provider->shouldReceive('user')->andReturn($account);
    $factory = Mockery::mock(LaravelSocialiteContractsFactory::class);
    $factory->shouldReceive('driver')->with('discord')->andReturn($provider);
    Socialite::swap($factory);
}

const INV_CB = '/auth/discord/callback?code=x&state=s';

it('lets only users with the permission manage codes', function () {
    $admin = User::factory()->superAdmin()->create();
    $guest = User::factory()->withRole(Role::findByName('Gast'))->create();

    $this->actingAs($guest)->get('/users/invitations')->assertForbidden();
    $this->actingAs($guest)->post('/users/invitations', ['expires_in_days' => 14])->assertForbidden();
    expect(RegistrationCode::count())->toBe(0);

    $this->actingAs($admin)->post('/users/invitations', ['label' => 'Max', 'expires_in_days' => 7])->assertRedirect();
    $code = RegistrationCode::sole();
    expect($code->label)->toBe('Max')->and($code->created_by)->toBe($admin->id)
        ->and(strlen($code->code))->toBe(32)
        ->and($code->expires_at?->isFuture())->toBeTrue();

    $this->actingAs($admin)->get('/users/invitations')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('Users/Invitations')
        ->where('codes.0.url', config('app.url').'/invite?code='.$code->code)
        ->where('codes.0.status', 'unused'));

    $this->actingAs($guest)->delete("/users/invitations/{$code->id}")->assertForbidden();
    expect(RegistrationCode::count())->toBe(1);
    $this->actingAs($admin)->delete("/users/invitations/{$code->id}")->assertRedirect();
    expect(RegistrationCode::count())->toBe(0);
});

it('validates label and expiry', function () {
    $this->actingAs(User::factory()->superAdmin()->create())
        ->post('/users/invitations', ['label' => str_repeat('a', 256), 'expires_in_days' => 400])
        ->assertSessionHasErrors(['label', 'expires_in_days']);
});

it('refuses to delete a used code', function () {
    $admin = User::factory()->superAdmin()->create();
    $code = RegistrationCode::generate($admin, null, null);
    RegistrationCode::reserve($code->code);

    $this->actingAs($admin)->delete("/users/invitations/{$code->id}");
    expect(RegistrationCode::count())->toBe(1);
});

it('GET /invite remembers the code and redirects without it in the URL', function () {
    $code = RegistrationCode::generate(null, null, null);

    $r = $this->get('/invite?code='.$code->code)->assertRedirect('/auth/discord')
        ->assertSessionHas('registration_code', $code->code);
    expect($r->headers->get('Location'))->not->toContain('code=');
});

it('POST /invite remembers the code and redirects without it in the URL', function () {
    $code = RegistrationCode::generate(null, null, null);

    $r = $this->post('/invite', ['code' => $code->code])->assertRedirect('/auth/discord')
        ->assertSessionHas('registration_code', $code->code);
    expect($r->headers->get('Location'))->not->toContain($code->code);
});

it('rejects unknown, used and expired codes', function (string $how) {
    $code = RegistrationCode::generate(null, null, $how === 'expired' ? now()->subMinute() : null);
    if ($how === 'used') {
        RegistrationCode::reserve($code->code);
    }
    $value = $how === 'unknown' ? 'nope' : $code->code;

    $this->get('/invite?code='.$value)->assertRedirect('/login')
        ->assertSessionHas('auth.error', 'auth.error.code_invalid')->assertSessionMissing('registration_code');
    $this->post('/invite', ['code' => $value])->assertRedirect('/login')
        ->assertSessionHas('auth.error', 'auth.error.code_invalid');
    $this->post('/invite', ['code' => ['x']])->assertSessionHas('auth.error', 'auth.error.code_invalid');
})->with(['unknown', 'used', 'expired']);

it('answers closed mode with the closed message', function () {
    app(Settings::class)->set('auth.registration_mode', 'closed');
    $code = RegistrationCode::generate(null, null, null);

    $this->get('/invite?code='.$code->code)->assertRedirect('/login')
        ->assertSessionHas('auth.error', 'auth.error.closed');
});

it('throttles /invite', function () {
    for ($i = 0; $i < 20; $i++) {
        $this->post('/invite', ['code' => 'nope'])->assertRedirect('/login');
    }
    $this->post('/invite', ['code' => 'nope'])->assertStatus(429);
});

it('registers with a code, marks it used and clears it from the session', function () {
    $code = RegistrationCode::generate(null, null, null);
    inv_discord();

    $this->withSession(['registration_code' => $code->code])->get(INV_CB)->assertRedirect('/')
        ->assertSessionMissing('registration_code');

    $user = User::where('discord_id', '22222222222222222')->sole();
    expect($code->fresh()->used_by)->toBe($user->id)->and($code->fresh()->used_at)->not->toBeNull();
});

it('leaves the code unused when creating the account fails', function () {
    $code = RegistrationCode::generate(null, null, null);
    inv_discord();
    User::creating(fn () => throw new RuntimeException('boom'));

    $this->withoutExceptionHandling();
    expect(fn () => $this->withSession(['registration_code' => $code->code])->get(INV_CB))->toThrow(RuntimeException::class);

    expect($code->fresh()->used_at)->toBeNull()->and(User::count())->toBe(0);
});

it('lets the second registration with the same code lose', function () {
    $code = RegistrationCode::generate(null, null, null);

    inv_discord('22222222222222222');
    $this->withSession(['registration_code' => $code->code])->get(INV_CB)->assertRedirect('/');
    $this->flushSession();
    auth()->forgetGuards();

    inv_discord('33333333333333333');
    $this->withSession(['registration_code' => $code->code])->get(INV_CB)
        ->assertRedirect('/login')->assertSessionHas('auth.error', 'auth.error.code_invalid');

    expect(User::count())->toBe(1);
});

it('rejects a code that became unusable between check and registration (race)', function () {
    $code = RegistrationCode::generate(null, null, null);
    inv_discord();
    $done = false;
    DB::listen(function ($q) use ($code, &$done) {
        if (! $done && str_starts_with($q->sql, 'select') && str_contains($q->sql, 'registration_codes')) {
            $done = true;
            DB::table('registration_codes')->where('id', $code->id)->update(['used_at' => now()]);
        }
    });

    $this->withSession(['registration_code' => $code->code])->get(INV_CB)
        ->assertRedirect('/login')->assertSessionHas('auth.error', 'auth.error.code_invalid');
    expect(User::count())->toBe(0);
});

it('redeems a carried code in open mode and ignores an invalid one', function () {
    app(Settings::class)->set('auth.registration_mode', 'open');
    $code = RegistrationCode::generate(null, null, null);
    inv_discord();
    $this->withSession(['registration_code' => $code->code])->get(INV_CB)->assertRedirect('/');
    expect($code->fresh()->used_by)->toBe(User::sole()->id);

    $this->flushSession();
    auth()->forgetGuards();
    inv_discord('33333333333333333');
    $this->withSession(['registration_code' => 'bogus'])->get(INV_CB)->assertRedirect('/');
    expect(User::count())->toBe(2);
});

it('falls back to login when the account appears between decision and insert', function () {
    app(Settings::class)->set('auth.registration_mode', 'open');
    inv_discord();
    $done = false;
    DB::listen(function ($q) use (&$done) {
        if (! $done && str_starts_with($q->sql, 'select') && preg_match('/from [`"]users[`"]/', $q->sql)) {
            $done = true;
            DB::table('users')->insert(['discord_id' => '22222222222222222', 'username' => 'twin', 'is_active' => true, 'is_super_admin' => false]);
        }
    });

    $this->get(INV_CB)->assertRedirect('/');

    $this->assertAuthenticatedAs(User::where('discord_id', '22222222222222222')->sole());
    expect(User::count())->toBe(1);
});

it('answers a broken provider payload with discord_failed', function () {
    $provider = Mockery::mock(AbstractProvider::class);
    $provider->shouldReceive('user')->andThrow(new TypeError('x'));
    $factory = Mockery::mock(LaravelSocialiteContractsFactory::class);
    $factory->shouldReceive('driver')->with('discord')->andReturn($provider);
    Socialite::swap($factory);

    $this->get(INV_CB)->assertRedirect('/login')->assertSessionHas('auth.error', 'auth.error.discord_failed');
});

it('answers an array code/state with a valid session state with discord_failed, not a 500', function () {
    $this->withSession(['state' => 'expected'])->get('/auth/discord/callback?code[]=x&state[]=expected')
        ->assertRedirect('/login')->assertSessionHas('auth.error', 'auth.error.discord_failed');
    expect(User::count())->toBe(0);
});

it('drops a stale carried code on early callback failures', function () {
    $this->withSession(['registration_code' => 'abc', 'state' => 'expected'])
        ->get('/auth/discord/callback?code=x&state=wrong')
        ->assertSessionHas('auth.error', 'auth.error.discord_failed')->assertSessionMissing('registration_code');

    Setting::where('key', 'discord.client_secret')->update(['value' => 'not-decryptable']);
    app(Settings::class)->refresh();
    $this->withSession(['registration_code' => 'abc'])->get(INV_CB)
        ->assertSessionHas('auth.error', 'auth.error.secret_unavailable')->assertSessionMissing('registration_code');
});

it('enforces CSRF on POST /invite and accepts the current token', function () {
    $this->app['env'] = 'local';
    $code = RegistrationCode::generate(null, null, null);

    $this->post('/invite', ['code' => $code->code])->assertStatus(419);

    $r = $this->withSession(['_token' => 'tok'])->post('/invite', ['code' => $code->code, '_token' => 'tok'])
        ->assertRedirect('/auth/discord');
    expect($r->headers->get('Location'))->not->toContain('code=');
});

it('answers an Inertia POST /invite with a full-page visit to /auth/discord', function () {
    $code = RegistrationCode::generate(null, null, null);

    $this->post('/invite', ['code' => $code->code], ['X-Inertia' => 'true'])
        ->assertStatus(409)->assertHeader('X-Inertia-Location', url('/auth/discord'));
});

it('provides a working XSRF token on /login after logout', function () {
    $this->app['env'] = 'local';
    $code = RegistrationCode::generate(null, null, null);
    $user = User::factory()->create();

    $old = 'old-token';
    $this->actingAs($user)->withSession(['_token' => $old])->post('/logout', ['_token' => $old])->assertRedirect('/login');
    Auth::forgetGuards();

    $cookie = $this->get('/login')->assertOk()->getCookie('XSRF-TOKEN', false);
    expect($cookie)->not->toBeNull();

    $this->post('/invite', ['code' => $code->code], ['X-XSRF-TOKEN' => $cookie->getValue()])
        ->assertRedirect('/auth/discord');
    $this->post('/invite', ['code' => $code->code], ['X-CSRF-TOKEN' => $old])->assertStatus(419);
});
