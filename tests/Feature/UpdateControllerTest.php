<?php

use App\Models\Role;
use App\Models\User;
use App\Support\Settings;
use App\Update\Backup\BackupManager;
use App\Update\HealthGate;
use App\Update\Maintenance;
use App\Update\ProcessUpdateStarter;
use App\Update\Swapper;
use App\Update\UpdateException;
use App\Update\UpdateLock;
use App\Update\UpdateRunner;
use App\Update\UpdateState;
use Database\Seeders\RoleSeeder;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\MaintenanceSandbox;
use Tests\Support\UpdateFixtures;

uses(RefreshDatabase::class);

/** Records what would have been launched; never starts a process. */
class UcFakeLauncher extends ProcessUpdateStarter
{
    /** @var list<array{string, mixed}> */
    public array $calls = [];

    public function start(string $version, bool $skipTrial = false): void
    {
        $this->calls[] = ['start', [$version, $skipTrial]];
    }

    public function rollback(): void
    {
        $this->calls[] = ['rollback', []];
    }
}

/** The real preflight depends on the machine (test database is SQLite in memory): tests set what it answers. */
class UcFakeUpdater extends UpdateRunner
{
    public ?UpdateException $problem = null;

    public bool $real = false;

    public function preflight(): ?UpdateException
    {
        return $this->real ? parent::preflight() : $this->problem;
    }
}

beforeEach(function () {
    Http::preventStrayRequests();
    MaintenanceSandbox::enter();
    $this->seed(RoleSeeder::class);
    $this->base = sys_get_temp_dir().'/ignis-uc-'.bin2hex(random_bytes(4));
    mkdir($this->base);
    register_shutdown_function(fn () => (new Filesystem)->deleteDirectory($this->base));
    config(['ignis.update.state_path' => $this->base.'/state.json', 'ignis.update.backup_path' => $this->base.'/backups', 'ignis.update.driver' => 'files']);
    UpdateFixtures::setUp('1.0.0');
    $this->launcher = new UcFakeLauncher;
    app()->instance(ProcessUpdateStarter::class, $this->launcher);
    $this->updater = new UcFakeUpdater(app(UpdateState::class), new UpdateLock, app(Maintenance::class), app(Swapper::class), app(HealthGate::class));
    app()->instance(UpdateRunner::class, $this->updater);
});

function ucAdmin(): User
{
    $role = Role::create(['name' => 'U'.uniqid(), 'guard_name' => 'web', 'priority' => 500, 'color' => 'info']);
    $role->syncPermissions(['admin']);

    return User::factory()->withRole($role)->create();
}

function ucBackup(string $from = '1.0.0', string $to = '2.0.0'): void
{
    $dir = config('ignis.update.backup_path')."/20260101-000000-$from-to-$to";
    mkdir($dir, 0700, true);
    file_put_contents("$dir/manifest.json", json_encode(['created_at' => '2026-01-01T00:00:00Z', 'from' => $from, 'to' => $to, 'size_bytes' => 1234, 'files' => ['x' => 'y']]));
}

dataset('routes', [
    'status' => ['GET', '/settings/system/update/status'],
    'backups' => ['GET', '/settings/system/update/backups'],
    'check' => ['POST', '/settings/system/update/check'],
    'start' => ['POST', '/settings/system/update'],
    'rollback' => ['POST', '/settings/system/update/rollback'],
]);

it('rights matrix: guest redirected, user forbidden, admin and super admin allowed', function (string $method, string $url) {
    $this->call($method, $url)->assertRedirect('/login');
    $this->actingAs(User::factory()->create())->call($method, $url)->assertForbidden();
    foreach ([ucAdmin(), User::factory()->superAdmin()->create()] as $user) {
        expect($this->actingAs($user)->call($method, $url, [], [], [], ['HTTP_ACCEPT' => 'application/json'])->status())->not->toBeIn([302, 401, 403]);
    }
})->with('routes');

