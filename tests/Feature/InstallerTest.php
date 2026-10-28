<?php

use App\Install\AlreadyInstalled;
use App\Install\InstallData;
use App\Install\Installer;
use App\Install\InstallState;
use App\Install\Requirements;
use App\Models\DocumentTemplate;
use App\Models\Rank;
use App\Models\Setting;
use App\Modules\ModuleRegistry;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

const INSTALL_TEST_SECRET = 'sup3r-s3cret-value-xyz';

beforeEach(function () {
    $this->app->instance(ModuleRegistry::class, new ModuleRegistry(base_path('tests/Fixtures/modules'), app(Settings::class)));
    $this->valid = [
        'orgName' => 'Feuerwehr Test',
        'discordClientId' => '123456789012345678',
        'discordClientSecret' => INSTALL_TEST_SECRET,
        'adminDiscordId' => '98765432109876543',
        'modules' => ['Beta'],
        'locale' => 'en',
    ];
});

it('redirects to the installer while not installed', function () {
    $this->get('/')->assertRedirect('/install');
});

it('keeps health and api endpoints reachable while not installed', function (string $uri) {
    $this->get($uri)->assertOk();
})->with(['/up', '/healthz', '/api/version']);

it('shows the installer page with requirements', function () {
    $this->get('/install')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Install/Index')
            ->has('requirements.php')
            ->has('requirements.extensions')
            ->has('requirements.storage')
            ->where('requirements.database.ok', true)
            ->where('modules', ['Beta', 'Demo'])
            ->where('tokenRequired', false)
            ->where('redirectUri', rtrim(config('app.url'), '/').'/auth/discord/callback'));
});

it('installs with valid input and stores all settings', function () {
    $this->post('/install', $this->valid)->assertRedirect('/');

    $s = app(Settings::class);
    expect($s->get('system.name'))->toBe('Feuerwehr Test')
        ->and($s->get('discord.client_id'))->toBe('123456789012345678')
        ->and($s->get('discord.client_secret'))->toBe(INSTALL_TEST_SECRET)
        ->and($s->get('install.admin_discord_id'))->toBe('98765432109876543')
        ->and($s->get('modules.beta.enabled'))->toBeTrue()
        ->and($s->get('modules.demo.enabled'))->toBeFalse()
        ->and($s->has('modules.alpha.enabled'))->toBeFalse()
        ->and($s->get('app.locale'))->toBe('en')
        ->and(app(InstallState::class)->isInstalled())->toBeTrue();

    $raw = Setting::where('key', 'discord.client_secret')->first();
    expect($raw->value)->not->toContain(INSTALL_TEST_SECRET)
        ->and($raw->is_encrypted)->toBeTrue()
        ->and(Setting::where('is_encrypted', true)->pluck('key')->all())->toBe(['discord.client_secret']);

    $this->get('/')->assertRedirect('/login');
    $this->get('/login')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Auth/Login'));
});

it('rejects invalid input and saves nothing', function (string $field, mixed $value) {
    $this->post('/install', [...$this->valid, $field => $value])->assertSessionHasErrors($field);

    expect(Setting::count())->toBe(0);
})->with([
    'admin id not numeric' => ['adminDiscordId', '1234567890123456a'],
    'admin id too short' => ['adminDiscordId', '1234567890123456'],
    'admin id too long' => ['adminDiscordId', '123456789012345678901'],
    'client id not numeric' => ['discordClientId', 'abc'],
    'org missing' => ['orgName', ''],
    'org too long' => ['orgName', str_repeat('x', 101)],
    'secret missing' => ['discordClientSecret', ''],
    'unknown module' => ['modules', ['Gamma']],
    'core module' => ['modules', ['Alpha']],
    'dependency not chosen' => ['modules', ['Demo']],
    'unsupported locale' => ['locale', 'fr'],
]);

it('accepts a module together with its dependency', function () {
    $this->post('/install', [...$this->valid, 'modules' => ['Demo', 'Beta']])->assertRedirect('/');

    expect(app(Settings::class)->get('modules.demo.enabled'))->toBeTrue();
});

it('accepts an empty module list', function () {
    $this->post('/install', [...$this->valid, 'modules' => []])->assertRedirect('/');

    expect(app(Settings::class)->get('modules.beta.enabled'))->toBeFalse();
});

it('is closed after installation and a second run changes nothing', function () {
    $this->post('/install', $this->valid)->assertRedirect('/');
    $before = Setting::orderBy('key')->get(['key', 'value'])->toArray();

    $this->get('/install')->assertNotFound();
    $this->post('/install', [...$this->valid, 'orgName' => 'Evil'])->assertNotFound();
    expect(fn () => app(Installer::class)->run(new InstallData('Evil', '123456789012345678', 'x', '98765432109876543', [])))
        ->toThrow(AlreadyInstalled::class);

    expect(Setting::orderBy('key')->get(['key', 'value'])->toArray())->toBe($before);
});

