<?php

use App\Console\Commands\SchedulerHeartbeatCommand;
use App\Models\Document;
use App\Models\LegacyDocument;
use App\Models\Personnel;
use App\Models\Role;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Settings;
use App\System\PendingMigrations;
use App\System\SystemOverview;
use Database\Seeders\RoleSeeder;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\UpdateFixtures;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

function ovUser(string ...$perms): User
{
    $role = Role::create(['name' => 'O'.uniqid(), 'guard_name' => 'web', 'priority' => 500, 'color' => 'info']);
    $role->syncPermissions($perms);

    return User::factory()->withRole($role)->create();
}

/** @return array<string, mixed> */
function ovBuild(): array
{
    return app(SystemOverview::class)->build(Request::create('/settings/system'));
}

function ovFailing(string ...$probes): SystemOverview
{
    return new class(app(PendingMigrations::class), array_values($probes)) extends SystemOverview
    {
        /** @param list<string> $failing */
        public function __construct(PendingMigrations $m, private array $failing)
        {
            parent::__construct($m);
        }

        private function maybe(string $probe): void
        {
            if (in_array($probe, $this->failing, true)) {
                throw new RuntimeException("{$probe} failed");
            }
        }

        protected function jobsCount(): int
        {
            $this->maybe('jobs');

            return parent::jobsCount();
        }

        protected function failedJobsCount(): int
        {
            $this->maybe('failed_jobs');

            return parent::failedJobsCount();
        }

        protected function personnelCount(): int
        {
            $this->maybe('personnel');

            return parent::personnelCount();
        }

        protected function documentsCount(): int
        {
            $this->maybe('documents');

            return parent::documentsCount();
        }

        protected function vehiclesCount(): int
        {
            $this->maybe('vehicles');

            return parent::vehiclesCount();
        }

        protected function sessionsCount(): ?int
        {
            $this->maybe('sessions');

            return parent::sessionsCount();
        }

        protected function dbVersion(): string
        {
            $this->maybe('db_version');

            return parent::dbVersion();
        }

        protected function dbSize(): int
        {
            $this->maybe('db_size');

            return parent::dbSize();
        }

        protected function topTables(): array
        {
            $this->maybe('tables');

            return parent::topTables();
        }

        protected function health(Request $request): array
        {
            $this->maybe('health');

            return parent::health($request);
        }
    };
}

function ovPendingDir(string $name = '2099_01_01_000000_pending_fixture'): string
{
    $dir = sys_get_temp_dir().'/ignis-mig-'.uniqid();
    File::makeDirectory($dir);
    File::put("$dir/$name.php", '<?php return new class extends Illuminate\Database\Migrations\Migration { public function up(): void {} };');
    test()->beforeApplicationDestroyed(fn () => File::deleteDirectory($dir));

    return $dir;
}

it('requires login and the admin right', function () {
    $this->get('/settings/system')->assertRedirect('/login');

    $this->actingAs(ovUser('personnel.view'))->get('/settings/system')->assertForbidden();
    $this->actingAs(ovUser('admin'))->get('/settings/system')->assertOk();
    $this->actingAs(User::factory()->superAdmin()->create())->get('/settings/system')->assertOk();
});

it('renders the shell at once and defers the numbers', function () {
    config(['ignis.version' => '9.9.9']);

    $this->actingAs(ovUser('admin'))->get('/settings/system')->assertInertia(fn (Assert $p) => $p
        ->component('Settings/System/Index')
        ->where('version', '9.9.9')
        ->missing('overview')
        ->loadDeferredProps(fn (Assert $d) => $d->has('overview.health.checks', 6)->has('overview.counts')));
});

