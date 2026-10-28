<?php

use App\Install\InstallData;
use App\Install\Installer;
use App\Install\InstallState;
use App\Models\Setting;
use App\Models\User;
use App\Modules\ModuleRegistry;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function rename_migration(): object
{
    return require database_path('migrations/2026_10_20_100000_rename_org_name_to_system_name.php');
}

it('renames the org.name row to system.name, idempotently', function () {
    app(Settings::class)->set('org.name', 'Feuerwehr Alt');

    rename_migration()->up();
    rename_migration()->up();

    expect(Setting::pluck('key')->all())->toBe(['system.name'])
        ->and(app(Settings::class)->get('system.name'))->toBe('Feuerwehr Alt');
});

it('runs without any row', function () {
    rename_migration()->up();

    expect(Setting::count())->toBe(0);
});

it('keeps system.name when both rows exist', function () {
    app(Settings::class)->set('org.name', 'Alt');
    app(Settings::class)->set('system.name', 'Neu');

    rename_migration()->up();

    expect(Setting::pluck('key')->all())->toBe(['system.name'])
        ->and(app(Settings::class)->get('system.name'))->toBe('Neu');
});

it('lets the installer write system.name', function () {
    $this->mock(InstallState::class, function ($m) {
        $m->shouldReceive('isInstalled')->andReturnFalse();
        $m->shouldReceive('markInstalled');
    });
    $this->app->instance(ModuleRegistry::class, new ModuleRegistry(base_path('tests/Fixtures/modules'), app(Settings::class)));

    app(Installer::class)->run(InstallData::fromValidated([
        'orgName' => 'Feuerwehr Neu', 'discordClientId' => '123456789012345678', 'discordClientSecret' => 's',
        'adminDiscordId' => '98765432109876543', 'modules' => [], 'locale' => 'de',
    ]));

    expect(app(Settings::class)->get('system.name'))->toBe('Feuerwehr Neu')
        ->and(Setting::where('key', 'org.name')->exists())->toBeFalse();
});

it('shares system.name as the app name, else the configured name', function () {
    config(['app.name' => 'Fallback']);
    $this->get('/login')->assertInertia(fn (Assert $page) => $page->where('app.name', 'Fallback'));

    app(Settings::class)->set('system.name', 'Feuerwehr Neu');
    $this->get('/login')->assertInertia(fn (Assert $page) => $page->where('app.name', 'Feuerwehr Neu'));
});

it('gives accounts discord_dm on by default and keeps theme a free string column', function () {
    $user = User::factory()->create()->fresh();

    expect(Schema::hasColumn('users', 'discord_dm'))->toBeTrue()
        ->and($user->discord_dm)->toBeTrue()
        ->and($user->theme)->toBe('dark');

    $user->update(['discord_dm' => false, 'theme' => 'system']);
    expect($user->fresh()->discord_dm)->toBeFalse()->and($user->fresh()->theme)->toBe('system');
});