it('rolls back everything when a write fails midway', function () {
    Setting::saving(fn (Setting $s) => $s->key === 'app.locale' ? throw new RuntimeException('boom') : null);

    expect(fn () => app(Installer::class)->run(new InstallData('Org', '123456789012345678', 'x', '98765432109876543', ['Alpha'])))
        ->toThrow(RuntimeException::class, 'boom');

    Cache::flush();
    expect(Setting::count())->toBe(0)
        ->and(app(InstallState::class)->isInstalled())->toBeFalse();
});

it('serves the installer with database sessions configured while the database is unreachable', function () {
    $default = config('database.default');
    config(['session.driver' => 'database']);
    config(['database.connections.broken' => ['driver' => 'sqlite', 'database' => base_path('nonexistent/dir/x.sqlite'), 'prefix' => '']]);
    config(['database.default' => 'broken']);
    DB::purge('broken');
    Cache::flush();

    try {
        $this->get('/install')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Install/Index')
                ->where('requirements.database.ok', false)
                ->where('requirements.database.detail', 'Nicht verfügbar'));

        $this->get('/')->assertRedirect('/install');
        $this->followingRedirects()->get('/')->assertOk();
    } finally {
        config(['database.default' => $default]);
        DB::purge('broken');
    }
});

it('switches to file sessions while not installed', function () {
    config(['session.driver' => 'database']);

    $this->get('/install')->assertOk();

    expect(config('session.driver'))->toBe('file');
});

it('leaves the configured session driver alone once installed', function () {
    app(InstallState::class)->markInstalled();
    config(['session.driver' => 'database']);

    $this->get('/login')->assertOk();

    expect(config('session.driver'))->toBe('database');
});

it('translates failing requirement details', function () {
    app()->setLocale('de');
    $r = Requirements::check(['ignis_no_such_ext'], ['nowhere' => base_path('nonexistent/dir')]);

    expect($r['extensions'])->toBe(['ok' => false, 'detail' => 'Fehlt: ignis_no_such_ext'])
        ->and($r['storage'])->toBe(['ok' => false, 'detail' => 'Nicht beschreibbar: nowhere']);

    foreach ($r as $check) {
        expect($check['detail'])->not->toContain('missing')->not->toContain('not writable')->not->toContain('unavailable');
    }
});

it('reports not installed instead of throwing when the settings table is missing', function () {
    Schema::drop('settings');
    Cache::flush();

    expect(app(InstallState::class)->isInstalled())->toBeFalse();
});

describe('with install token', function () {
    beforeEach(fn () => config(['ignis.install_token' => 'tok-123']));

    it('tells the page a token is required and warns otherwise', function () {
        $this->get('/install')->assertInertia(fn (Assert $page) => $page->where('tokenRequired', true));
    });

    it('rejects a missing or wrong token and saves nothing', function (?string $token) {
        $this->post('/install', [...$this->valid, 'installToken' => $token])->assertSessionHasErrors('installToken');

        expect(Setting::count())->toBe(0)
            ->and(session()->getOldInput('installToken'))->toBeNull()
            ->and(session()->getOldInput('discordClientSecret'))->toBeNull()
            ->and(session()->getOldInput('orgName'))->toBe('Feuerwehr Test');
    })->with([null, 'wrong']);

    it('installs with the right token and never stores it', function () {
        $this->post('/install', [...$this->valid, 'installToken' => 'tok-123'])->assertRedirect('/');

        expect(Setting::where('value', 'like', '%tok-123%')->exists())->toBeFalse()
            ->and(app(InstallState::class)->isInstalled())->toBeTrue();
    });
});

describe('with a whitespace-only install token', function () {
    beforeEach(fn () => config(['ignis.install_token' => ' ']));

    it('treats it as not configured', function () {
        $this->get('/install')->assertInertia(fn (Assert $page) => $page->where('tokenRequired', false));

        $this->post('/install', $this->valid)->assertRedirect('/');
        expect(app(InstallState::class)->isInstalled())->toBeTrue();
    });
});

it('seeds the personnel defaults while installing', function () {
    app(Installer::class)->run(new InstallData('Org', '123456789012345678', 'x', '98765432109876543', ['Alpha']));

    expect(Rank::count())->toBe(19);
});

it('seeds the system document templates while installing', function () {
    app(Installer::class)->run(new InstallData('Org', '123456789012345678', 'x', '98765432109876543', ['Alpha']));

    expect(DocumentTemplate::count())->toBe(10);
});
