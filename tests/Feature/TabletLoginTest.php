<?php

use App\Auth\ApiKey;
use App\Http\Controllers\TabletLoginController;
use App\Models\TabletLoginToken;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

const TABLET_DISCORD_ID = '123456789012345678';

beforeEach(function () {
    $this->key = app(ApiKey::class)->current();
    app(Settings::class)->set('auth.tablet_login', true);
    $this->user = User::factory()->create(['discord_id' => TABLET_DISCORD_ID]);
});

function issueToken(mixed $discordId = TABLET_DISCORD_ID, ?string $key = null): TestResponse
{
    return test()->postJson('/api/tablet/login-token', ['discord_id' => $discordId], ['X-API-Key' => $key ?? test()->key]);
}

function failureShape(TestResponse $r): array
{
    return [
        $r->getStatusCode(),
        $r->headers->get('Location'),
        $r->headers->get('Cache-Control'),
        $r->headers->get('Referrer-Policy'),
        session('auth.error'),
    ];
}

// ----- POST /api/tablet/login-token -----

it('issues a one-time token for an active account', function () {
    $r = issueToken()->assertOk();

    $token = $r->json('token');
    expect($r->json())->toBe(['success' => true, 'token' => $token, 'expires_in' => 60, 'login_url' => 'http://localhost/auth/tablet'])
        ->and($token)->toMatch('/^[A-Za-z0-9_-]{43}$/')
        ->and($r->headers->get('Cache-Control'))->toContain('no-store');

    $row = TabletLoginToken::sole();
    expect($row->user_id)->toBe($this->user->id)
        ->and($row->token_hash)->toBe(hash('sha256', $token))
        ->and($row->used_at)->toBeNull()
        ->and($row->expires_at->timestamp)->toBe(now()->addSeconds(60)->timestamp)
        ->and(json_encode(DB::table('tablet_login_tokens')->get()))->not->toContain($token)
        ->and($r->getContent())->not->toContain($row->token_hash);
});

it('builds login_url with the https scheme behind a trusted proxy', function () {
    config(['trustedproxy.proxies' => '*']);

    $this->postJson('/api/tablet/login-token', ['discord_id' => TABLET_DISCORD_ID], ['X-API-Key' => $this->key, 'X-Forwarded-Proto' => 'https'])
        ->assertOk()->assertJsonPath('login_url', 'https://localhost/auth/tablet');
});

it('answers 404 disabled when tablet login is off, after the key check', function (mixed $value) {
    app(Settings::class)->set('auth.tablet_login', $value);

    issueToken()->assertNotFound()->assertExactJson(['success' => false, 'error' => 'disabled']);
    issueToken(key: 'wrong')->assertForbidden()->assertJsonPath('message', 'Zugriff verweigert');
    expect(TabletLoginToken::count())->toBe(0);
})->with([false, 'true', 1]);

it('answers 404 disabled when the setting was never set', function () {
    app(Settings::class)->forget('auth.tablet_login');

    issueToken()->assertNotFound()->assertJsonPath('error', 'disabled');
});

it('lets the api key middleware reject a missing or wrong key with 403', function () {
    $this->postJson('/api/tablet/login-token', ['discord_id' => TABLET_DISCORD_ID])->assertForbidden()
        ->assertExactJson(['success' => false, 'message' => 'Zugriff verweigert']);
    issueToken(key: 'wrong')->assertForbidden();
    expect(TabletLoginToken::count())->toBe(0);
});

it('answers 503 from the middleware when no key is configured', function () {
    app(Settings::class)->forget('api.key');

    issueToken()->assertStatus(503);
});

it('requires key authentication even if the middleware is bypassed', function () {
    Route::middleware('api')->post('/api/_test/tablet', [TabletLoginController::class, 'issue']);

    $this->postJson('/api/_test/tablet', ['discord_id' => TABLET_DISCORD_ID])->assertForbidden()
        ->assertExactJson(['success' => false, 'error' => 'api_key_required']);
    expect(TabletLoginToken::count())->toBe(0);
});

it('rejects an invalid discord id with 422', function (mixed $id) {
    issueToken($id)->assertUnprocessable()->assertExactJson(['success' => false, 'error' => 'invalid_discord_id']);
})->with([
    'letters' => 'abc',
    '16 digits' => '1234567890123456',
    '21 digits' => '123456789012345678901',
    'newline inside' => "123456789\n012345678",
    'sign' => '+'.TABLET_DISCORD_ID,
    'json integer' => 123456789012345678,
    'array' => [[TABLET_DISCORD_ID]],
    'null' => null,
]);

it('rejects a missing discord id with 422', function () {
    $this->postJson('/api/tablet/login-token', [], ['X-API-Key' => $this->key])->assertUnprocessable()
        ->assertJsonPath('error', 'invalid_discord_id');
});

