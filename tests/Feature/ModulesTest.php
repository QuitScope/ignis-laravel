<?php

use App\Dashboard\DashboardWidgets;
use App\Models\Role;
use App\Models\User;
use App\Modules\ModuleRegistry;
use App\Notifications\InboxNotification;
use App\Notifications\NotificationKinds;
use App\Search\SearchRegistry;
use App\Support\Settings;
use Database\Seeders\RoleSeeder;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\Fixtures\Modules\Demo\DemoServiceProvider;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->modules = new ModuleRegistry(base_path('tests/Fixtures/modules'), app(Settings::class));
    $this->app->instance(ModuleRegistry::class, $this->modules);
    $this->modules->registerAll($this->app);
    $this->app->make(ConsoleKernel::class)->setArtisan(null);
    DemoServiceProvider::$pings = 0;
    config(['app.debug' => false]);
});

function modUser(string ...$perms): User
{
    $role = Role::create(['name' => 'M'.uniqid(), 'guard_name' => 'web', 'priority' => 500, 'color' => 'info']);
    $role->syncPermissions($perms);

    return User::factory()->withRole($role)->create();
}

function demoTick(): Event
{
    return collect(app(Schedule::class)->events())->sole(fn (Event $e) => $e->description === 'demo-tick');
}

/** @return list<string> keys of the widgets $user gets */
function widgetKeys(User $user): array
{
    return collect(app(DashboardWidgets::class)->allowedFor($user))->flatten()->map->key()->all();
}

it('switches a module off and on again within one process, without restart or route cache', function () {
    $admin = modUser('admin');
    $user = modUser();
    $user->notify(new InboxNotification('demo_ping', 'Ping', null, null));

    $this->actingAs($user)->get('/demo/ping')->assertOk()->assertSee('pong');
    $this->getJson('/api/demo/ping')->assertOk()->assertExactJson(['pong' => true]);
    $this->actingAs($user)->get('/inbox')->assertInertia(fn (Assert $p) => $p->where('app.modules', ['beta', 'demo'])->where('notifications.unread', 1));
    event('demo.ping');
    $this->artisan('demo:hello')->expectsOutput('hallo')->assertSuccessful();
    expect(DemoServiceProvider::$pings)->toBe(1);
    expect(collect(app(SearchRegistry::class)->sources())->map->key())->toContain('demo')
        ->and(app(SearchRegistry::class)->search($user, 'demo')['scopes'])->toContain(['key' => 'demo', 'label' => 'Demo'])
        ->and(widgetKeys($user))->toContain('demo')
        ->and(app(NotificationKinds::class)->allowed($user))->toContain('demo_ping')
        ->and(app(NotificationKinds::class)->visibleTo($user)->count())->toBe(1)
        ->and(demoTick()->filtersPass($this->app))->toBeTrue();
    $this->actingAs($admin)->get('/settings/system/config')->assertInertia(fn (Assert $p) => $p->where('tabs', fn ($tabs) => collect($tabs)->contains('demo')));

    app(Settings::class)->set('demo.greeting', 'Moin');
    $this->actingAs($admin)->put('/settings/system/modules', ['modules' => ['demo' => false]])
        ->assertRedirect('/settings/system/modules')->assertSessionHas('status', 'settings.modules.saved');

    expect(app(Settings::class)->get('modules.demo.enabled'))->toBeFalse();
    $this->actingAs($user)->get('/demo/ping')->assertNotFound();
    $this->actingAs($user)->get('/inbox')->assertInertia(fn (Assert $p) => $p->where('app.modules', ['beta'])->where('notifications.unread', 0));
    event('demo.ping');
    $this->artisan('demo:hello')->expectsOutput('Modul demo ist deaktiviert')->doesntExpectOutput('hallo')->assertSuccessful();
    expect(DemoServiceProvider::$pings)->toBe(1);
    expect(collect(app(SearchRegistry::class)->sources())->map->key())->not->toContain('demo')
        ->and(collect(app(SearchRegistry::class)->search($user, 'demo')['scopes'])->pluck('key'))->not->toContain('demo')
        ->and(widgetKeys($user))->not->toContain('demo')
        ->and(app(NotificationKinds::class)->allowed($user))->not->toContain('demo_ping')
        ->and(app(NotificationKinds::class)->visibleTo($user)->count())->toBe(0)
        ->and(demoTick()->filtersPass($this->app))->toBeFalse();
    $this->actingAs($admin)->get('/settings/system/config')->assertInertia(fn (Assert $p) => $p->where('tabs', fn ($tabs) => ! collect($tabs)->contains('demo')));
    $this->actingAs($admin)->put('/settings/system/config/demo', ['demo.greeting' => 'Hi'])->assertNotFound();
    expect(app(Settings::class)->get('demo.greeting'))->toBe('Moin');

    $this->actingAs($admin)->put('/settings/system/modules', ['modules' => ['demo' => true]])->assertRedirect('/settings/system/modules');

    $this->actingAs($user)->get('/demo/ping')->assertOk();
    event('demo.ping');
    expect(DemoServiceProvider::$pings)->toBe(2)
        ->and(widgetKeys($user))->toContain('demo')
        ->and(app(NotificationKinds::class)->visibleTo($user)->count())->toBe(1)
        ->and(demoTick()->filtersPass($this->app))->toBeTrue();
});

