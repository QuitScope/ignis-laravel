<?php

use App\Install\InstallState;
use App\Models\Setting;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->opts = [
        '--org' => 'Feuerwehr Test',
        '--discord-client-id' => '123456789012345678',
        '--discord-client-secret' => 'cli-secret',
        '--admin-discord-id' => '98765432109876543',
    ];
});

it('installs with all options', function () {
    $this->artisan('ignis:install', $this->opts)->assertExitCode(0);

    expect(app(InstallState::class)->isInstalled())->toBeTrue()
        ->and(app(Settings::class)->get('discord.client_secret'))->toBe('cli-secret')
        ->and(app(Settings::class)->get('app.locale'))->toBe('de');
});

it('fails with a hint when a required option is missing', function () {
    $options = $this->opts;
    unset($options['--admin-discord-id']);

    $this->artisan('ignis:install', $options)
        ->expectsOutputToContain('--admin-discord-id')
        ->assertExitCode(1);

    expect(Setting::count())->toBe(0);
});

it('refuses a second run', function () {
    $this->artisan('ignis:install', $this->opts)->assertExitCode(0);
    $before = Setting::orderBy('key')->get(['key', 'value'])->toArray();

    $this->artisan('ignis:install', [...$this->opts, '--org' => 'Other'])
        ->expectsOutputToContain('bereits installiert')
        ->assertExitCode(1);

    expect(Setting::orderBy('key')->get(['key', 'value'])->toArray())->toBe($before);
});

it('requires a matching token when one is configured', function () {
    config(['ignis.install_token' => 'tok-123']);

    $this->artisan('ignis:install', [...$this->opts, '--install-token' => 'nope'])->assertExitCode(1);
    expect(Setting::count())->toBe(0);

    $this->artisan('ignis:install', [...$this->opts, '--install-token' => 'tok-123'])->assertExitCode(0);
});
