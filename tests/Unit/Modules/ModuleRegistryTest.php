<?php

use App\Install\InstallState;
use App\Modules\ModuleInfo;
use App\Modules\ModuleRegistry;
use App\Support\Settings;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\Modules\Demo\DemoServiceProvider;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->mock(InstallState::class)->shouldReceive('isInstalled')->andReturnTrue();
    $this->registry = new ModuleRegistry(base_path('tests/Fixtures/modules'), app(Settings::class));
    $this->app->instance(ModuleRegistry::class, $this->registry);
});

it('discovers modules with a service provider and ignores other folders', function () {
    expect($this->registry->discover())->toBe([
        'Alpha' => 'Tests\Fixtures\Modules\Alpha\AlphaServiceProvider',
        'Beta' => 'Tests\Fixtures\Modules\Beta\BetaServiceProvider',
        'Demo' => 'Tests\Fixtures\Modules\Demo\DemoServiceProvider',
    ]);
});

it('describes every module by its lower-case folder name, with metadata defaults', function () {
    expect($this->registry->all())->toEqual([
        'alpha' => new ModuleInfo('alpha', 'Alpha', '', 'verwaltung', [], true),
        'beta' => new ModuleInfo('beta', 'Beta', '', 'verwaltung', [], false),
        'demo' => new ModuleInfo('demo', 'demo.label', 'demo.description', 'einsatz', ['beta', 'gamma'], false),
    ]);
});

it('scans a modules folder once per process', function () {
    $dir = sys_get_temp_dir().'/ignis_mod_'.uniqid();
    mkdir("$dir/One", 0777, true);
    file_put_contents("$dir/One/OneServiceProvider.php", '<?php namespace Tmp\One; class OneServiceProvider extends \App\Modules\ModuleProvider { public function moduleName(): string { return "One"; } }');

    try {
        expect(array_keys((new ModuleRegistry($dir, app(Settings::class)))->discover()))->toBe(['One']);
        mkdir("$dir/Two");
        file_put_contents("$dir/Two/TwoServiceProvider.php", '<?php namespace Tmp\Two; class TwoServiceProvider extends \App\Modules\ModuleProvider { public function moduleName(): string { return "Two"; } }');

        expect(array_keys((new ModuleRegistry($dir, app(Settings::class)))->discover()))->toBe(['One']);
        ModuleRegistry::flushDiscovery();
        expect(array_keys((new ModuleRegistry($dir, app(Settings::class)))->discover()))->toBe(['One', 'Two']);
    } finally {
        array_map('unlink', glob("$dir/*/*"));
        array_map('rmdir', glob("$dir/*"));
        rmdir($dir);
    }
});

it('takes the metadata from the providers it registered', function () {
    $this->registry->registerAll($this->app);

    expect($this->registry->all())->toHaveKeys(['alpha', 'beta', 'demo'])
        ->and($this->registry->all()['demo'])->toEqual(new ModuleInfo('demo', 'demo.label', 'demo.description', 'einsatz', ['beta', 'gamma'], false));
});

it('gates the scheduled events and listeners a module registers through its provider helpers', function () {
    $this->registry->registerAll($this->app);
    $tick = collect(app(Schedule::class)->events())->sole(fn ($e) => $e->description === 'demo-tick');
    DemoServiceProvider::$pings = 0;

    event('demo.ping');
    expect($tick->filtersPass($this->app))->toBeTrue()->and(DemoServiceProvider::$pings)->toBe(1);

    app(Settings::class)->set('modules.demo.enabled', false);
    event('demo.ping');
    expect($tick->filtersPass($this->app))->toBeFalse()->and(DemoServiceProvider::$pings)->toBe(1);
});

it('enables modules by default, honours the switch, keeps core modules on and knows no unknown module', function () {
    expect($this->registry->isEnabled('beta'))->toBeTrue()->and($this->registry->isEnabled('Beta'))->toBeTrue();

    app(Settings::class)->set('modules.beta.enabled', false);
    app(Settings::class)->set('modules.alpha.enabled', false);

    expect($this->registry->isEnabled('beta'))->toBeFalse()
        ->and($this->registry->isEnabled('alpha'))->toBeTrue()
        ->and($this->registry->isEnabled('gamma'))->toBeFalse()
        ->and($this->registry->switches())->toBe(['beta' => false, 'demo' => true])
        ->and(ModuleRegistry::active(null))->toBeTrue()
        ->and(ModuleRegistry::active('beta'))->toBeFalse()
        ->and((ModuleRegistry::whenEnabled('demo'))())->toBeTrue();
});