it('answers a switched-off module exactly like a missing route (web for guests, API as JSON)', function () {
    app(Settings::class)->set('modules.demo.enabled', false);

    $off = $this->get('/demo/ping');
    $missing = $this->get('/demo/missing');
    $off->assertNotFound();
    expect($off->getContent())->toBe($missing->getContent())
        ->and($off->headers->has('set-cookie'))->toBe($missing->headers->has('set-cookie'))->toBeFalse();

    $this->getJson('/api/demo/ping')->assertNotFound()->assertExactJson(['message' => 'The route api/demo/ping could not be found.']);
    $this->getJson('/api/demo/missing')->assertNotFound()->assertExactJson(['message' => 'The route api/demo/missing could not be found.']);
});

it('answers a wrong method or OPTIONS on a switched-off module like a missing route', function () {
    $this->call('OPTIONS', '/demo/ping')->assertOk();
    $this->post('/demo/ping')->assertStatus(405);

    app(Settings::class)->set('modules.demo.enabled', false);

    $this->call('OPTIONS', '/demo/ping')->assertNotFound();
    $this->post('/demo/ping')->assertNotFound();
    $this->postJson('/api/demo/ping')->assertNotFound()->assertExactJson(['message' => 'The route api/demo/ping could not be found.']);
    $this->post('/up')->assertStatus(405);
});

it('tells guests nothing about modules and users only which ones are on', function () {
    app(Settings::class)->set('modules.demo.enabled', false);

    $this->get('/login')->assertInertia(fn (Assert $p) => $p->where('app.modules', []));
    $this->actingAs(modUser())->get('/inbox')->assertInertia(fn (Assert $p) => $p->where('app.modules', ['beta']));
});

it('puts the module gate on every route of a module', function () {
    $roots = [base_path('modules'), base_path('tests/Fixtures/modules')];
    $checked = 0;
    foreach (Route::getRoutes()->getRoutes() as $route) {
        $uses = $route->getAction('uses');
        $inModule = $uses instanceof Closure
            ? Str::startsWith(str_replace('\\', '/', (string) (new ReflectionFunction($uses))->getFileName()), array_map(fn ($r) => str_replace('\\', '/', $r), $roots))
            : is_string($uses) && Str::startsWith($uses, ['Modules\\', 'Tests\\Fixtures\\Modules\\']);
        if ($inModule) {
            $checked++;
            expect(collect($route->middleware())->contains(fn ($m) => str_starts_with($m, 'module:')))->toBeTrue($route->uri());
        }
    }

    expect($checked)->toBeGreaterThanOrEqual(4);
});

it('runs the module migrations in both states', function () {
    app(Settings::class)->set('modules.demo.enabled', false);
    $this->artisan('migrate')->assertSuccessful();

    expect(Schema::hasTable('demo_things'))->toBeTrue();

    app(Settings::class)->set('modules.demo.enabled', true);

    expect(Schema::hasTable('demo_things'))->toBeTrue();
});

