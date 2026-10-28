<?php

use App\Auth\Permissions;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Support\Settings;
use Database\Seeders\RoleSeeder;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as PsrRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use SocialiteProviders\Manager\OAuth2\User as SocialiteUser;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

const ADMIN_DISCORD_ID = '98765432109876543';

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $s = app(Settings::class);
    $s->set('discord.client_id', '123456789012345678');
    $s->set('discord.client_secret', 'client-secret-value', encrypted: true);
    $s->set('install.admin_discord_id', ADMIN_DISCORD_ID);
});

/** @param array<string, mixed> $extraRaw */
function discordAccount(string $id, string $username = 'tester', array $extraRaw = []): SocialiteUser
{
    return (new SocialiteUser)
        ->setRaw(['id' => $id, 'username' => $username, 'discriminator' => '0', ...$extraRaw])
        ->map(['id' => $id, 'nickname' => $username, 'name' => $username, 'email' => null, 'avatar' => null]);
}

function fakeDiscord(SocialiteUser|Throwable $result): void
{
    $provider = Mockery::mock(AbstractProvider::class);
    $provider->shouldReceive('setScopes')->andReturnSelf();
    $user = $provider->shouldReceive('user');
    $result instanceof Throwable ? $user->andThrow($result) : $user->andReturn($result);
    Socialite::shouldReceive('driver')->with('discord')->andReturn($provider);
}

function registrationMode(string $mode): void
{
    app(Settings::class)->set('auth.registration_mode', $mode);
}

const CALLBACK = '/auth/discord/callback?code=oauth-code-xyz&state=st';

it('logs in a known active user and lands on /', function () {
    $user = User::factory()->create(['discord_id' => '11111111111111111']);
    fakeDiscord(discordAccount('11111111111111111'));

    $this->get(CALLBACK)->assertRedirect('/');

    $this->assertAuthenticatedAs($user);
});

it('rejects a deactivated user with a message on /login', function () {
    User::factory()->inactive()->create(['discord_id' => '11111111111111111']);
    fakeDiscord(discordAccount('11111111111111111'));

    $this->get(CALLBACK)
        ->assertRedirect('/login')
        ->assertSessionHas('auth.error', 'auth.error.deactivated');
    $this->assertGuest();

    $this->get('/login')->assertInertia(fn (Assert $page) => $page
        ->component('Auth/Login')
        ->where('error', __('auth.error.deactivated')));
});

it('creates the installation admin as super admin with role Admin, even when closed', function () {
    registrationMode('closed');
    fakeDiscord(discordAccount(ADMIN_DISCORD_ID, 'boss'));

    $this->get(CALLBACK)->assertRedirect('/');

    $user = User::where('discord_id', ADMIN_DISCORD_ID)->sole();
    expect($user->is_super_admin)->toBeTrue()
        ->and($user->role()?->name)->toBe('Admin')
        ->and($user->username)->toBe('boss');
    $this->assertAuthenticatedAs($user);
});

it('does not escalate an existing account with the installation admin id', function () {
    $user = User::factory()->withRole(Role::findByName('Gast'))->create(['discord_id' => ADMIN_DISCORD_ID]);
    fakeDiscord(discordAccount(ADMIN_DISCORD_ID));

    $this->get(CALLBACK)->assertRedirect('/');

    $user->refresh();
    expect($user->is_super_admin)->toBeFalse()
        ->and($user->role()?->name)->toBe('Gast')
        ->and(User::count())->toBe(1);
});

it('rejects unknown users when registration is closed', function () {
    registrationMode('closed');
    fakeDiscord(discordAccount('22222222222222222'));

    $this->get(CALLBACK)
        ->assertRedirect('/login')
        ->assertSessionHas('auth.error', 'auth.error.closed');

    expect(User::count())->toBe(0);
    $this->assertGuest();
});

it('audits a denied login at most once per Discord id and reason in 10 minutes', function () {
    registrationMode('closed');
    fakeDiscord(discordAccount('22222222222222222'));

    $this->get(CALLBACK)->assertRedirect('/login');
    $this->get(CALLBACK)->assertRedirect('/login');
    expect(Activity::where('description', 'Anmeldung abgelehnt')->count())->toBe(1);

    $this->travel(11)->minutes();
    $this->get(CALLBACK)->assertRedirect('/login');
    expect(Activity::where('description', 'Anmeldung abgelehnt')->count())->toBe(2);
});

