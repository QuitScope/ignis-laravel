<?php

use App\Auth\ApiKey;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\LegacySeed;

uses(RefreshDatabase::class)->group('legacy');

const LEGACY_KEY = 'abcdef0123456789abcdef0123456789';

beforeEach(function () {
    if (! LegacySeed::boot()) {
        $this->markTestSkipped('LEGACY_TEST_DB_HOST not set');
    }
});

it('imports the api key encrypted, usable in the same process', function () {
    LegacySeed::config(['API_KEY' => LEGACY_KEY]);

    $json = LegacySeed::run(['auth_settings'], options: ['--import-api-key' => true]);

    $raw = DB::table('settings')->where('key', 'api.key')->first();
    expect(app(ApiKey::class)->matches(LEGACY_KEY))->toBeTrue()
        ->and((bool) $raw->is_encrypted)->toBeTrue()
        ->and($raw->value)->not->toContain(LEGACY_KEY)
        ->and($json['entities']['auth_settings']['failed'])->toBe(0);
});

it('rejects empty, placeholder and too short keys and keeps the existing one', function (string $value) {
    app(Settings::class)->set('api.key', 'existing-key-existing-key', encrypted: true);
    LegacySeed::config(['API_KEY' => $value]);

    $json = LegacySeed::run(['auth_settings'], options: ['--import-api-key' => true]);

    expect(app(Settings::class)->get('api.key'))->toBe('existing-key-existing-key')
        ->and(implode(' ', $json['warnings']))->toContain('API_KEY unbrauchbar, bitte mit ignis:api-key neu erzeugen');
})->with(['', 'CHANGE_ME', 'short-15-chars!']);

it('keeps a different existing key, warns without printing either key', function () {
    app(Settings::class)->set('api.key', 'existing-key-existing-key', encrypted: true);
    LegacySeed::config(['API_KEY' => LEGACY_KEY]);

    $json = LegacySeed::run(['auth_settings'], options: ['--import-api-key' => true]);

    $text = json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    expect(app(Settings::class)->get('api.key'))->toBe('existing-key-existing-key')
        ->and($text)->toContain('API_KEY im Altsystem weicht vom aktuellen Schlüssel ab, nicht überschrieben (ignis:api-key --regenerate bzw. manuell setzen)')
        ->and($text)->not->toContain(LEGACY_KEY)->not->toContain('existing-key-existing-key');
});

it('is silent when the existing key equals the legacy key', function () {
    app(Settings::class)->set('api.key', LEGACY_KEY, encrypted: true);
    LegacySeed::config(['API_KEY' => LEGACY_KEY]);

    $json = LegacySeed::run(['auth_settings'], options: ['--import-api-key' => true]);

    expect(implode(' ', $json['warnings']))->not->toContain('API_KEY');
});

it('keeps operator changes on re-run', function () {
    LegacySeed::config(['API_KEY' => LEGACY_KEY, 'REGISTRATION_MODE' => 'open', 'TABLET_LOGIN_ENABLED' => 'true']);
    LegacySeed::run(['auth_settings'], options: ['--import-api-key' => true]);
    $settings = app(Settings::class);
    $settings->set('api.key', 'operator-key-operator-key', encrypted: true);
    $settings->set('auth.registration_mode', 'closed');
    $settings->set('auth.tablet_login', false);
    $settings->set('discord.redirect_path', '/auth/discord/callback');

    $json = LegacySeed::run(['auth_settings'], options: ['--import-api-key' => true]);

    expect($settings->get('api.key'))->toBe('operator-key-operator-key')
        ->and($settings->get('auth.registration_mode'))->toBe('closed')
        ->and($settings->get('auth.tablet_login'))->toBeFalse()
        ->and($settings->get('discord.redirect_path'))->toBe('/auth/callback.php')
        ->and(implode(' ', $json['notes']))->toContain('kept')
        ->and($json['exit'])->toBe(0);

    $settings->set('discord.redirect_path', '/custom/callback');
    $again = LegacySeed::run(['auth_settings']);

    expect($settings->get('discord.redirect_path'))->toBe('/custom/callback')
        ->and(implode(' ', $again['notes']))->toContain('discord.redirect_path')->toContain('kept')
        ->and(implode(' ', $again['warnings']))->not->toContain('/auth/discord/callback');
});

it('discards an invalid registration mode and keeps the target value', function () {
    LegacySeed::config(['REGISTRATION_MODE' => 'banana']);
    app(Settings::class)->set('auth.registration_mode', 'code');

    $json = LegacySeed::run(['auth_settings']);

    expect(app(Settings::class)->get('auth.registration_mode'))->toBe('code')
        ->and(implode(' ', $json['warnings']))->toContain('banana');
});