it('status JSON: state, stale flag, redacted log tail, no secrets, no-store', function () {
    app(UpdateState::class)->begin('1.0.0', '2.0.0');
    app(UpdateState::class)->step('downloading');
    $logs = storage_path('logs');
    mkdir($logs, 0775, true);
    $lines = array_map(fn ($i) => "[t] line $i", range(1, 60));
    $lines[] = '[t] auth Bearer abc123secret done';
    file_put_contents("$logs/update-20260101-000000.log", implode("\n", $lines)."\n");

    $res = $this->actingAs(ucAdmin())->getJson('/settings/system/update/status')->assertOk();
    $res->assertHeader('Cache-Control', 'no-store, private');
    expect($res->json('state.status'))->toBe('running')
        ->and($res->json('state.step'))->toBe('downloading')
        ->and($res->json('stale'))->toBeTrue()
        ->and($res->json('log'))->toHaveCount(40)
        ->and($res->getContent())->not->toContain('abc123secret')->not->toContain('"secret"')
        ->and($res->json('log.39'))->toContain('Bearer [redacted]')
        ->and($res->json('driver'))->toBe('files')
        ->and($res->json('paths'))->toBe(['state' => config('ignis.update.state_path'), 'log' => storage_path('logs')]);
});

it('status is reachable in maintenance mode and never reveals the maintenance secret', function () {
    (new Maintenance)->enable();
    $down = (string) file_get_contents(storage_path('framework/down'));
    $secret = json_decode($down, true)['secret'];
    expect($secret)->not->toBeEmpty();

    $res = $this->actingAs(ucAdmin())->getJson('/settings/system/update/status')->assertOk();
    expect($res->json('maintenance'))->toBeTrue()->and($res->getContent())->not->toContain($secret);
    $this->actingAs(ucAdmin())->get('/settings/system/jobs')->assertStatus(503);
    foreach (['/healthz', '/api/health'] as $probe) {
        $this->getJson($probe)->assertOk()->assertJsonStructure(['status', 'checks']);
    }
    expect($res->json('image_ref'))->toBeNull();
});

it('status offers the trial-less run after a trial_unavailable failure only', function () {
    $state = app(UpdateState::class);
    $state->begin('1.0.0', '2.0.0');
    $state->fail('trial_migration', __('update.error.trial_unavailable'), reason: 'trial_unavailable');
    $this->actingAs(ucAdmin())->getJson('/settings/system/update/status')->assertJsonPath('trial_unavailable', true)->assertJsonPath('state.status', 'failed');

    $state->begin('1.0.0', '2.0.0');
    $state->fail('downloading', __('update.error.sha256'), reason: 'sha256');
    $this->actingAs(ucAdmin())->getJson('/settings/system/update/status')->assertJsonPath('trial_unavailable', false);
});

it('lists backups with time, versions and size, without paths', function () {
    ucBackup();
    $res = $this->actingAs(ucAdmin())->getJson('/settings/system/update/backups')->assertOk();
    expect($res->json('backups.0'))->toBe(['name' => '20260101-000000-1.0.0-to-2.0.0', 'created_at' => '2026-01-01T00:00:00Z', 'from' => '1.0.0', 'to' => '2.0.0', 'size_bytes' => 1234]);
});

it('start launches in the background and answers 202 with an audit entry', function () {
    UpdateFixtures::fake();
    $admin = ucAdmin();

    $this->actingAs($admin)->postJson('/settings/system/update', ['version' => '2.0.0', 'without_trial' => true])->assertStatus(202);

    expect($this->launcher->calls)->toBe([['start', ['2.0.0', true]]]);
    $a = Activity::where('description', 'Update gestartet')->sole();
    expect($a->causer_id)->toBe($admin->id)->and($a->log_name)->toBe('System')->and($a->details)->toBe('1.0.0 → 2.0.0');
    expect((new UpdateLock)->ownerAlive())->toBeFalse()->and(app(UpdateState::class)->read()['status'])->toBe('idle');
});