it('registers unknown users in open mode with the default role and explicit attributes', function () {
    registrationMode('open');
    fakeDiscord(discordAccount('22222222222222222', 'newbie', ['is_super_admin' => true, 'name' => 'Injected', 'is_active' => false]));

    $this->get(CALLBACK)->assertRedirect('/');

    $user = User::where('discord_id', '22222222222222222')->sole();
    expect($user->username)->toBe('newbie')
        ->and($user->name)->toBeNull()
        ->and($user->is_active)->toBeTrue()
        ->and($user->is_super_admin)->toBeFalse()
        ->and($user->role()?->name)->toBe('Gast');
    $this->assertAuthenticatedAs($user);
});

it('requires a code for unknown users in code mode', function () {
    registrationMode('code');
    fakeDiscord(discordAccount('22222222222222222'));

    $this->get(CALLBACK)
        ->assertRedirect('/login')
        ->assertSessionHas('auth.error', 'auth.error.code_required');

    expect(User::count())->toBe(0);
});

it('rejects an invalid code in code mode', function () {
    registrationMode('code');
    fakeDiscord(discordAccount('22222222222222222'));

    $this->withSession(['registration_code' => 'nope'])->get(CALLBACK)
        ->assertRedirect('/login')
        ->assertSessionHas('auth.error', 'auth.error.code_invalid');

    expect(User::count())->toBe(0);
});

it('defaults to code mode', function () {
    fakeDiscord(discordAccount('22222222222222222'));

    $this->get(CALLBACK)->assertSessionHas('auth.error', 'auth.error.code_required');
});

it('denies registration when no default role exists', function () {
    registrationMode('open');
    Role::query()->update(['is_default' => false]);
    fakeDiscord(discordAccount('22222222222222222'));

    $this->get(CALLBACK)
        ->assertRedirect('/login')
        ->assertSessionHas('auth.error', 'auth.error.no_default_role');

    expect(User::count())->toBe(0);
});

it('aborts without creating an account when the OAuth state is missing or wrong', function () {
    registrationMode('open');

    $this->get(CALLBACK)
        ->assertRedirect('/login')
        ->assertSessionHas('auth.error', 'auth.error.discord_failed');
    $this->withSession(['state' => 'expected'])->get(CALLBACK)
        ->assertRedirect('/login')
        ->assertSessionHas('auth.error', 'auth.error.discord_failed');

    expect(User::count())->toBe(0);
    $this->assertGuest();
});

it('handles Discord network errors without leaking the code into the log', function () {
    registrationMode('open');
    Log::spy();
    fakeDiscord(new ConnectException('cURL error for code=oauth-code-xyz', new PsrRequest('POST', 'https://discord.com/api/oauth2/token')));

    $this->get(CALLBACK)
        ->assertRedirect('/login')
        ->assertSessionHas('auth.error', 'auth.error.discord_failed');

    expect(User::count())->toBe(0);
    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $msg) => ! str_contains($msg, 'oauth-code-xyz') && ! str_contains($msg, 'client-secret-value'));
});

it('shows an error instead of a 500 when the client secret cannot be decrypted', function (string $uri) {
    Setting::where('key', 'discord.client_secret')->update(['value' => 'not-decryptable']);
    app(Settings::class)->refresh();

    $this->get($uri)
        ->assertRedirect('/login')
        ->assertSessionHas('auth.error', 'auth.error.secret_unavailable');

    expect(User::count())->toBe(0);
})->with(['/auth/discord', CALLBACK]);

it('redirects to Discord with settings-based credentials, identify scope and a state', function () {
    app(Settings::class)->set('discord.redirect_path', '/auth/callback.php');

    $location = $this->get('/auth/discord')->assertRedirect()->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $q);

    expect($location)->toStartWith('https://discord.com/api/oauth2/authorize?')
        ->and($q['client_id'])->toBe('123456789012345678')
        ->and($q['redirect_uri'])->toBe('http://localhost/auth/callback.php')
        ->and($q['scope'])->toBe('identify')
        ->and($q['state'])->toBe(session('state'))
        ->and($location)->not->toContain('client-secret-value');
});

it('uses /auth/discord/callback as the default redirect path', function () {
    $location = $this->get('/auth/discord')->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $q);

    expect($q['redirect_uri'])->toBe('http://localhost/auth/discord/callback');
});

it('throttles the Discord entry point', function () {
    for ($i = 0; $i < 20; $i++) {
        $this->get('/auth/discord')->assertRedirect();
    }

    $this->get('/auth/discord')->assertStatus(429);
});

it('logs out a user deactivated during a running session on the next request', function () {
    $user = User::factory()->create(['discord_id' => '11111111111111111']);
    fakeDiscord(discordAccount('11111111111111111'));
    $this->get(CALLBACK)->assertRedirect('/');
    $this->get('/')->assertOk();

    User::whereKey($user->id)->update(['is_active' => false]);
    Auth::forgetGuards();

    $this->get('/')
        ->assertRedirect('/login')
        ->assertSessionHas('auth.error', 'auth.error.deactivated');
    expect(Auth::check())->toBeFalse();
});