it('imports a valid registration mode', function (string $mode) {
    LegacySeed::config(['REGISTRATION_MODE' => $mode]);

    LegacySeed::run(['auth_settings']);

    expect(app(Settings::class)->get('auth.registration_mode'))->toBe($mode);
})->with(['open', 'code', 'closed']);

it('parses the tablet login flag in every boolean spelling', function (string $value, bool $expected) {
    LegacySeed::config(['TABLET_LOGIN_ENABLED' => $value]);

    LegacySeed::run(['auth_settings']);

    expect(app(Settings::class)->get('auth.tablet_login'))->toBe($expected);
})->with([['true', true], ['1', true], ['yes', true], ['TRUE', true], ['On', true], ['ja', true],
    ['false', false], ['0', false], ['', false], ['NO', false], ['off', false], ['Nein', false]]);

it('discards a tablet login flag that is no boolean', function () {
    app(Settings::class)->set('auth.tablet_login', true);
    LegacySeed::config(['TABLET_LOGIN_ENABLED' => 'maybe']);

    $json = LegacySeed::run(['auth_settings']);

    expect(app(Settings::class)->get('auth.tablet_login'))->toBeTrue()
        ->and(implode(' ', $json['warnings']))->toContain('TABLET_LOGIN_ENABLED');
});

it('takes the api key only with --import-api-key', function () {
    LegacySeed::config(['API_KEY' => LEGACY_KEY]);

    $json = LegacySeed::run(['auth_settings']);

    expect(app(ApiKey::class)->isConfigured())->toBeFalse()
        ->and(implode(' ', $json['notes']))->toContain('--import-api-key')
        ->and(json_encode($json))->not->toContain(LEGACY_KEY);
});

it('says nothing about the api key without the flag when the stored key already equals it', function () {
    app(Settings::class)->set('api.key', LEGACY_KEY, encrypted: true);
    LegacySeed::config(['API_KEY' => LEGACY_KEY]);

    $json = LegacySeed::run(['auth_settings']);

    expect(implode(' ', $json['notes']).implode(' ', $json['warnings']))->not->toContain('API_KEY');
});

it('replaces a different api key with --import-api-key --overwrite-settings', function () {
    app(Settings::class)->set('api.key', 'existing-key-existing-key', encrypted: true);
    LegacySeed::config(['API_KEY' => LEGACY_KEY]);

    LegacySeed::run(['auth_settings'], options: ['--import-api-key' => true, '--overwrite-settings' => true]);

    expect(app(ApiKey::class)->matches(LEGACY_KEY))->toBeTrue();
});

it('replaces operator settings with --overwrite-settings', function () {
    app(Settings::class)->set('auth.registration_mode', 'closed');
    LegacySeed::config(['REGISTRATION_MODE' => 'open']);

    LegacySeed::run(['auth_settings'], options: ['--overwrite-settings' => true]);

    expect(app(Settings::class)->get('auth.registration_mode'))->toBe('open');
});

it('always points the redirect path at the legacy callback with one warning', function () {
    $json = LegacySeed::run(['auth_settings']);

    expect(app(Settings::class)->get('discord.redirect_path'))->toBe('/auth/callback.php')
        ->and(collect($json['warnings'])->filter(fn ($w) => str_contains($w, '/auth/discord/callback')))->toHaveCount(1);
});

it('leaves absent settings alone', function () {
    app(Settings::class)->set('auth.tablet_login', true);

    LegacySeed::run(['auth_settings']);

    expect(app(Settings::class)->get('auth.tablet_login'))->toBeTrue()
        ->and(DB::table('settings')->where('key', 'api.key')->exists())->toBeFalse();
});

it('is idempotent', function () {
    LegacySeed::config(['API_KEY' => LEGACY_KEY, 'REGISTRATION_MODE' => 'closed']);
    LegacySeed::run(['auth_settings'], options: ['--import-api-key' => true]);
    $count = DB::table('settings')->count();

    LegacySeed::run(['auth_settings'], options: ['--import-api-key' => true]);

    expect(DB::table('settings')->count())->toBe($count)
        ->and(app(ApiKey::class)->matches(LEGACY_KEY))->toBeTrue();
});

it('dry run leaves no trace and resets the settings cache', function () {
    LegacySeed::config(['API_KEY' => LEGACY_KEY, 'REGISTRATION_MODE' => 'closed']);

    $json = LegacySeed::run(['auth_settings'], dry: true, options: ['--import-api-key' => true]);

    expect(DB::table('settings')->count())->toBe(0)
        ->and(app(ApiKey::class)->isConfigured())->toBeFalse()
        ->and(app(Settings::class)->has('auth.registration_mode'))->toBeFalse()
        ->and($json['entities']['auth_settings']['written'])->toBeGreaterThan(0);
});