it('counts personnel, documents of both kinds, vehicles and sessions of the last 24 hours', function () {
    Personnel::factory()->count(3)->create();
    Vehicle::factory()->count(2)->create();
    Document::factory()->create(['personnel_id' => Personnel::query()->first()->id]);
    LegacyDocument::factory()->count(2)->create();
    config(['session.driver' => 'database']);
    $now = now()->timestamp;
    foreach ([['fresh', $now - 60], ['edge', $now - 86000], ['old', $now - 90000]] as [$id, $at]) {
        DB::table('sessions')->insert(['id' => $id, 'payload' => '', 'last_activity' => $at]);
    }
    DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'available_at' => $now, 'created_at' => $now]);
    DB::table('failed_jobs')->insert(['uuid' => 'u1', 'connection' => 'x', 'queue' => 'x', 'payload' => '{}', 'exception' => 'boom', 'failed_at' => now()]);

    $o = ovBuild();

    expect($o['counts'])->toBe(['personnel' => Personnel::query()->count(), 'documents' => 3, 'vehicles' => 2, 'sessions_24h' => 2])
        ->and($o['queue'])->toBe(['pending' => 1, 'failed' => 1])
        ->and($o['runtime']['php'])->toBe(PHP_VERSION)
        ->and($o['runtime']['db_driver'])->toBe(DB::getDriverName())
        ->and($o['runtime']['db_version'])->toBeString();
});

it('gives the database size and, where the engine can, the largest tables', function () {
    $db = ovBuild()['database'];

    expect($db['size_bytes'])->toBeInt()->toBeGreaterThan(0);
    if ($db['tables'] !== null) {
        expect(count($db['tables']))->toBeLessThanOrEqual(10)
            ->and($db['tables'][0])->toHaveKeys(['name', 'bytes'])
            ->and(array_column($db['tables'], 'bytes'))->toBe(collect(array_column($db['tables'], 'bytes'))->sortDesc()->values()->all());
    }
});

it('reads the table sizes from information_schema on MariaDB', function () {
    $tables = ovBuild()['database']['tables'];

    expect($tables)->not->toBeEmpty()->and(count($tables))->toBeLessThanOrEqual(10)
        ->and(collect($tables)->pluck('name')->all())->toContain('users');
})->skip(fn () => ! in_array(config('database.default'), ['mysql', 'mariadb'], true), 'needs the MariaDB test configuration');

it('lets each failing probe give null without a 500', function () {
    $all = ['jobs', 'failed_jobs', 'personnel', 'documents', 'vehicles', 'sessions', 'db_version', 'db_size', 'tables', 'health'];
    config(['session.driver' => 'database']);

    $o = ovFailing(...$all)->build(Request::create('/settings/system'));

    expect($o['queue'])->toBe(['pending' => null, 'failed' => null])
        ->and($o['counts'])->toBe(['personnel' => null, 'documents' => null, 'vehicles' => null, 'sessions_24h' => null])
        ->and($o['database'])->toBe(['size_bytes' => null, 'tables' => null])
        ->and($o['health'])->toBeNull()
        ->and($o['runtime']['db_version'])->toBeNull()
        ->and($o['scheduler']['status'])->toBe('unknown');

    $one = ovFailing('vehicles')->build(Request::create('/settings/system'));
    expect($one['counts']['vehicles'])->toBeNull()->and($one['counts']['personnel'])->toBeInt()->and($one['health'])->not->toBeNull();
});

it('answers the page with 200 when a probe fails', function () {
    $this->app->bind(SystemOverview::class, fn () => ovFailing('failed_jobs', 'health'));

    $this->actingAs(ovUser('admin'))->get('/settings/system')->assertOk()
        ->assertInertia(fn (Assert $p) => $p->loadDeferredProps(fn (Assert $d) => $d->where('overview.queue.failed', null)->where('overview.health', null)));
});

it('gives no session count unless sessions live in the database', function () {
    DB::table('sessions')->insert(['id' => 'x', 'payload' => '', 'last_activity' => now()->timestamp]);

    config(['session.driver' => 'file']);
    expect(ovBuild()['counts']['sessions_24h'])->toBeNull();

    config(['session.driver' => 'database']);
    expect(ovBuild()['counts']['sessions_24h'])->toBe(1);
});

it('treats a missing migrations table as nothing pending and a failing list as null', function () {
    $migrator = Mockery::mock(Migrator::class);
    $migrator->shouldReceive('paths')->andReturn([]);
    $migrator->shouldReceive('getMigrationFiles')->andReturn(['2099_x' => 'x']);
    $migrator->shouldReceive('getRepository->getRan')->andThrow(new RuntimeException('no table'));
    expect((new PendingMigrations($migrator))->names())->toBe([]);

    Cache::shouldReceive('remember')->andThrow(new RuntimeException('cache down'));
    Cache::shouldReceive('get')->andReturn(null);
    expect(ovBuild()['migrations'])->toBeNull();
});

