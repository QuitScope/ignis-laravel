<?php

use App\Auth\ApiKey;
use App\Models\Setting;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function () {
    Route::middleware(['api', 'api.key'])->get('/api/_test/key', fn () => response()->json([
        'ok' => true,
        'auth' => request()->attributes->get('api_auth'),
    ]));
    Route::middleware(['api', 'api.key'])->post('/api/_test/key', fn () => response()->json(['ok' => true]));
});

it('accepts the correct key in each of the four forms', function () {
    $key = app(ApiKey::class)->current();

    $this->getJson('/api/_test/key', ['X-API-Key' => $key])->assertOk()->assertJson(['ok' => true, 'auth' => 'key']);
    $this->getJson('/api/_test/key?api_key='.$key)->assertOk()->assertJson(['auth' => 'key']);
    $this->postJson('/api/_test/key', ['intraRP_API_Key' => $key])->assertOk();
    $this->postJson('/api/_test/key', ['api_key' => $key])->assertOk();
});

it('rejects a wrong or missing key with 403', function () {
    app(ApiKey::class)->current();

    $this->getJson('/api/_test/key', ['X-API-Key' => 'wrong'])->assertForbidden()
        ->assertExactJson(['success' => false, 'message' => 'Zugriff verweigert']);
    $this->getJson('/api/_test/key')->assertForbidden();
    $this->getJson('/api/_test/key', ['X-API-Key' => ''])->assertForbidden();
    $this->postJson('/api/_test/key', ['intraRP_API_Key' => ''])->assertForbidden();
});

it('treats non-string key values as missing', function () {
    $key = app(ApiKey::class)->current();

    $this->postJson('/api/_test/key', ['api_key' => [$key]])->assertForbidden();
    $this->postJson('/api/_test/key', ['intraRP_API_Key' => ['x' => $key]])->assertForbidden();
    $this->getJson('/api/_test/key?api_key[]='.$key)->assertForbidden();
});

it('ignores form-encoded bodies and cookies', function () {
    $key = app(ApiKey::class)->current();

    $this->post('/api/_test/key', ['api_key' => $key], ['Accept' => 'application/json'])->assertForbidden();
    $this->withUnencryptedCookie('api_key', $key)->getJson('/api/_test/key')->assertForbidden();
});

it('answers 503 when no key is configured, even if one is supplied, and never generates one', function () {
    $this->getJson('/api/_test/key', ['X-API-Key' => 'anything'])->assertStatus(503)
        ->assertExactJson(['success' => false, 'message' => 'API-Key nicht konfiguriert']);
    $this->getJson('/api/_test/key')->assertStatus(503);

    expect(Setting::where('key', 'api.key')->exists())->toBeFalse()
        ->and(app(ApiKey::class)->isConfigured())->toBeFalse();
});

it('treats an empty stored value as not configured', function () {
    app(Settings::class)->set('api.key', '', encrypted: true);

    $this->getJson('/api/_test/key', ['X-API-Key' => ''])->assertStatus(503);
    $this->postJson('/api/_test/key', ['intraRP_API_Key' => ''])->assertStatus(503);
    expect(app(ApiKey::class)->isConfigured())->toBeFalse()
        ->and(app(ApiKey::class)->matches(''))->toBeFalse();
});

it('never matches null or an empty string', function () {
    app(ApiKey::class)->current();

    expect(app(ApiKey::class)->matches(null))->toBeFalse()
        ->and(app(ApiKey::class)->matches(''))->toBeFalse();
});

it('stores the key encrypted at rest', function () {
    $key = app(ApiKey::class)->current();
    $row = Setting::where('key', 'api.key')->firstOrFail();

    expect(strlen($key))->toBe(48)
        ->and($row->is_encrypted)->toBeTrue()
        ->and($row->getRawOriginal('value'))->not->toContain($key)
        ->and(app(ApiKey::class)->current())->toBe($key);
});

it('invalidates the old key on regenerate', function () {
    $old = app(ApiKey::class)->current();
    $new = app(ApiKey::class)->regenerate();

    expect($new)->not->toBe($old)
        ->and(app(ApiKey::class)->matches($old))->toBeFalse()
        ->and(app(ApiKey::class)->matches($new))->toBeTrue();
    $this->getJson('/api/_test/key', ['X-API-Key' => $old])->assertForbidden();
    $this->getJson('/api/_test/key', ['X-API-Key' => $new])->assertOk();
});