it('answers 404 unknown_user for unknown and for deactivated accounts alike', function () {
    $unknown = issueToken('99999999999999999')->assertNotFound();
    $this->user->forceFill(['is_active' => false])->save();
    $inactive = issueToken()->assertNotFound();

    expect($unknown->json())->toBe(['success' => false, 'error' => 'unknown_user'])
        ->and($inactive->json())->toBe($unknown->json())
        ->and(TabletLoginToken::count())->toBe(0);
});

it('allows 10 tokens per user and minute, the 11th gets 429 with Retry-After, regardless of IP', function () {
    for ($i = 1; $i <= 10; $i++) {
        $this->withServerVariables(['REMOTE_ADDR' => "10.0.0.$i"]);
        issueToken()->assertOk();
    }

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.99']);
    issueToken()->assertStatus(429)->assertHeader('Retry-After', '60')
        ->assertExactJson(['success' => false, 'error' => 'rate_limited']);
    expect(TabletLoginToken::count())->toBe(10);

    $other = User::factory()->create();
    issueToken($other->discord_id)->assertOk();

    $this->travel(61)->seconds();
    issueToken()->assertOk();
});

it('deletes expired tokens when issuing', function () {
    issueToken()->assertOk();
    issueToken()->assertOk();
    $this->travel(2)->minutes();

    $other = User::factory()->create();
    issueToken($other->discord_id)->assertOk();

    expect(TabletLoginToken::pluck('user_id')->all())->toBe([$other->id]);
});

// ----- GET /auth/tablet -----

it('signs in with a valid token, regenerates the session and audits', function () {
    $token = issueToken()->json('token');
    session()->start();
    $before = session()->getId();

    $r = $this->withCookie(config('session.cookie'), $before)->get('/auth/tablet?token='.$token);

    $r->assertRedirect('/')->assertHeader('Referrer-Policy', 'no-referrer');
    expect($r->headers->get('Cache-Control'))->toContain('no-store')
        ->and($r->headers->get('Location'))->not->toContain($token)
        ->and(session()->getId())->not->toBe($before);
    $this->assertAuthenticatedAs($this->user);
    expect(TabletLoginToken::sole()->used_at)->not->toBeNull();

    $entry = Activity::where('description', 'Anmeldung über ef_bridge')->sole();
    expect($entry->causer_id)->toBe($this->user->id)
        ->and($entry->log_name)->toBe('System')
        ->and($entry->properties->toArray())->toBe(['context' => ['user_id' => $this->user->id]])
        ->and(json_encode($entry->getAttributes()))->not->toContain($token)
        ->and(json_encode($entry->getAttributes()))->not->toContain(hash('sha256', $token));
});

it('answers HEAD and link-preview bots with 204 without burning the token', function () {
    $token = issueToken()->json('token');

    $this->call('HEAD', '/auth/tablet?token='.$token)->assertNoContent();
    $this->withHeader('User-Agent', 'Mozilla/5.0 (compatible; Discordbot/2.0; +https://discordapp.com)')
        ->get('/auth/tablet?token='.$token)->assertNoContent();
    $this->assertGuest();
    expect(TabletLoginToken::sole()->used_at)->toBeNull();

    $this->withHeader('User-Agent', 'Mozilla/5.0')->get('/auth/tablet?token='.$token)->assertRedirect('/');
    $this->assertAuthenticatedAs($this->user);
});

it('drops the previous session data when another user redeems a token', function () {
    $token = issueToken()->json('token');
    $previous = User::factory()->create();

    $this->actingAs($previous)->withSession(['carried' => 'A']);
    $before = session()->getId();
    $this->get('/auth/tablet?token='.$token)->assertRedirect('/');

    $this->assertAuthenticatedAs($this->user);
    expect(session()->has('carried'))->toBeFalse()->and(session()->getId())->not->toBe($before);
});

it('keeps the session, its id and the CSRF token when the same user redeems a token (second frame)', function () {
    $token = issueToken()->json('token');

    $this->actingAs($this->user)->withSession(['carried' => 'A'])->get('/');
    [$id, $csrf] = [session()->getId(), session()->token()];
    $this->withCookie(config('session.cookie'), $id)->get('/auth/tablet?token='.$token)->assertRedirect('/');

    expect(session('carried'))->toBe('A')->and(session()->getId())->toBe($id)->and(session()->token())->toBe($csrf);
});

it('leaves expires_at unchanged when a token is redeemed', function () {
    $token = issueToken()->json('token');
    $expires = DB::table('tablet_login_tokens')->value('expires_at');
    $this->travel(5)->seconds();

    expect(TabletLoginToken::redeem($token))->toBe($this->user->id)
        ->and(DB::table('tablet_login_tokens')->value('expires_at'))->toBe($expires);
});

it('does not sign in with the remember cookie', function () {
    $token = issueToken()->json('token');

    $this->get('/auth/tablet?token='.$token)->assertRedirect('/');

    expect($this->user->fresh()->remember_token)->toBeNull();
});