it('registers switched-off modules too and answers their routes with 404', function () {
    app(Settings::class)->set('modules.beta.enabled', false);
    $this->registry->registerAll($this->app);

    $this->get('/alpha')->assertOk();
    $this->get('/beta')->assertNotFound();

    app(Settings::class)->set('modules.beta.enabled', true);

    $this->get('/beta')->assertOk();
});

it('loads module translations', function () {
    $this->registry->registerAll($this->app);
    app()->setLocale('de');

    expect(__('Alpha greeting'))->toBe('Hallo von Alpha');
});

it('registers the migration paths of switched-off modules too', function () {
    $beta = 'tests/Fixtures/modules/Beta/database/migrations';
    $paths = fn () => array_map(fn ($p) => str_replace('\\', '/', substr($p, strlen(base_path()) + 1)), app('migrator')->paths());
    app(Settings::class)->set('modules.beta.enabled', false);
    $this->registry->registerAll($this->app);

    expect($paths())->toContain($beta);
});

it('does not throw when the settings table is missing', function () {
    Schema::drop('settings');
    Cache::flush();

    $this->registry->registerAll($this->app);

    expect($this->registry->isEnabled('beta'))->toBeTrue();
    $this->get('/beta')->assertOk();
});

it('does not load module route files when routes are cached', function () {
    $this->app->instance('routes.cached', true);

    $this->registry->registerAll($this->app);

    expect(collect(Route::getRoutes()->getRoutes())->map->uri()->all())->not->toContain('alpha', 'beta');
});

it('puts the module gate in front of the web group', function () {
    $this->registry->registerAll($this->app);

    expect(Route::getRoutes()->match(request()->create('/beta'))->middleware())->toBe(['module:beta', 'web']);
});

it('parses providers declared with modifiers, brace namespaces or without namespace', function () {
    $dir = sys_get_temp_dir().'/ignis_mod_'.uniqid();
    mkdir("$dir/Brace", 0777, true);
    mkdir("$dir/Plain");
    mkdir("$dir/Ro");
    file_put_contents("$dir/Brace/BraceServiceProvider.php", '<?php namespace Tmp\Brace { final class BraceServiceProvider extends \App\Modules\ModuleProvider { public function moduleName(): string { return "Brace"; } } }');
    file_put_contents("$dir/Plain/PlainServiceProvider.php", '<?php final class PlainTmpServiceProvider extends \App\Modules\ModuleProvider { public function moduleName(): string { return "Plain"; } }');
    file_put_contents("$dir/Ro/RoServiceProvider.php", '<?php namespace Tmp\Ro; $x = RoServiceProvider::class; final readonly class RoServiceProvider {}');

    try {
        expect((new ModuleRegistry($dir, app(Settings::class)))->discover())->toBe([
            'Brace' => 'Tmp\Brace\BraceServiceProvider',
            'Plain' => 'PlainTmpServiceProvider',
        ]);
    } finally {
        array_map('unlink', glob("$dir/*/*"));
        array_map('rmdir', glob("$dir/*"));
        rmdir($dir);
    }
});

it('throws when a provider file declares no class', function () {
    $dir = sys_get_temp_dir().'/ignis_mod_'.uniqid();
    mkdir("$dir/X", 0777, true);
    file_put_contents("$dir/X/XServiceProvider.php", '<?php return [];');

    try {
        expect(fn () => (new ModuleRegistry($dir, app(Settings::class)))->discover())
            ->toThrow(RuntimeException::class, 'XServiceProvider.php');
    } finally {
        unlink("$dir/X/XServiceProvider.php");
        rmdir("$dir/X");
        rmdir($dir);
    }
});

it('ignores abstract providers', function () {
    expect($this->registry->discover())->not->toHaveKey('Abstracto');
});
