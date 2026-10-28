<?php

use App\Models\Setting;
use App\Support\Settings;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->settings = app(Settings::class));

it('stores and returns structured values', function () {
    $this->settings->set('a.b', ['x' => 1]);

    expect($this->settings->get('a.b'))->toBe(['x' => 1])
        ->and($this->settings->has('a.b'))->toBeTrue();
});

it('returns the default for missing keys', function () {
    expect($this->settings->get('missing', 'dflt'))->toBe('dflt')
        ->and($this->settings->has('missing'))->toBeFalse();
});

it('encrypts secret values at rest', function () {
    $this->settings->set('secret', 'abc', true);

    expect(Setting::where('key', 'secret')->value('value'))->not->toContain('abc')
        ->and($this->settings->get('secret'))->toBe('abc');
});

it('serves repeated reads without extra queries', function () {
    $this->settings->set('k', 'v');
    $this->settings->get('k');

    DB::enableQueryLog();
    $this->settings->get('k');
    $this->settings->get('k');

    expect(DB::getQueryLog())->toBeEmpty();
});

it('forgets entries and invalidates the cache', function () {
    $this->settings->set('k', 'v');
    expect($this->settings->get('k'))->toBe('v');

    $this->settings->forget('k');

    expect($this->settings->get('k', 'gone'))->toBe('gone')
        ->and(Setting::count())->toBe(0);
});

it('returns null and warns when decryption fails under another app key', function () {
    $this->settings->set('secret', 'abc', true);
    Cache::flush();
    config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    app()->forgetInstance('encrypter');
    Crypt::clearResolvedInstance('encrypter');

    Log::spy();

    expect($this->settings->get('secret'))->toBeNull();
    Log::shouldHaveReceived('warning')->once();
});

it('degrades to defaults when the settings table is missing', function () {
    Schema::drop('settings');
    Cache::flush();
    Log::spy();

    expect($this->settings->get('x', 'dflt'))->toBe('dflt')
        ->and($this->settings->get('y', 'd2'))->toBe('d2')
        ->and($this->settings->has('x'))->toBeFalse();
    Log::shouldHaveReceived('warning')->once();
});

it('degrades to defaults when the database is unreachable', function () {
    $default = config('database.default');
    config([
        'database.connections.broken' => ['driver' => 'sqlite', 'database' => '/nonexistent/dir/db.sqlite'],
        'database.default' => 'broken',
    ]);
    Cache::flush();

    expect(app(Settings::class)->get('x', 'dflt'))->toBe('dflt');

    config(['database.default' => $default]);
});

it('reads the cache store only once per request', function () {
    $this->settings->set('a', 1);
    $this->settings->get('a');
    app()->forgetScopedInstances();

    $reads = 0;
    Event::listen([CacheHit::class, CacheMissed::class], function ($e) use (&$reads) {
        if ($e->key === 'settings.all') {
            $reads++;
        }
    });

    $this->settings->get('a');
    $this->settings->get('a');

    expect($reads)->toBe(1);
});

it('sees writes immediately despite the request memo', function () {
    $this->settings->set('x', 'old');
    expect($this->settings->get('x'))->toBe('old');

    $this->settings->set('x', 'v');
    expect($this->settings->get('x'))->toBe('v');

    $this->settings->forget('x');
    expect($this->settings->get('x', 'gone'))->toBe('gone');
});

it('does not return a stored null instead of the default', function () {
    $this->settings->set('x', null);

    expect($this->settings->get('x', 'd'))->toBe('d')
        ->and($this->settings->has('x'))->toBeTrue();

    $this->settings->set('x', 'v');
    expect($this->settings->get('x', 'd'))->toBe('v');
});