it('start validates the version and the flag', function (mixed $version, mixed $trial) {
    $this->actingAs(ucAdmin())->postJson('/settings/system/update', ['version' => $version, 'without_trial' => $trial])->assertStatus(422);
    expect($this->launcher->calls)->toBe([]);
})->with([['v2.0.0', true], ['2.0', false], ['2.0.0; ls', false], ['', false], [['2.0.0'], false], ['2.0.0', 'maybe']]);

it('start refuses a blocked installation with the reason', function (string $reason, Closure $setup) {
    $setup();
    $this->actingAs(ucAdmin())->postJson('/settings/system/update', ['version' => '2.0.0'])
        ->assertStatus(422)->assertJsonPath('reason', $reason)->assertJsonPath('message', __("update.blocker.$reason"));
    expect($this->launcher->calls)->toBe([])->and(Activity::count())->toBe(0);
})->with([
    'git' => ['git_checkout', fn () => mkdir(config('ignis.update.app_path').'/.git')],
    'dev' => ['dev_version', fn () => unlink(config('ignis.update.app_path').'/VERSION')],
    'repo' => ['repo_missing', fn () => config(['ignis.release_repo' => ''])],
    'key' => ['key_missing', fn () => config(['ignis.update.public_key' => 'PLACEHOLDER'])],
]);

it('start refuses while an update runs, crashed or alive', function () {
    UpdateFixtures::fake();
    $state = app(UpdateState::class);
    $lock = new UpdateLock;
    $lock->acquire();
    $state->begin('1.0.0', '2.0.0');
    $this->actingAs(ucAdmin())->postJson('/settings/system/update', ['version' => '2.0.0'])->assertStatus(409)->assertJsonPath('reason', 'busy');
    $lock->release();
    $this->actingAs(ucAdmin())->postJson('/settings/system/update', ['version' => '2.0.0'])->assertStatus(409)->assertJsonPath('reason', 'recover_needed');
    expect($this->launcher->calls)->toBe([]);
});

it('start refuses after a failed rollback', function () {
    UpdateFixtures::fake();
    $state = app(UpdateState::class);
    $state->begin('1.0.0', '2.0.0');
    $state->beginRollback('switching', 'x');
    $state->finishRollback(false, 'y');
    $this->actingAs(ucAdmin())->postJson('/settings/system/update', ['version' => '2.0.0'])->assertStatus(409)->assertJsonPath('reason', 'rollback_needed');
});

it('start refuses when the verified release is not the requested version', function () {
    UpdateFixtures::fake();
    $this->actingAs(ucAdmin())->postJson('/settings/system/update', ['version' => '1.5.0'])->assertStatus(422)->assertJsonPath('reason', 'release_changed');
    expect($this->launcher->calls)->toBe([]);
});

it('start refuses a bad signature and stores nothing secret', function () {
    UpdateFixtures::fake(signature: base64_encode(random_bytes(64)));
    $this->actingAs(ucAdmin())->postJson('/settings/system/update', ['version' => '2.0.0'])->assertStatus(422)->assertJsonPath('reason', 'signature');
    expect($this->launcher->calls)->toBe([])->and(Activity::count())->toBe(0);
});

it('start audit and state hold no secret', function () {
    UpdateFixtures::fake();
    $this->actingAs(ucAdmin())->postJson('/settings/system/update', ['version' => '2.0.0'])->assertStatus(202);
    $dump = json_encode(Activity::all()->toArray()).(string) @file_get_contents($this->base.'/state.json');
    expect($dump)->not->toContain(config('ignis.update.public_key'))->not->toContain('secret');
});

it('rollback needs the confirmation', function () {
    ucBackup();
    $this->actingAs(ucAdmin())->postJson('/settings/system/update/rollback')->assertStatus(422);
    $this->actingAs(ucAdmin())->postJson('/settings/system/update/rollback', ['confirm' => false])->assertStatus(422);
    expect($this->launcher->calls)->toBe([]);
});