it('forgets the cached pending list when a migration run ends', function () {
    $dir = ovPendingDir();
    app('migrator')->path($dir);
    expect(app(PendingMigrations::class)->names())->toHaveCount(1);

    File::delete("$dir/2099_01_01_000000_pending_fixture.php");
    event(new MigrationsEnded('up', []));

    expect(app(PendingMigrations::class)->names())->toBe([]);
});

it('reports the scheduler heartbeat as unknown, fresh or stale', function () {
    $this->travelTo(now()->startOfSecond());
    expect(ovBuild()['scheduler'])->toBe(['status' => 'unknown', 'last_run' => null, 'age_seconds' => null]);

    Artisan::call('ignis:heartbeat');
    $this->travel(2)->minutes();
    expect(ovBuild()['scheduler']['status'])->toBe('ok')->and(ovBuild()['scheduler']['age_seconds'])->toBe(120);

    $this->travel(4)->minutes();
    expect(ovBuild()['scheduler'])->toMatchArray(['status' => 'warn', 'age_seconds' => 360]);
});

it('writes the heartbeat with the command and schedules it every minute', function () {
    expect(Cache::get(SchedulerHeartbeatCommand::CACHE_KEY))->toBeNull();

    $this->artisan('ignis:heartbeat')->assertSuccessful();

    expect(Cache::get(SchedulerHeartbeatCommand::CACHE_KEY))->toBe(now()->toIso8601String());
    $event = collect(app(Schedule::class)->events())->first(fn (Event $e) => str_contains($e->command, 'ignis:heartbeat'));
    expect($event)->not->toBeNull()->and($event->expression)->toBe('* * * * *');
});

it('shows a short message per health check and never raw error text', function () {
    $broken = DB::getDriverName() === 'sqlite';
    if ($broken) {
        Schema::drop('failed_jobs');
    }

    $health = ovBuild()['health'];

    expect($health['status'])->toBeIn($broken ? ['down'] : ['ok', 'degraded']);
    foreach ($health['checks'] as $c) {
        expect(array_keys($c))->toBe(['key', 'status', 'message']);
    }
    $queue = collect($health['checks'])->firstWhere('key', 'queue');
    if ($broken) {
        expect($queue)->toBe(['key' => 'queue', 'status' => 'down', 'message' => 'Warteschlange nicht erreichbar']);
    }
    expect(json_encode($health))->not->toContain('SQLSTATE')->not->toContain('no such table')->not->toContain('secret-path');
});

it('keeps secrets out of every prop', function () {
    config(['database.connections.sqlite.password' => 'db-pass-9f3k', 'database.connections.sqlite.host' => 'db-host-9f3k']);
    config(['session.driver' => 'database']);
    $settings = app(Settings::class);
    $settings->set('api.key', 'apikey-9f3k-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', true);
    $settings->set('discord.client_secret', 'discord-secret-9f3k', true);
    $admin = ovUser('admin');
    $this->actingAs($admin);
    $sid = 'session-id-9f3k';
    DB::table('sessions')->insert(['id' => $sid, 'payload' => 'payload-9f3k', 'last_activity' => now()->timestamp]);

    $page = $this->get('/settings/system')->viewData('page')['props'];
    $dump = json_encode([$page, ovBuild()]);

    foreach (['db-pass-9f3k', 'db-host-9f3k', 'apikey-9f3k', 'discord-secret-9f3k', $sid, 'payload-9f3k', config('app.key'), base_path(), trim(json_encode(base_path()), '"')] as $secret) {
        expect($dump)->not->toContain(is_string($secret) ? str_replace('/', '\\/', $secret) : '')->not->toContain($secret);
    }
});

it('shows the update section variants', function (array $set, ?string $latest, ?string $url, bool $enabled) {
    foreach ($set as $k => $v) {
        app(Settings::class)->set($k, $v);
    }

    $this->actingAs(ovUser('admin'))->get('/settings/system')->assertInertia(fn (Assert $p) => $p
        ->where('updates.latest_version', $latest)->where('updates.release_url', $url)->where('updates.check_enabled', $enabled));
})->with([
    'off' => [[], null, null, false],
    'on, nothing yet' => [['updates.check_enabled' => true], null, null, true],
    'latest known' => [['updates.check_enabled' => true, 'updates.latest_version' => '2.0.0', 'updates.release_url' => 'https://example.org/r/2.0.0'], '2.0.0', 'https://example.org/r/2.0.0', true],
    'non-https link is dropped' => [['updates.latest_version' => '2.0.0', 'updates.release_url' => 'javascript:alert(1)'], '2.0.0', null, false],
]);