it('lists the registered modules that can be switched, grouped, with their state', function () {
    app(Settings::class)->set('modules.beta.enabled', false);
    app()->setLocale('de');

    $this->actingAs(modUser('admin'))->get('/settings/system/modules')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('Settings/System/Modules')
        ->where('modules', [
            ['name' => 'beta', 'label' => 'Beta', 'description' => '', 'group' => 'verwaltung', 'enabled' => false, 'depends' => []],
            ['name' => 'demo', 'label' => 'Demo-Modul', 'description' => 'Nur für Tests.', 'group' => 'einsatz', 'enabled' => true, 'depends' => ['Beta']],
        ]));
});

it('shows only the shipped modules (Calendar, Enotf, Federation, FireTab, Forms, Logbook, KnowledgeBase, Mail, ManvBoard) when no other module is installed; Federation is off by default', function () {
    $this->app->instance(ModuleRegistry::class, new ModuleRegistry(base_path('modules'), app(Settings::class)));

    $this->actingAs(modUser('admin'))->get('/settings/system/modules')->assertInertia(fn (Assert $p) => $p->has('modules', 9)
        ->where('modules', fn ($m) => collect($m)->pluck('name')->sort()->values()->all() === ['calendar', 'enotf', 'federation', 'firetab', 'forms', 'knowledgebase', 'logbook', 'mail', 'manvboard']
            && collect($m)->where('enabled', false)->pluck('name')->all() === ['federation']));
});

it('requires login and the admin right (403 before 422)', function () {
    $this->get('/settings/system/modules')->assertRedirect('/login');
    $this->put('/settings/system/modules', ['modules' => ['demo' => false]])->assertRedirect('/login');

    $plain = modUser('personnel.view');
    $this->actingAs($plain)->get('/settings/system/modules')->assertForbidden();
    $this->actingAs($plain)->put('/settings/system/modules', ['modules' => ['gamma' => 'x']])->assertForbidden();
    expect(app(Settings::class)->has('modules.demo.enabled'))->toBeFalse();

    $this->actingAs(modUser('admin'))->get('/settings/system/modules')->assertOk();
    $super = User::factory()->superAdmin()->create();
    $this->actingAs($super)->get('/settings/system/modules')->assertOk();
    $this->actingAs($super)->put('/settings/system/modules', ['modules' => ['demo' => false]])->assertRedirect('/settings/system/modules');
    expect(app(Settings::class)->get('modules.demo.enabled'))->toBeFalse();
});

it('refuses unknown names, core modules and values that are not booleans', function (mixed $modules) {
    $this->actingAs(modUser('admin'))->putJson('/settings/system/modules', ['modules' => $modules])->assertUnprocessable();

    expect(Activity::count())->toBe(0)
        ->and(app(Settings::class)->has('modules.alpha.enabled'))->toBeFalse()
        ->and(app(Settings::class)->has('modules.demo.enabled'))->toBeFalse();
})->with([
    'unknown module' => [['gamma' => false]],
    'core module' => [['alpha' => false]],
    'not a boolean' => [['demo' => 'maybe']],
    'not a map' => ['demo'],
    'missing' => [null],
    'unknown next to a valid one' => [['demo' => false, 'gamma' => true]],
    'more than 100' => [array_fill_keys(array_map(fn ($i) => "m{$i}", range(1, 101)), true)],
]);

it('ignores dependencies that are not installed (Demo also names gamma)', function () {
    app(Settings::class)->set('modules.demo.enabled', false);

    $this->actingAs(modUser('admin'))->put('/settings/system/modules', ['modules' => ['demo' => true]])->assertSessionHasNoErrors();
    expect($this->modules->isEnabled('demo'))->toBeTrue();
});