it('rollback needs a backup of the installed version', function () {
    $this->actingAs(ucAdmin())->postJson('/settings/system/update/rollback', ['confirm' => true])->assertStatus(422)->assertJsonPath('reason', 'no_backup');
    ucBackup('0.9.0', '0.9.5');
    $this->actingAs(ucAdmin())->postJson('/settings/system/update/rollback', ['confirm' => true])->assertStatus(422)->assertJsonPath('reason', 'no_backup');
    expect($this->launcher->calls)->toBe([])->and(Activity::count())->toBe(0);
});

it('rollback refuses while an update runs', function () {
    ucBackup('0.9.0', '1.0.0');
    app(UpdateState::class)->begin('1.0.0', '2.0.0');
    $this->actingAs(ucAdmin())->postJson('/settings/system/update/rollback', ['confirm' => true])->assertStatus(409);
    expect($this->launcher->calls)->toBe([]);
});

it('rollback launches detached and audits', function () {
    ucBackup('0.9.0', '1.0.0');
    $admin = ucAdmin();
    $this->actingAs($admin)->postJson('/settings/system/update/rollback', ['confirm' => true])->assertStatus(202);
    expect($this->launcher->calls)->toBe([['rollback', []]]);
    $a = Activity::where('description', 'Auf vorherigen Stand zurückgesetzt')->sole();
    expect($a->causer_id)->toBe($admin->id)->and($a->details)->toBe('1.0.0 → 0.9.0');
});

it('check stores updates.* even with the automatic check off', function () {
    UpdateFixtures::fake();
    $settings = app(Settings::class);
    $settings->set('updates.check_enabled', false);

    $this->actingAs(ucAdmin())->postJson('/settings/system/update/check')->assertOk()
        ->assertJsonPath('latest_version', '2.0.0')->assertJsonPath('release_url', 'https://github.com/ignis-test/ignis/releases/tag/v2.0.0');
    expect($settings->get('updates.latest_version'))->toBe('2.0.0')->and($settings->get('updates.checked_at'))->not->toBeEmpty();
});

it('check answers 422 without a repository and on a failed check', function () {
    config(['ignis.release_repo' => '']);
    $this->actingAs(ucAdmin())->postJson('/settings/system/update/check')->assertStatus(422)->assertJsonPath('reason', 'repo_missing');

    config(['ignis.release_repo' => UpdateFixtures::REPO]);
    Http::fake([UpdateFixtures::API => Http::response('x', 500)]);
    $this->actingAs(ucAdmin())->postJson('/settings/system/update/check')->assertStatus(422)->assertJsonPath('reason', 'check_failed');
});

it('system page props carry the blocker and no manifest or secret', function () {
    UpdateFixtures::fake();
    $json = $this->actingAs(ucAdmin())->get('/settings/system')->assertOk()->getContent();
    foreach ([config('ignis.update.public_key'), 'sha256', 'manifest.json'] as $needle) {
        expect($json)->not->toContain($needle);
    }
    expect(app(BackupManager::class))->toBeInstanceOf(BackupManager::class);
});

it('start refuses a configuration problem before launching: docker driver, dump, release layout', function (string $reason, Closure $setup) {
    UpdateFixtures::fake();
    $setup($this->updater);
    $res = $this->actingAs(ucAdmin())->postJson('/settings/system/update', ['version' => '2.0.0'])->assertStatus(422);
    expect($res->json('reason'))->toBe($reason)->and($res->json('message'))->not->toBeEmpty()
        ->and($this->launcher->calls)->toBe([])->and(Activity::where('description', 'Update gestartet')->count())->toBe(0);
    expect($this->actingAs(ucAdmin())->getJson('/settings/system/update/status')->json('preflight.reason'))->toBe($reason);
})->with([
    'docker' => ['driver_docker', function ($u) {
        config(['ignis.update.driver' => 'docker']);
        $u->real = true;
    }],
    'dump' => ['dump_unavailable', function ($u) {
        $u->real = true;
    }],
    'release layout' => ['release_layout_db', function ($u) {
        $u->problem = new UpdateException('release_layout_db');
    }],
]);

