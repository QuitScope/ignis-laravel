<?php

use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('defaults to de', function () {
    config(['app.locale' => null]);
    $this->actingAs(User::factory()->create())->get('/')->assertOk();

    expect(app()->getLocale())->toBe('de');
});

it('uses a supported configured locale', function () {
    config(['app.locale' => 'en']);
    $this->actingAs(User::factory()->create())->get('/')->assertOk();

    expect(app()->getLocale())->toBe('en');
});

it('falls back to de for an unsupported configured locale', function () {
    config(['app.locale' => 'xx']);
    $this->actingAs(User::factory()->create())->get('/')->assertOk();

    expect(app()->getLocale())->toBe('de');
});

it('uses the app.locale setting over config', function () {
    config(['app.locale' => 'de']);
    app(Settings::class)->set('app.locale', 'en');
    $this->actingAs(User::factory()->create())->get('/')->assertOk();

    expect(app()->getLocale())->toBe('en');
});

it('falls back to de for an unsupported app.locale setting', function () {
    config(['app.locale' => 'en']);
    app(Settings::class)->set('app.locale', 'xx');
    $this->actingAs(User::factory()->create())->get('/')->assertOk();

    expect(app()->getLocale())->toBe('de');
});

it('falls back to de for wrong-case or empty settings', function (mixed $value) {
    config(['app.locale' => 'en']);
    app(Settings::class)->set('app.locale', $value);
    $this->actingAs(User::factory()->create())->get('/')->assertOk();

    expect(app()->getLocale())->toBe('de');
})->with(['DE', '']);

it('uses config when no setting exists', function () {
    config(['app.locale' => 'en']);
    $this->actingAs(User::factory()->create())->get('/')->assertOk();

    expect(app()->getLocale())->toBe('en');
});

it('has identical keys in de.json and en.json', function () {
    $de = json_decode(file_get_contents(lang_path('de.json')), true, flags: JSON_THROW_ON_ERROR);
    $en = json_decode(file_get_contents(lang_path('en.json')), true, flags: JSON_THROW_ON_ERROR);

    expect(array_keys($en))->toEqualCanonicalizing(array_keys($de))->not->toBeEmpty();
});

it('treats a stored null locale like an unset one', function () {
    config(['app.locale' => 'en']);
    app(Settings::class)->set('app.locale', null);
    $this->actingAs(User::factory()->create())->get('/')->assertOk();

    expect(app()->getLocale())->toBe('en');
});