it('rejects a replayed token', function () {
    $token = issueToken()->json('token');
    $this->get('/auth/tablet?token='.$token)->assertRedirect('/');
    auth()->logout();

    $this->get('/auth/tablet?token='.$token)->assertRedirect('/login')
        ->assertSessionHas('auth.error', 'auth.error.tablet_failed');
    $this->assertGuest();
});

it('rejects an expired token exactly like a forged one', function () {
    $token = issueToken()->json('token');
    $this->travel(61)->seconds();

    $expired = $this->get('/auth/tablet?token='.$token);
    $expiredShape = failureShape($expired);
    $forged = $this->get('/auth/tablet?token='.str_repeat('A', 43));

    $expired->assertRedirect('/login')->assertHeader('Referrer-Policy', 'no-referrer');
    expect($expiredShape)->toBe(failureShape($forged))
        ->and($expiredShape[2])->toContain('no-store')
        ->and($expiredShape[4])->toBe('auth.error.tablet_failed')
        ->and(TabletLoginToken::sole()->used_at)->toBeNull();
    $this->assertGuest();
});

it('rejects malformed tokens the same way', function (string $query) {
    $r = $this->get('/auth/tablet'.$query);

    expect(failureShape($r))->toBe([302, 'http://localhost/login', 'no-store, private', 'no-referrer', 'auth.error.tablet_failed']);
    $this->assertGuest();
})->with([
    'missing' => '',
    'empty' => '?token=',
    'array' => '?token[]=abc',
    'too long' => '?token='.str_repeat('A', 129),
]);

it('rejects the token of an account deactivated after issuing', function () {
    $token = issueToken()->json('token');
    $this->user->forceFill(['is_active' => false])->save();

    $r = $this->get('/auth/tablet?token='.$token);

    expect(failureShape($r))->toBe([302, 'http://localhost/login', 'no-store, private', 'no-referrer', 'auth.error.tablet_failed']);
    $this->assertGuest();
    expect(Activity::where('description', 'Anmeldung über ef_bridge')->exists())->toBeFalse();
});

it('answers 404 on /auth/tablet when tablet login is off', function () {
    $token = issueToken()->json('token');
    app(Settings::class)->set('auth.tablet_login', false);

    $this->get('/auth/tablet?token='.$token)->assertNotFound();
    $this->assertGuest();
    expect(TabletLoginToken::sole()->used_at)->toBeNull();
});

it('throttles /auth/tablet to 30 requests per minute', function () {
    for ($i = 0; $i < 30; $i++) {
        $this->get('/auth/tablet?token=x')->assertRedirect('/login');
    }

    $this->get('/auth/tablet?token=x')->assertStatus(429);
});

it('redeems a token once: the second claim gets nothing', function () {
    $token = issueToken()->json('token');

    expect(TabletLoginToken::redeem($token))->toBe($this->user->id)
        ->and(TabletLoginToken::redeem($token))->toBeNull();
});

it('redeems with a single conditional UPDATE', function () {
    $token = issueToken()->json('token');

    DB::enableQueryLog();
    TabletLoginToken::redeem($token);
    $updates = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $q) => str_starts_with(strtolower($q), 'update'));

    expect($updates)->toHaveCount(1);
    $sql = str_replace(['"', '`'], '', strtolower($updates->first()));
    expect($sql)->toContain('token_hash = ?')->toContain('used_at is null')->toContain('expires_at > ?');
});

it('never logs the token', function () {
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
        $logged[] = $e->message.json_encode($e->context);
    });

    $token = issueToken()->json('token');
    $this->get('/auth/tablet?token='.$token);
    $this->get('/auth/tablet?token='.$token);
    $this->get('/auth/tablet?token=forged-'.$token);

    expect(implode("\n", $logged))->not->toContain($token);
});

it('sends the FiveM webview to a fivem route after sign-in, normal browsers to /', function () {
    $token = issueToken()->json('token');
    $this->withHeader('User-Agent', 'Mozilla/5.0 CitizenFX/1.0')->get('/auth/tablet?token='.$token)->assertRedirect('/firetab');

    auth()->logout();
    $this->flushSession();
    $token = issueToken()->json('token');
    $this->withHeader('User-Agent', 'Mozilla/5.0')->get('/auth/tablet?token='.$token)->assertRedirect('/');
});

it('sends the FiveM webview to the tablet named by `to`, ignoring anything off the allowlist', function (string $query, string $target) {
    $token = issueToken()->json('token');
    $this->withHeader('User-Agent', 'Mozilla/5.0 CitizenFX/1.0')->get('/auth/tablet?token='.$token.$query)->assertRedirect($target);
})->with([
    ['&to=enotf', '/enotf'],
    ['&to=firetab', '/firetab'],
    ['&to=//evil.example', '/firetab'],
    ['&to=ENOTF', '/firetab'],
    ['&to[]=enotf', '/firetab'],
]);