it('tells whether this installation can update itself', function () {
    UpdateFixtures::setUp('1.0.0');
    $this->actingAs(ovUser('admin'))->get('/settings/system')->assertInertia(fn (Assert $p) => $p
        ->where('update', ['can_self_update' => true, 'reason' => null, 'repo_configured' => true]));

    config(['ignis.release_repo' => '']);
    $this->actingAs(ovUser('admin'))->get('/settings/system')->assertInertia(fn (Assert $p) => $p
        ->where('update', ['can_self_update' => false, 'reason' => 'repo_missing', 'repo_configured' => false]));
});

it('lists migration files that have not run, without running them', function () {
    expect(app(PendingMigrations::class)->names())->toBe([]);

    Cache::forget('system.pending_migrations');
    app('migrator')->path(ovPendingDir());

    expect(app(PendingMigrations::class)->names())->toBe(['2099_01_01_000000_pending_fixture'])
        ->and(DB::table('migrations')->where('migration', 'like', '2099%')->count())->toBe(0);
});

it('caches the pending list for 60 seconds', function () {
    $dir = ovPendingDir();
    app('migrator')->path($dir);
    expect(app(PendingMigrations::class)->names())->toHaveCount(1);

    File::delete("$dir/2099_01_01_000000_pending_fixture.php");
    expect(app(PendingMigrations::class)->names())->toHaveCount(1);

    $this->travel(61)->seconds();
    expect(app(PendingMigrations::class)->names())->toBe([]);
});

it('shows the pending-migration banner prop to admins only', function () {
    app('migrator')->path(ovPendingDir());

    $this->actingAs(ovUser('admin'))->get('/settings/system/modules')
        ->assertInertia(fn (Assert $p) => $p->where('system.pending_migrations', ['2099_01_01_000000_pending_fixture']));
    $this->actingAs(User::factory()->superAdmin()->create())->get('/dashboard')
        ->assertInertia(fn (Assert $p) => $p->has('system.pending_migrations', 1));
    $this->actingAs(ovUser('personnel.view'))->get('/dashboard')
        ->assertInertia(fn (Assert $p) => $p->where('system.pending_migrations', []));
});

it('never breaks a page when the pending probe fails', function () {
    $this->app->bind(PendingMigrations::class, fn () => throw new RuntimeException('boom'));

    $this->actingAs(ovUser('admin'))->get('/settings/system/modules')->assertOk()
        ->assertInertia(fn (Assert $p) => $p->where('system.pending_migrations', []));
});

it('sends no pending list to guests', function () {
    app('migrator')->path(ovPendingDir());

    $this->get('/login')->assertInertia(fn (Assert $p) => $p->where('system.pending_migrations', []));
});

it('redirects the legacy settings pages', function (string $from, int $status, string $to) {
    $this->get($from)->assertStatus($status)->assertRedirect($to);
})->with([
    ['/settings/index', 301, '/settings'],
    ['/settings/system/index', 301, '/settings/system'],
    ['/settings/system/performance', 301, '/settings/system'],
    ['/settings/system/updater', 301, '/settings/system'],
    ['/settings/system/telemetry', 301, '/settings/system'],
    ['/settings/system/plugins', 301, '/settings/system/modules'],
    ['/settings/system/index?x=1', 301, '/settings/system?x=1'],
]);

it('queries the same number of times however much data there is', function () {
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        ovBuild();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };
    config(['session.driver' => 'database']);
    ovBuild();
    $few = $count();
    Personnel::factory()->count(20)->create();
    Vehicle::factory()->count(20)->create();
    foreach (range(1, 20) as $i) {
        DB::table('sessions')->insert(['id' => "s$i", 'payload' => '', 'last_activity' => now()->timestamp]);
    }

    expect($count())->toBe($few);
});