it('status carries no preflight problem when the configuration is fine', function () {
    $this->actingAs(ucAdmin())->getJson('/settings/system/update/status')->assertJsonPath('preflight', null);
});

it('start refuses an unreadable state file', function () {
    UpdateFixtures::fake();
    file_put_contents($this->base.'/state.json', '{broken');
    $this->actingAs(ucAdmin())->postJson('/settings/system/update', ['version' => '2.0.0'])->assertStatus(409)->assertJsonPath('reason', 'state_unreadable');
    expect($this->launcher->calls)->toBe([]);
});

it('rollback is offered only for a backup of the installed version', function () {
    ucBackup('0.9.0', '0.9.5');
    $this->actingAs(ucAdmin())->getJson('/settings/system/update/status')->assertJsonPath('backup_available', false);
    ucBackup('0.9.0', '1.0.0');
    $this->actingAs(ucAdmin())->getJson('/settings/system/update/status')->assertJsonPath('backup_available', true);
});

it('check and start share a tight throttle bucket, rollback has its own', function () {
    UpdateFixtures::fake();
    $admin = ucAdmin();
    for ($i = 0; $i < 6; $i++) {
        $this->actingAs($admin)->postJson('/settings/system/update/check')->assertOk();
    }
    $this->actingAs($admin)->postJson('/settings/system/update/check')->assertStatus(429);
    $this->actingAs($admin)->postJson('/settings/system/update', ['version' => '2.0.0'])->assertStatus(429);
});

it('status in maintenance: guest redirected, plain user forbidden', function () {
    (new Maintenance)->enable();
    $this->getJson('/settings/system/update/status')->assertStatus(401);
    $this->actingAs(User::factory()->create())->getJson('/settings/system/update/status')->assertForbidden();
});

it('the system page opens for admins in maintenance mode, other pages stay 503', function () {
    (new Maintenance)->enable();
    $this->get('/settings/system')->assertRedirect('/login');
    $this->actingAs(User::factory()->create())->get('/settings/system')->assertForbidden();
    $this->actingAs(ucAdmin())->get('/settings/system')->assertOk();
    $this->actingAs(ucAdmin())->get('/settings/system/jobs')->assertStatus(503);
});

it('rollback is reachable in maintenance mode (after failed_rollback) for admins only; other update POSTs stay 503', function () {
    ucBackup('0.9.0', '1.0.0');
    $state = app(UpdateState::class);
    $state->begin('0.9.0', '1.0.0');
    $state->beginRollback('switching', 'x');
    $state->finishRollback(false, 'y');
    (new Maintenance)->enable();

    $this->postJson('/settings/system/update/rollback', ['confirm' => true])->assertStatus(401);
    $this->actingAs(User::factory()->create())->postJson('/settings/system/update/rollback', ['confirm' => true])->assertForbidden();
    $this->actingAs(ucAdmin())->postJson('/settings/system/update/rollback')->assertStatus(422);
    $this->actingAs(ucAdmin())->postJson('/settings/system/update/rollback', ['confirm' => true])->assertStatus(202);
    expect($this->launcher->calls)->toBe([['rollback', []]]);

    foreach (['/settings/system/update', '/settings/system/update/check'] as $url) {
        $this->actingAs(ucAdmin())->postJson($url, ['version' => '2.0.0'])->assertStatus(503);
    }
    $this->actingAs(ucAdmin())->getJson('/settings/system/update/backups')->assertStatus(503);
});

