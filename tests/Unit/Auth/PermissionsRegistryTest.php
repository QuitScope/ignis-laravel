<?php

use App\Auth\Permissions;
use App\Install\InstallState;
use App\Modules\ModuleRegistry;
use App\Providers\AppServiceProvider;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->mock(InstallState::class)->shouldReceive('isInstalled')->andReturnTrue();
    $modules = new ModuleRegistry(base_path('tests/Fixtures/modules'), app(Settings::class));
    $this->app->instance(ModuleRegistry::class, $modules);
    $modules->registerAll($this->app);
});

it('keeps the core permissions and groups', function () {
    expect(Permissions::catalog())->toHaveKey('admin', 'permissions.admin')
        ->and(Permissions::groups()['users'])->toBe(['users.view', 'users.edit', 'users.create', 'users.delete'])
        ->and(Permissions::known())->toContain('application.view', 'application.edit')
        ->and(Permissions::moduleOf('application.edit'))->toBe('forms')
        ->and(Permissions::groups())->not->toHaveKey('application')
        ->and(Permissions::moduleOf('admin'))->toBeNull();
});

it('registers core and module permissions with group and label', function () {
    Permissions::register('core.extra', 'other');
    Permissions::register('demo.extra', 'demo', 'Demo', 'demo.extra.label');

    expect(Permissions::catalog())->toMatchArray(['core.extra' => 'permissions.core.extra', 'demo.manage' => 'permissions.demo.manage', 'demo.extra' => 'demo.extra.label'])
        ->and(Permissions::groups()['demo'])->toBe(['demo.manage', 'demo.extra'])
        ->and(Permissions::groups()['other'])->toContain('core.extra')
        ->and(Permissions::moduleOf('demo.extra'))->toBe('demo')
        ->and(Permissions::moduleOf('nope'))->toBeNull();
});

it('hides the permissions of a switched-off module from catalog and groups, not from known()', function () {
    app(Settings::class)->set('modules.demo.enabled', false);

    expect(Permissions::catalog())->not->toHaveKey('demo.manage')
        ->and(Permissions::groups())->not->toHaveKey('demo')
        ->and(Permissions::known())->toContain('demo.manage', 'admin')
        ->and(Permissions::hidden('demo.manage'))->toBeTrue()
        ->and(Permissions::hidden('admin'))->toBeFalse()
        ->and(Permissions::hidden('legacy.custom'))->toBeFalse();
});

it('treats permissions of a module that is not installed as known but hidden', function () {
    Permissions::register('kb.edit', 'knowledgebase', 'knowledgebase');

    expect(Permissions::known())->toContain('kb.edit')
        ->and(Permissions::catalog())->not->toHaveKey('kb.edit')
        ->and(Permissions::hidden('kb.edit'))->toBeTrue();
});

it('ignores a repeated registration and throws on a conflicting one', function () {
    Permissions::register('demo.manage', 'demo', 'demo');
    expect(Permissions::groups()['demo'])->toBe(['demo.manage']);

    expect(fn () => Permissions::register('demo.manage', 'other', 'demo'))->toThrow(LogicException::class)
        ->and(fn () => Permissions::register('demo.manage', 'demo'))->toThrow(LogicException::class)
        ->and(fn () => Permissions::register('admin', 'users'))->toThrow(LogicException::class);
});

it('falls back to Europe/Berlin with a warning for an invalid organisation time zone', function () {
    Log::spy();
    config(['ignis.timezone' => 'Mars/Olympus']);
    AppServiceProvider::checkTimezone();
    expect(config('ignis.timezone'))->toBe('Europe/Berlin');
    Log::shouldHaveReceived('warning')->once();

    config(['ignis.timezone' => 'America/New_York']);
    AppServiceProvider::checkTimezone();
    expect(config('ignis.timezone'))->toBe('America/New_York');
});

it('defaults the organisation time zone to Europe/Berlin', function () {
    expect(config('ignis.timezone'))->toBe('Europe/Berlin');
});