it('throttles failed attempts per IP but never blocks a valid key', function () {
    $key = app(ApiKey::class)->current();

    for ($i = 0; $i < 30; $i++) {
        $this->getJson('/api/_test/key', ['X-API-Key' => 'wrong'])->assertForbidden();
    }

    $logged = 0;
    Event::listen(MessageLogged::class, function () use (&$logged) {
        $logged++;
    });
    $this->getJson('/api/_test/key', ['X-API-Key' => 'wrong'])->assertStatus(429)->assertHeader('Retry-After')
        ->assertExactJson(['success' => false, 'message' => 'Zu viele Versuche']);
    expect($logged)->toBe(0);

    $this->getJson('/api/_test/key', ['X-API-Key' => $key])->assertOk();
    $this->getJson('/api/_test/key', ['X-API-Key' => 'wrong'])->assertStatus(429);

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])
        ->getJson('/api/_test/key', ['X-API-Key' => 'wrong'])->assertForbidden();

    $this->travel(61)->seconds();
    $this->getJson('/api/_test/key', ['X-API-Key' => 'wrong'])->assertForbidden();
});

it('takes the first non-empty source: header wins over the body', function () {
    $key = app(ApiKey::class)->current();

    $this->postJson('/api/_test/key', ['intraRP_API_Key' => $key], ['X-API-Key' => 'wrong'])->assertForbidden();
    $this->postJson('/api/_test/key', ['intraRP_API_Key' => 'wrong'], ['X-API-Key' => $key])->assertOk();
});

it('never throttles successful calls', function () {
    $key = app(ApiKey::class)->current();

    for ($i = 0; $i < 60; $i++) {
        $this->getJson('/api/_test/key', ['X-API-Key' => $key])->assertOk();
    }
});

it('never writes the key to the log', function () {
    $key = app(ApiKey::class)->current();
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
        $logged[] = [$e->level, $e->message, $e->context];
    });

    $this->getJson('/api/_test/key?api_key='.$key)->assertOk();
    $this->getJson('/api/_test/key', ['X-API-Key' => $key])->assertOk();
    $this->getJson('/api/_test/key?api_key='.$key.'x')->assertForbidden();
    $this->postJson('/api/_test/key', ['intraRP_API_Key' => substr($key, 0, 40), 'payload' => 'secret-payload'])->assertForbidden();

    $dump = json_encode($logged);
    expect($dump)->not->toContain(substr($key, 0, 40))
        ->and($dump)->not->toContain('secret-payload')
        ->and($logged)->toHaveCount(2)
        ->and($logged[0])->toBe(['warning', 'API key rejected', ['ip' => '127.0.0.1', 'path' => 'api/_test/key']]);
});

it('prints a stable key from the command and replaces it with --regenerate', function () {
    $keyFromOutput = function (string $output): string {
        preg_match('/\b([A-Za-z0-9]{48})\b/', $output, $m);

        return $m[1] ?? '';
    };

    Artisan::call('ignis:api-key');
    $first = $keyFromOutput($out = Artisan::output());
    expect($first)->toHaveLength(48)
        ->and($out)->toContain('efbridge key ignis '.$first)
        ->and(app(ApiKey::class)->matches($first))->toBeTrue()
        ->and(Activity::count())->toBe(0);

    Artisan::call('ignis:api-key');
    expect($keyFromOutput(Artisan::output()))->toBe($first);

    Artisan::call('ignis:api-key', ['--regenerate' => true]);
    $second = $keyFromOutput(Artisan::output());
    expect($second)->toHaveLength(48)->not->toBe($first)
        ->and(app(ApiKey::class)->matches($first))->toBeFalse();

    $entry = Activity::sole();
    expect($entry->description)->toBe('API-Schlüssel neu erzeugt')
        ->and($entry->is_global)->toBeTruthy()
        ->and(json_encode($entry->getAttributes()))->not->toContain($first)
        ->and(json_encode($entry->getAttributes()))->not->toContain($second);
});

it('prints a hint and fails when the database is not set up yet', function () {
    Schema::drop('settings');

    $this->artisan('ignis:api-key')
        ->expectsOutputToContain('Die Datenbank ist noch nicht eingerichtet. Zuerst installieren.')
        ->assertFailed();
});