it('answers 401 JSON to a plain JSON request of a deactivated user, but redirects Inertia', function (array $headers, ?int $status) {
    $user = User::factory()->create(['discord_id' => '11111111111111111']);
    fakeDiscord(discordAccount('11111111111111111'));
    $this->get(CALLBACK)->assertRedirect('/');

    User::whereKey($user->id)->update(['is_active' => false]);
    Auth::forgetGuards();

    $r = $this->get('/', $headers);
    $status === 401 ? $r->assertUnauthorized()->assertExactJson(['error' => 'deactivated']) : $r->assertRedirect('/login');
    expect(Auth::check())->toBeFalse();
})->with([
    'plain json' => [['Accept' => 'application/json'], 401],
    'inertia' => [['Accept' => 'application/json', 'X-Inertia' => 'true'], null],
    'browser' => [[], null],
]);

it('ignores an external intended URL and keeps an internal one', function (string $intended, string $expected) {
    User::factory()->create(['discord_id' => '11111111111111111']);
    fakeDiscord(discordAccount('11111111111111111'));

    $this->withSession(['url.intended' => $intended])->get(CALLBACK)->assertRedirect($expected);
})->with([
    'external' => ['https://evil.example/', '/'],
    'protocol relative' => ['//evil.example/users', '/'],
    'internal path' => ['/users', '/users'],
    'own absolute url' => ['http://localhost/users?x=1', '/users?x=1'],
    'own host lookalike' => ['http://localhost.evil.example/users', '/'],
]);

it('sends guests to /login and back to the remembered page afterwards', function () {
    User::factory()->create(['discord_id' => '11111111111111111']);
    fakeDiscord(discordAccount('11111111111111111'));

    $this->get('/')->assertRedirect('/login');
    $this->get(CALLBACK)->assertRedirect('/');
});

it('logs out on POST /logout and invalidates the session', function () {
    $this->actingAs(User::factory()->create())
        ->withSession(['marker' => 'x'])
        ->post('/logout')
        ->assertRedirect('/login')
        ->assertSessionMissing('marker');

    $this->assertGuest();
});

it('rejects POST /logout without a CSRF token', function () {
    $this->app['env'] = 'local';
    $user = User::factory()->create();

    $this->actingAs($user)->post('/logout')->assertStatus(419);

    $this->assertAuthenticatedAs($user);
});

it('does not log out on GET /logout', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/logout')->assertStatus(302)->assertHeader('Location', '/login');
    $this->assertAuthenticatedAs($user);
});

it('serves /auth/callback as an alias of the canonical callback', function () {
    $user = User::factory()->create(['discord_id' => '11111111111111111']);
    fakeDiscord(discordAccount('11111111111111111'));

    $this->get('/auth/callback?code=c&state=s')->assertRedirect('/');

    $this->assertAuthenticatedAs($user);
});

it('rejects a malformed Discord id', function () {
    registrationMode('open');
    fakeDiscord(discordAccount('not-a-snowflake'));

    $this->get(CALLBACK)->assertSessionHas('auth.error', 'auth.error.discord_failed');

    expect(User::count())->toBe(0);
});

it('shows the login page to guests and sends signed-in users home', function () {
    registrationMode('code');
    $this->get('/login')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Auth/Login')
        ->where('registrationMode', 'code')
        ->where('error', null)
        ->where('auth.user', null));

    $this->actingAs(User::factory()->create())->get('/login')->assertRedirect('/');
});

it('shares the signed-in user with role and permissions only', function () {
    $user = User::factory()->withRole(Role::findByName('TL'))->create(['name' => 'Max', 'username' => 'max']);

    $this->actingAs($user)->get('/')->assertInertia(fn (Assert $page) => $page
        ->where('auth.user', [
            'id' => $user->id,
            'name' => 'Max',
            'username' => 'max',
            'role' => ['name' => 'TL', 'color' => 'primary'],
            'personnel' => null,
            'is_super_admin' => false,
            'permissions' => ['personnel.documents.manage', 'personnel.view'],
        ]));
});

it('shares all catalog permissions for a super admin', function () {
    $user = User::factory()->superAdmin()->create();

    $this->actingAs($user)->get('/')->assertInertia(fn (Assert $page) => $page
        ->where('auth.user.is_super_admin', true)
        ->where('auth.user.role', null)
        ->where('auth.user.permissions', collect(Permissions::catalog())->keys()->sort()->values()->all()));
});