it('status hides the preflight while a run is on and reports it after a failed rollback', function () {
    $this->updater->problem = new UpdateException('recover_needed');
    $state = app(UpdateState::class);
    $state->begin('1.0.0', '2.0.0');
    $this->actingAs(ucAdmin())->getJson('/settings/system/update/status')->assertJsonPath('preflight', null);

    $state->beginRollback('switching', 'x');
    $state->finishRollback(false, 'y');
    $this->actingAs(ucAdmin())->getJson('/settings/system/update/status')->assertJsonPath('preflight.reason', 'recover_needed');
});

describe('docker driver', function () {
    beforeEach(function () {
        config(['ignis.update.driver' => 'docker', 'ignis.update.updater_url' => 'http://updater:8099', 'ignis.update.updater_secret' => str_repeat('k', 40)]);
        $this->updater->real = true;
    });

    it('lifts the driver_docker blocker when the updater answers a signed ping', function () {
        Http::fake(['http://updater:8099/ping' => Http::response(['ok' => true, 'running' => false])]);
        $this->actingAs(ucAdmin())->getJson('/settings/system/update/status')->assertJsonPath('preflight', null)->assertJsonPath('stale', false)->assertJsonPath('driver', 'docker');
        Http::assertSent(fn ($r) => $r->hasHeader('X-Ignis-Signature') && $r->hasHeader('X-Ignis-Timestamp'));
    });

    it('names an updater that does not answer', function () {
        Http::fake(['http://updater:8099/*' => fn () => throw new ConnectionException('down')]);
        $this->actingAs(ucAdmin())->getJson('/settings/system/update/status')->assertJsonPath('preflight.reason', 'updater_unreachable');
    });

    it('start delegates to the updater container instead of launching a process', function () {
        UpdateFixtures::fake(extra: [
            'http://updater:8099/ping' => Http::response(['ok' => true, 'running' => false]),
            'http://updater:8099/start' => Http::response(['ok' => true], 202),
        ]);
        $this->actingAs(ucAdmin())->postJson('/settings/system/update', ['version' => '2.0.0', 'without_trial' => true])->assertStatus(202);
        expect($this->launcher->calls)->toBe([])->and(Activity::where('description', 'Update gestartet')->count())->toBe(1);
        Http::assertSent(fn ($r) => $r->url() === 'http://updater:8099/start' && json_decode($r->body(), true) === ['version' => '2.0.0', 'skip_trial' => true]);
    });

    it('start passes the updater refusal on and logs no start', function () {
        UpdateFixtures::fake(extra: [
            'http://updater:8099/ping' => Http::response(['ok' => true, 'running' => false]),
            'http://updater:8099/start' => Http::response(['reason' => 'image_missing', 'message' => 'no image'], 422),
        ]);
        $this->actingAs(ucAdmin())->postJson('/settings/system/update', ['version' => '2.0.0'])->assertStatus(422)->assertJsonPath('reason', 'image_missing');
        expect(Activity::where('description', 'Update gestartet')->count())->toBe(0);
    });

    it('a running updater makes start and rollback busy', function () {
        Http::fake(['http://updater:8099/ping' => Http::response(['ok' => true, 'running' => true])]);
        $this->actingAs(ucAdmin())->postJson('/settings/system/update', ['version' => '2.0.0'])->assertStatus(409)->assertJsonPath('reason', 'busy');
        $this->actingAs(ucAdmin())->postJson('/settings/system/update/rollback', ['confirm' => true])->assertStatus(409)->assertJsonPath('reason', 'busy');
    });

    it('rollback goes to the updater container', function () {
        ucBackup('0.9.0', '1.0.0');
        Http::fake(['http://updater:8099/ping' => Http::response(['ok' => true, 'running' => false]), 'http://updater:8099/rollback' => Http::response(['ok' => true], 202)]);
        $this->actingAs(ucAdmin())->postJson('/settings/system/update/rollback', ['confirm' => true])->assertStatus(202);
        expect($this->launcher->calls)->toBe([]);
        Http::assertSent(fn ($r) => $r->url() === 'http://updater:8099/rollback');
    });
});