it('keeps a module on while a core module depends on it', function () {
    $dir = sys_get_temp_dir().'/ignis_mod_'.uniqid();
    mkdir("$dir/Kern", 0777, true);
    mkdir("$dir/Opt");
    file_put_contents("$dir/Kern/KernServiceProvider.php", '<?php namespace Tmp\Kern; class KernServiceProvider extends \App\Modules\ModuleProvider { public function moduleName(): string { return "Kern"; } public function core(): bool { return true; } public function depends(): array { return ["opt"]; } }');
    file_put_contents("$dir/Opt/OptServiceProvider.php", '<?php namespace Tmp\Opt; class OptServiceProvider extends \App\Modules\ModuleProvider { public function moduleName(): string { return "Opt"; } }');

    try {
        $this->app->instance(ModuleRegistry::class, new ModuleRegistry($dir, app(Settings::class)));
        $this->actingAs(modUser('admin'))->putJson('/settings/system/modules', ['modules' => ['opt' => false]])
            ->assertUnprocessable()->assertJsonValidationErrors(['modules.opt']);
        expect(app(Settings::class)->has('modules.opt.enabled'))->toBeFalse();
    } finally {
        array_map('unlink', glob("$dir/*/*"));
        array_map('rmdir', glob("$dir/*"));
        rmdir($dir);
    }
});

it('keeps a module on while a switched-on module depends on it', function () {
    $admin = modUser('admin');

    $this->actingAs($admin)->putJson('/settings/system/modules', ['modules' => ['beta' => false]])
        ->assertUnprocessable()->assertJsonValidationErrors(['modules.beta']);
    $this->actingAs($admin)->from('/settings/system/modules')->put('/settings/system/modules', ['modules' => ['beta' => false]])
        ->assertSessionHasErrors('modules.beta');
    expect(session('errors')->first('modules.beta'))->toContain('Demo')
        ->and(app(Settings::class)->has('modules.beta.enabled'))->toBeFalse();

    $this->actingAs($admin)->put('/settings/system/modules', ['modules' => ['beta' => false, 'demo' => false]])->assertSessionHasNoErrors();
    expect($this->modules->switches())->toBe(['beta' => false, 'demo' => false]);
});

it('does not switch a module on while its dependency is off', function () {
    app(Settings::class)->set('modules.beta.enabled', false);
    app(Settings::class)->set('modules.demo.enabled', false);
    $admin = modUser('admin');

    $this->actingAs($admin)->putJson('/settings/system/modules', ['modules' => ['demo' => true]])
        ->assertUnprocessable()->assertJsonValidationErrors(['modules.demo']);
    expect(app(Settings::class)->get('modules.demo.enabled'))->toBeFalse();

    $this->actingAs($admin)->put('/settings/system/modules', ['modules' => ['demo' => true, 'beta' => true]])->assertSessionHasNoErrors();
    expect($this->modules->switches())->toBe(['beta' => true, 'demo' => true]);
});

it('audits each switched module exactly and writes nothing for unchanged ones', function () {
    $admin = modUser('admin');

    $this->actingAs($admin)->put('/settings/system/modules', ['modules' => ['demo' => true, 'beta' => true]])->assertRedirect();
    expect(Activity::count())->toBe(0)->and(app(Settings::class)->has('modules.demo.enabled'))->toBeFalse();

    $this->actingAs($admin)->put('/settings/system/modules', ['modules' => ['demo' => false, 'beta' => true]]);
    $off = Activity::sole();
    expect($off->description)->toBe('Modul deaktiviert')->and($off->log_name)->toBe('System')->and($off->details)->toBe('demo')
        ->and((bool) $off->is_global)->toBeTrue()->and($off->properties['context'])->toBe(['module' => 'demo'])->and($off->causer_id)->toBe($admin->id);

    $this->actingAs($admin)->put('/settings/system/modules', ['modules' => ['demo' => false]]);
    expect(Activity::count())->toBe(1);

    $this->actingAs($admin)->put('/settings/system/modules', ['modules' => ['demo' => true]]);
    $on = Activity::latest('id')->first();
    expect(Activity::count())->toBe(2)->and($on->description)->toBe('Modul aktiviert')->and($on->details)->toBe('demo')
        ->and($on->properties['context'])->toBe(['module' => 'demo']);
});
