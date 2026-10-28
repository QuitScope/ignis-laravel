<?php

use App\Update\Backup\BackupManager;
use App\Update\Backup\DatabaseDumper;
use App\Update\Backup\SqliteDumper;
use App\Update\FlatSwapper;
use App\Update\HealthGate;
use App\Update\Maintenance;
use App\Update\Swapper;
use App\Update\TrialMigration;
use App\Update\UpdateException;
use App\Update\UpdateLock;
use App\Update\UpdateLog;
use App\Update\UpdateRunner;
use App\Update\UpdateState;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\MaintenanceSandbox;
use Tests\Support\UpdateFixtures;

uses()->group('slow');

const UP_ENV = "APP_KEY=base64:c2VjcmV0LWFwcC1rZXktZm9yLXRlc3RzLW9ubHktMTIzNA==\nDB_PASSWORD=envSecret99\n";

function upArtisan(): string
{
    return <<<'PHP'
<?php
$cmd = $argv[1] ?? '';
if (! in_array($cmd, ['migrate', 'ignis:update:health'], true)) { echo "ok $cmd\n"; exit(0); }
if ($cmd === 'ignis:update:health' && is_file(__DIR__.'/app/BROKEN')) { fwrite(STDERR, "PHP Fatal error: broken release\n"); exit(255); }
$db = getenv('DB_DATABASE');
if (! $db || ! is_file($db)) {
    preg_match('/^DB_PATH=(.*)$/m', (string) file_get_contents(__DIR__.'/.env'), $m);
    $db = trim($m[1]);
}
$pdo = new PDO('sqlite:'.$db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$ran = $pdo->query('select migration from migrations')->fetchAll(PDO::FETCH_COLUMN);
$files = glob(__DIR__.'/database/migrations/*.sql');
sort($files);
$pending = 0;
foreach ($files as $f) {
    $name = basename($f, '.sql');
    if (in_array($name, $ran, true)) continue;
    if ($cmd === 'ignis:update:health' || str_ends_with($name, '_skip')) { $pending++; continue; }
    try { $pdo->exec(file_get_contents($f)); } catch (PDOException $e) { fwrite(STDERR, "migration $name failed: ".$e->getMessage()."\n"); exit(1); }
    $pdo->prepare('insert into migrations (migration, batch) values (?, 2)')->execute([$name]);
    echo "migrated $name\n";
}
if ($cmd === 'ignis:update:health') {
    echo json_encode(['version' => trim(file_get_contents(__DIR__.'/VERSION')), 'down' => [], 'queue' => 'ok', 'pending' => $pending]), "\n";
}
PHP;
}

/** @return array<string, string> files of a release tree */
function upCode(string $version, array $migrations): array
{
    $files = [
        'VERSION' => "$version\n",
        'artisan' => upArtisan(),
        'vendor/autoload.php' => "<?php // vendor $version",
        'public/build/manifest.json' => '{"v":"'.$version.'"}',
        'public/index.php' => "<?php // index $version",
        'app/Models/User.php' => "<?php // user $version",
        'config/app.php' => "<?php // config $version",
        'routes/web.php' => "<?php // routes $version",
    ];
    foreach ($migrations as $name => $sql) {
        $files["database/migrations/$name.sql"] = $sql;
    }

    return $files;
}

/**
 * @param  array<string, string>  $newMigrations  migrations of release 2.0.0 (besides 0001)
 * @return array{app: string, db: string, backups: string, root: string}
 */
function upSetUp(array $newMigrations = ['0002_add_color' => "ALTER TABLE things ADD COLUMN color text;\nUPDATE things SET color = 'red';"], bool $fake = true): array
{
    MaintenanceSandbox::enter();
    $root = sys_get_temp_dir().'/ignis-up-'.bin2hex(random_bytes(4));
    register_shutdown_function(fn () => (new Filesystem)->deleteDirectory($root));
    $app = "$root/app";
    $db = "$app/database/database.sqlite";
    $first = ['0001_create_things' => 'CREATE TABLE things (id integer primary key, name text);'];
    foreach (upCode('1.0.0', $first) as $name => $content) {
        @mkdir(dirname("$app/$name"), 0777, true);
        file_put_contents("$app/$name", $content);
    }
    mkdir("$app/bootstrap/cache", 0777, true);
    mkdir("$app/storage/app/public", 0777, true);
    file_put_contents("$app/storage/app/public/upload.png", 'upload');
    file_put_contents("$app/.env", UP_ENV."DB_PATH=$db\n");
    touch($db);

    config([
        'database.connections.update_test' => ['driver' => 'sqlite', 'database' => $db, 'prefix' => '', 'foreign_key_constraints' => true],
        'database.default' => 'update_test',
        'ignis.update.app_path' => $app,
        'ignis.update.driver' => 'files',
        'ignis.update.state_path' => "$root/state/state.json",
        'ignis.update.backup_path' => "$root/backups",
        'ignis.update.public_key' => UpdateFixtures::publicKey(),
        'ignis.release_repo' => UpdateFixtures::REPO,
    ]);
    $c = DB::connection('update_test');
    $c->statement('create table migrations (id integer primary key, migration text, batch integer)');
    $c->statement('CREATE TABLE things (id integer primary key, name text)');
    $c->statement('create table jobs (id integer primary key)');
    $c->statement('create table failed_jobs (id integer primary key)');
    $c->table('migrations')->insert(['migration' => '0001_create_things', 'batch' => 1]);
    $c->table('things')->insert([['name' => 'alpha'], ['name' => 'beta']]);
    DB::purge('update_test');

    $zip = UpdateFixtures::zip(upCode('2.0.0', $first + $newMigrations));
    if ($fake) {
        UpdateFixtures::fake(archive: $zip);
    }
    app()->instance(HealthGate::class, new HealthGate(null, 2, 10));

    return ['app' => $app, 'db' => $db, 'backups' => "$root/backups", 'root' => $root];
}

/** @return array<string, string> code files => sha256 (no storage, database file, backups or .update-old) */
function upTree(string $app): array
{
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($app, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($it as $f) {
        $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($app) + 1));
        if (preg_match('~^(storage|\.update-old)(/|$)|^database/database\.sqlite~', $rel)) {
            continue;
        }
        $out[$rel] = $f->isDir() ? 'dir' : hash_file('sha256', $f->getPathname());
    }
    ksort($out);

    return $out;
}

function upUpdater(): UpdateRunner
{
    return app(UpdateRunner::class);
}

function upExpectUnchanged(string $app, array $tree): void
{
    $c = DB::connection('update_test');
    expect(trim((string) file_get_contents("$app/VERSION")))->toBe('1.0.0')
        ->and(upTree($app))->toBe($tree)
        ->and(file_get_contents("$app/.env"))->toStartWith(UP_ENV)
        ->and(file_get_contents("$app/storage/app/public/upload.png"))->toBe('upload')
        ->and($c->table('things')->orderBy('id')->pluck('name')->all())->toBe(['alpha', 'beta'])
        ->and($c->getSchemaBuilder()->hasColumn('things', 'color'))->toBeFalse()
        ->and($c->table('migrations')->pluck('migration')->all())->toBe(['0001_create_things'])
        ->and(app(Maintenance::class)->isActive())->toBeFalse()
        ->and(glob("$app/.update-old/*"))->toBe([]);
}

it('successful update ends in done with new VERSION and migrated db', function () {
    ['app' => $app, 'backups' => $backups] = upSetUp();
    $env = file_get_contents("$app/.env");

    upUpdater()->run('2.0.0');

    $s = app(UpdateState::class)->read();
    expect($s['status'])->toBe('done', (string) $s['error'])
        ->and(array_column($s['steps'], 'name'))->toBe(['checking', 'downloading', 'verifying', 'backing_up', 'trial_migration', 'switching', 'migrating', 'health_check'])
        ->and(trim((string) file_get_contents("$app/VERSION")))->toBe('2.0.0')
        ->and(file_get_contents("$app/app/Models/User.php"))->toBe('<?php // user 2.0.0')
        ->and(file_get_contents("$app/.env"))->toBe($env)
        ->and(file_get_contents("$app/storage/app/public/upload.png"))->toBe('upload')
        ->and(DB::connection('update_test')->table('things')->pluck('color')->all())->toBe(['red', 'red'])
        ->and(app(Maintenance::class)->isActive())->toBeFalse()
        ->and(glob("$backups/*"))->toHaveCount(1)
        ->and(glob("$app/.update-old/*"))->toBe([]);
    $log = (string) file_get_contents((string) glob(storage_path('logs/update-*.log'))[0]);
    expect($log)->toContain('migrated 0002_add_color')->not->toContain('envSecret99')->not->toContain('c2VjcmV0');
});

it('failure injected at each step leaves old VERSION, old db rows, .env intact and maintenance off', function (string $step, string $status) {
    ['app' => $app, 'db' => $db] = upSetUp();
    $tree = upTree($app);
    $envHash = hash_file('sha256', "$app/.env");
    $dbHash = hash_file('sha256', $db);
    $updater = upUpdater();
    $updater->fault = function (string $at) use ($step) {
        if ($at === $step) {
            throw new RuntimeException("injected at $at password=hunter2secret");
        }
    };

    $updater->run('2.0.0');

    $s = app(UpdateState::class)->read();
    expect($s['status'])->toBe($status)->and($s['step'])->toBe($step)
        ->and($s['error'])->toContain($step)->not->toContain('hunter2secret')
        ->and($s['rolled_back'])->toBe($status === 'rolled_back')
        ->and(glob(config('ignis.update.backup_path').'/*'))->toHaveCount($status === 'failed' ? 0 : 1)
        ->and(hash_file('sha256', "$app/.env"))->toBe($envHash)
        ->and(hash_file('sha256', $db))->toBe($status === 'failed' ? $dbHash : hash_file('sha256', glob(config('ignis.update.backup_path').'/*/db.sqlite')[0]));
    upExpectUnchanged($app, $tree);
})->with([
    ['checking', 'failed'],
    ['downloading', 'failed'],
    ['verifying', 'failed'],
    ['backing_up', 'failed'],
    ['trial_migration', 'failed'],
    ['switching', 'rolled_back'],
    ['migrating', 'rolled_back'],
    ['health_check', 'rolled_back'],
]);

it('a manipulated release is refused before any change', function (array $fake) {
    ['app' => $app, 'backups' => $backups] = upSetUp(fake: false);
    $tree = upTree($app);
    UpdateFixtures::fake(...$fake);

    upUpdater()->run('2.0.0');

    $s = app(UpdateState::class)->read();
    expect($s['status'])->toBe('failed')->and($s['step'])->toBe('downloading')->and(glob("$backups/*"))->toBe([]);
    upExpectUnchanged($app, $tree);
})->with([
    'bad signature' => [['signature' => base64_encode(str_repeat('x', 64))]],
    'archive changed' => [['archive' => 'other bytes', 'manifest' => ['sha256' => str_repeat('a', 64)]]],
    'downgrade' => [['manifest' => ['version' => '0.9.0'], 'release' => ['tag_name' => 'v0.9.0']]],
]);

it('an archive with the wrong VERSION is refused in verifying', function () {
    ['app' => $app] = upSetUp(fake: false);
    $tree = upTree($app);
    UpdateFixtures::fake(archive: UpdateFixtures::zip(upCode('2.0.1', [])));

    upUpdater()->run('2.0.0');

    $s = app(UpdateState::class)->read();
    expect($s['status'])->toBe('failed')->and($s['step'])->toBe('verifying')->and($s['error'])->toContain('VERSION');
    upExpectUnchanged($app, $tree);
});

it('failing real migration triggers rollback to byte-identical db', function () {
    ['app' => $app, 'db' => $db] = upSetUp(['0002_broken' => "ALTER TABLE things ADD COLUMN color text;\nINSERT INTO missing_table VALUES (1);"]);
    $tree = upTree($app);

    upUpdater()->run('2.0.0', skipTrial: true);

    $s = app(UpdateState::class)->read();
    expect($s['status'])->toBe('rolled_back')->and($s['step'])->toBe('migrating')->and($s['error'])->toContain('migrate');
    $backup = glob(config('ignis.update.backup_path').'/*/db.sqlite')[0];
    expect(hash_file('sha256', $db))->toBe(hash_file('sha256', $backup));
    upExpectUnchanged($app, $tree);
});

it('the trial migration catches a broken migration before any change', function () {
    ['app' => $app] = upSetUp(['0002_broken' => 'INSERT INTO missing_table VALUES (1);']);
    $tree = upTree($app);

    upUpdater()->run('2.0.0');

    $s = app(UpdateState::class)->read();
    expect($s['status'])->toBe('failed')->and($s['step'])->toBe('trial_migration')->and($s['error'])->toBe(__('update.error.trial_failed'));
    upExpectUnchanged($app, $tree);
});

it('failing health gate triggers rollback', function () {
    ['app' => $app] = upSetUp(['0002_add_color' => 'ALTER TABLE things ADD COLUMN color text;', '0003_late_skip' => 'SELECT 1;']);
    $tree = upTree($app);

    upUpdater()->run('2.0.0');

    $s = app(UpdateState::class)->read();
    expect($s['status'])->toBe('rolled_back')->and($s['step'])->toBe('health_check')->and($s['error'])->toContain('1 migrations pending');
    upExpectUnchanged($app, $tree);
});

it('a new version that does not boot fails the health probe and is rolled back', function () {
    ['app' => $app] = upSetUp(fake: false);
    $tree = upTree($app);
    $files = upCode('2.0.0', ['0001_create_things' => 'CREATE TABLE things (id integer primary key, name text);']) + ['app/BROKEN' => 'x'];
    UpdateFixtures::fake(archive: UpdateFixtures::zip($files));

    upUpdater()->run('2.0.0');

    $s = app(UpdateState::class)->read();
    expect($s['status'])->toBe('rolled_back')->and($s['step'])->toBe('health_check')->and($s['error'])->toContain('does not boot');
    upExpectUnchanged($app, $tree);
});

it('rollback failure ends in failed_rollback with maintenance on and backup path in message', function () {
    ['app' => $app] = upSetUp();
    $updater = upUpdater();
    $dumper = new class('update_test') extends SqliteDumper
    {
        public function restore(string $sourceFile): void
        {
            throw new RuntimeException('database locked');
        }
    };
    $updater->backups = new BackupManager($dumper, fn () => PHP_INT_MAX);
    $updater->fault = fn (string $at) => $at === 'migrating' ? throw new RuntimeException('boom') : null;

    $updater->run('2.0.0');

    $s = app(UpdateState::class)->read();
    $set = glob(config('ignis.update.backup_path').'/*')[0];
    expect($s['status'])->toBe('failed_rollback')->and($s['rolled_back'])->toBeFalse()
        ->and($s['error'])->toContain(__('update.error.restore_db_failed'))->toContain($set)
        ->and(app(Maintenance::class)->isActive())->toBeTrue()
        ->and(is_dir($set))->toBeTrue()
        ->and(trim((string) file_get_contents("$app/VERSION")))->toBe('1.0.0');

    expect(fn () => upUpdater()->run('2.0.0'))->toThrow(fn (UpdateException $e) => expect($e->reason)->toBe('rollback_needed')
        ->and($e->getMessage())->toContain('ignis:update:rollback --force'));

    $this->artisan('ignis:update:rollback --force')->assertSuccessful();
    expect(app(UpdateState::class)->read()['status'])->toBe('rolled_back')->and(app(Maintenance::class)->isActive())->toBeFalse()
        ->and(trim((string) file_get_contents("$app/VERSION")))->toBe('1.0.0');
});

it('trial_unavailable aborts unless skipTrial', function () {
    ['app' => $app] = upSetUp();
    $tree = upTree($app);
    $noTrial = new class('update_test') extends SqliteDumper
    {
        public function available(bool $forRestore = false): ?string
        {
            return 'no CREATE rights';
        }
    };
    $updater = upUpdater();
    $updater->trial = new TrialMigration($noTrial);

    $updater->run('2.0.0');

    $s = app(UpdateState::class)->read();
    expect($s['status'])->toBe('failed')->and($s['step'])->toBe('trial_migration')->and($s['error'])->toBe(__('update.error.trial_unavailable'));
    expect($s['reason'])->toBe('trial_unavailable');
    upExpectUnchanged($app, $tree);

    $updater->run('2.0.0', skipTrial: true);
    expect(app(UpdateState::class)->read()['status'])->toBe('done', (string) app(UpdateState::class)->read()['error'])->and(trim((string) file_get_contents("$app/VERSION")))->toBe('2.0.0');
});

it('second update while locked is refused', function () {
    ['app' => $app] = upSetUp();
    $tree = upTree($app);
    $other = new UpdateLock;
    $other->acquire();

    expect(fn () => upUpdater()->run('2.0.0'))->toThrow(fn (UpdateException $e) => expect($e->reason)->toBe('busy'));
    expect(app(UpdateState::class)->read()['status'])->toBe('idle');
    Http::assertNothingSent();
    upExpectUnchanged($app, $tree);

    $this->artisan('ignis:update:run 2.0.0')->assertFailed();
    $this->artisan('ignis:update:rollback --force')->assertFailed();
    $log = implode("\n", array_map('file_get_contents', glob(UpdateLog::dir().'/update-*.log') ?: []));
    expect($log)->toContain('update run could not start: busy')->toContain('rollback could not start: busy');
    $other->release();
});

it('git checkout, dev version or placeholder key is refused before any change', function (Closure $break) {
    ['app' => $app, 'backups' => $backups] = upSetUp();
    $break($app);
    $tree = upTree($app);

    upUpdater()->run('2.0.0');

    $s = app(UpdateState::class)->read();
    expect($s['status'])->toBe('failed')->and($s['step'])->toBe('checking')->and(glob("$backups/*"))->toBe([])
        ->and(app(Maintenance::class)->isActive())->toBeFalse()->and(upTree($app))->toBe($tree);
    Http::assertNothingSent();
})->with([
    'git checkout' => [fn (string $app) => mkdir("$app/.git")],
    'dev version' => [fn (string $app) => unlink("$app/VERSION")],
    'placeholder key' => [fn () => config(['ignis.update.public_key' => 'PLACEHOLDER'])],
]);

it('recover after killed run restores', function (int $crashAfterMoves) {
    ['app' => $app, 'db' => $db] = upSetUp();
    $tree = upTree($app);
    $state = app(UpdateState::class);
    $state->begin('1.0.0', '2.0.0');
    foreach (['checking', 'downloading', 'verifying', 'backing_up', 'trial_migration', 'switching'] as $step) {
        $state->step($step);
    }
    app(Maintenance::class)->enable();
    (new BackupManager(DatabaseDumper::for(), fn () => PHP_INT_MAX))->create('1.0.0', '2.0.0');
    $stage = dirname($app).'/stage';
    foreach (upCode('2.0.0', ['0001_create_things' => '', '0002_x' => '']) as $name => $content) {
        @mkdir(dirname("$stage/$name"), 0777, true);
        file_put_contents("$stage/$name", $content);
    }
    DB::connection('update_test')->table('things')->insert(['name' => 'written by the new version']);
    DB::purge('update_test');
    $swapper = new FlatSwapper;
    $moves = 0;
    $swapper->rename = function (string $a, string $b) use (&$moves, $crashAfterMoves): bool {
        if ($moves++ === $crashAfterMoves) {
            throw new LogicException('killed');
        }

        return @rename($a, $b);
    };
    try {
        $swapper->swap($stage);
    } catch (LogicException) {
    }
    file_put_contents(dirname((string) config('ignis.update.state_path')).'/update.lock.pid', '99999999||deadbeef');
    expect($state->isStale(new UpdateLock))->toBeTrue();

    $this->artisan('ignis:update:recover')->assertSuccessful();

    $s = $state->read();
    expect($s['status'])->toBe('rolled_back')->and($s['error'])->toBe(__('update.error.interrupted'));
    upExpectUnchanged($app, $tree);
})->with([
    'half swapped' => [4],
    'fully swapped' => [1000],
]);

it('recover does nothing when no update is unfinished and refuses while the owner lives', function () {
    upSetUp();
    $this->artisan('ignis:update:recover')->expectsOutput('nothing to recover')->assertSuccessful();

    $state = app(UpdateState::class);
    $state->begin('1.0.0', '2.0.0');
    $owner = new UpdateLock;
    $owner->acquire();
    $this->artisan('ignis:update:recover')->assertFailed();
    $this->artisan('ignis:update:recover --force')->expectsConfirmation('Only continue if no update process is running any more. Take over the update lock?', 'no')->assertFailed();
    expect($state->read()['status'])->toBe('running');
    $owner->release();
});

it('manual rollback after a successful update restores the old version and database', function () {
    ['app' => $app] = upSetUp();
    $tree = upTree($app);
    upUpdater()->run('2.0.0');
    expect(app(UpdateState::class)->read()['status'])->toBe('done');

    $this->artisan('ignis:update:rollback')->assertFailed();
    expect(trim((string) file_get_contents("$app/VERSION")))->toBe('2.0.0');

    $this->artisan('ignis:update:rollback --force')->assertSuccessful();

    expect(app(UpdateState::class)->read()['status'])->toBe('rolled_back');
    upExpectUnchanged($app, $tree);
    $this->artisan('ignis:update:rollback --force')->assertFailed();
});

it('the run command reports the result', function () {
    ['app' => $app] = upSetUp();

    $this->artisan('ignis:update:run 2.0.0')->expectsOutput('Updated to 2.0.0.')->assertSuccessful();
    $this->artisan('ignis:update:run 3.0.0')->assertFailed();
});

function upReleaseLayout(bool $appPathIsRelease = false): array
{
    ['app' => $flat, 'root' => $root] = upSetUp();
    $release = "$root/releases/1.0.0";
    mkdir("$root/releases");
    mkdir("$root/shared");
    rename($flat, $release);
    rename("$release/.env", "$root/shared/.env");
    rename("$release/storage", "$root/shared/storage");
    rename("$release/database/database.sqlite", "$root/shared/database.sqlite");
    $db = "$root/shared/database.sqlite";
    file_put_contents("$root/shared/.env", UP_ENV."DB_PATH=$db\n");
    if (! @symlink('../../shared/.env', "$release/.env") || ! @symlink('../../shared/storage', "$release/storage") || ! @symlink("$root/releases/1.0.0", "$root/current")) {
        test()->markTestSkipped('symlinks not permitted here');
    }
    config(['database.connections.update_test.database' => $db, 'ignis.update.app_path' => $appPathIsRelease ? realpath($release) : "$root/current"]);
    DB::purge('update_test');

    return ['root' => $root, 'current' => "$root/current", 'db' => $db];
}

it('release layout: the update switches current, a failure switches it back', function () {
    ['root' => $root, 'current' => $current] = upReleaseLayout();
    $tree = upTree($current);
    $updater = upUpdater();
    $updater->fault = fn (string $at) => $at === 'health_check' ? throw new RuntimeException('boom') : null;

    $updater->run('2.0.0');

    expect(app(UpdateState::class)->read()['status'])->toBe('rolled_back')
        ->and(realpath($current))->toBe(realpath("$root/releases/1.0.0"))->and(is_dir("$root/releases/2.0.0"))->toBeFalse()
        ->and(is_link("$current/.env"))->toBeTrue();
    upExpectUnchanged($current, $tree);

    upUpdater()->run('2.0.0');

    expect(app(UpdateState::class)->read()['status'])->toBe('done', (string) app(UpdateState::class)->read()['error'])
        ->and(readlink($current))->toBe("$root/releases/2.0.0")
        ->and(file_get_contents("$current/.env"))->toStartWith(UP_ENV)
        ->and(DB::connection('update_test')->table('things')->pluck('color')->all())->toBe(['red', 'red']);
});

it('recover after a run killed before switching only marks it failed and switches maintenance off', function () {
    ['app' => $app, 'backups' => $backups] = upSetUp();
    $tree = upTree($app);
    $state = app(UpdateState::class);
    $state->begin('1.0.0', '2.0.0');
    foreach (['checking', 'downloading', 'verifying', 'backing_up', 'trial_migration'] as $step) {
        $state->step($step);
    }
    app(Maintenance::class)->enable();
    (new BackupManager(DatabaseDumper::for(), fn () => PHP_INT_MAX))->create('1.0.0', '2.0.0');
    mkdir("$app/.update-old/.work-dead/stage", 0777, true);

    $this->artisan('ignis:update:recover')->assertSuccessful();

    $s = $state->read();
    expect($s['status'])->toBe('failed')->and($s['step'])->toBe('trial_migration')->and($s['error'])->toBe(__('update.error.interrupted'))
        ->and(glob("$backups/*"))->toBe([])->and(is_dir("$app/.update-old/.work-dead"))->toBeFalse();
    upExpectUnchanged($app, $tree);
});

it('release layout with the default app path (the resolved release dir) migrates and checks the new release', function () {
    ['root' => $root, 'current' => $current] = upReleaseLayout(appPathIsRelease: true);

    upUpdater()->run('2.0.0');

    $s = app(UpdateState::class)->read();
    expect($s['status'])->toBe('done', (string) $s['error'])
        ->and(readlink($current))->toBe("$root/releases/2.0.0")
        ->and(DB::connection('update_test')->table('migrations')->pluck('migration')->all())->toBe(['0001_create_things', '0002_add_color'])
        ->and(DB::connection('update_test')->table('things')->pluck('color')->all())->toBe(['red', 'red']);
    $log = (string) file_get_contents((string) glob(storage_path('logs/update-*.log'))[0]);
    expect($log)->toContain('migrated 0002_add_color');
});

it('release layout with the SQLite file inside the release is refused before any change', function () {
    ['root' => $root, 'current' => $current, 'db' => $shared] = upReleaseLayout();
    $db = "$root/releases/1.0.0/database/database.sqlite";
    rename($shared, $db);
    config(['database.connections.update_test.database' => $db]);
    DB::purge('update_test');
    $tree = upTree($current);

    expect(upUpdater()->preflight()?->reason)->toBe('release_layout_db');
    upUpdater()->run('2.0.0');

    $s = app(UpdateState::class)->read();
    expect($s['status'])->toBe('failed')->and($s['step'])->toBe('checking')->and($s['error'])->toBe(__('update.error.release_layout_db'))->and($s['reason'])->toBe('release_layout_db')
        ->and(upTree($current))->toBe($tree)->and(glob(config('ignis.update.backup_path').'/*'))->toBe([]);
});

it('a state write failing after the commit never rolls back; recover marks it done', function () {
    ['app' => $app] = upSetUp();
    $state = new class extends UpdateState
    {
        public int $fails = 3;

        public function done(): void
        {
            if ($this->fails-- > 0) {
                throw new RuntimeException('disk full');
            }
            parent::done();
        }
    };
    app()->instance(UpdateState::class, $state);

    upUpdater()->run('2.0.0');

    expect($state->read()['status'])->toBe('running')
        ->and(trim((string) file_get_contents("$app/VERSION")))->toBe('2.0.0')
        ->and(DB::connection('update_test')->table('things')->pluck('color')->all())->toBe(['red', 'red'])
        ->and(app(Maintenance::class)->isActive())->toBeFalse()
        ->and($state->isStale(new UpdateLock))->toBeTrue();

    $this->artisan('ignis:update:recover')->expectsOutput('the update had completed; marked as done')->assertSuccessful();
    expect($state->read()['status'])->toBe('done')->and(trim((string) file_get_contents("$app/VERSION")))->toBe('2.0.0')
        ->and(DB::connection('update_test')->table('things')->pluck('color')->all())->toBe(['red', 'red']);
});

it('revert.php restores a half-done swap without the framework', function () {
    ['app' => $app, 'db' => $db] = upSetUp();
    $tree = upTree($app);
    $env = hash_file('sha256', "$app/.env");
    $set = (new BackupManager(DatabaseDumper::for(), fn () => PHP_INT_MAX))->create('1.0.0', '2.0.0');
    $stage = dirname($app).'/stage';
    foreach (upCode('2.0.0', ['0001_create_things' => '']) as $name => $content) {
        @mkdir(dirname("$stage/$name"), 0777, true);
        file_put_contents("$stage/$name", $content);
    }
    DB::connection('update_test')->table('things')->insert(['name' => 'new data']);
    DB::purge('update_test');
    $swapper = new FlatSwapper;
    $swapper->recovery = ['db_type' => 'sqlite', 'db_file' => $db, 'db_backup' => "$set->path/db.sqlite", 'env' => "$app/.env", 'env_backup' => "$set->path/env"];
    $moves = 0;
    $swapper->rename = function (string $a, string $b) use (&$moves): bool {
        if ($moves++ === 4) {
            throw new LogicException('killed');
        }

        return @rename($a, $b);
    };
    try {
        $swapper->swap($stage);
    } catch (LogicException) {
    }
    file_put_contents("$app/.env", 'APP_KEY=broken');
    expect(is_dir("$app/app"))->toBeFalse()->and(is_dir("$app/config"))->toBeFalse()->and(is_file("$app/vendor/autoload.php"))->toBeTrue();

    $p = proc_open([PHP_BINARY, '.update-old/1.0.0/revert.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $app);
    $out = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
    $code = proc_close($p);

    expect($code)->toBe(0, $out)->and($out)->toContain('Code restored')->toContain('Database restored')
        ->and(upTree($app))->toBe($tree)
        ->and(hash_file('sha256', $db))->toBe(hash_file('sha256', "$set->path/db.sqlite"))
        ->and(hash_file('sha256', "$app/.env"))->toBe($env)
        ->and($swapper->pending())->toBe([]);
});

it('recover of a crashed manual rollback uses the backup recorded in the state', function () {
    ['app' => $app] = upSetUp();
    $tree = upTree($app);
    upUpdater()->run('2.0.0');
    $set = glob(config('ignis.update.backup_path').'/*')[0];
    $this->travel(10)->minutes();
    $state = app(UpdateState::class);
    $state->begin('2.0.0', '1.0.0');
    $state->annotate(['backup' => $set]);
    $state->beginRollback('rolling_back', 'manual');
    app(Maintenance::class)->enable();
    file_put_contents(dirname((string) config('ignis.update.state_path')).'/update.lock.pid', '99999999||deadbeef');

    $this->artisan('ignis:update:recover')->expectsOutput('rolled back')->assertSuccessful();

    expect($state->read()['status'])->toBe('rolled_back');
    upExpectUnchanged($app, $tree);
});

it('a failing swap revert falls back to the code backup and clears the journal', function () {
    ['app' => $app] = upSetUp();
    $tree = upTree($app);
    $armed = false;
    $swapper = new FlatSwapper;
    $swapper->rename = function (string $a, string $b) use (&$armed): bool {
        return ! $armed && @rename($a, $b);
    };
    app()->instance(Swapper::class, $swapper);
    $updater = upUpdater();
    $updater->fault = function (string $at) use (&$armed) {
        if ($at === 'migrating') {
            $armed = true;
            throw new RuntimeException('boom');
        }
    };

    $updater->run('2.0.0');

    expect(app(UpdateState::class)->read()['status'])->toBe('rolled_back')->and($swapper->pending())->toBe([]);
    upExpectUnchanged($app, $tree);
    $this->artisan('ignis:update:recover')->expectsOutput('nothing to recover')->assertSuccessful();
});

it('maintenance mode the admin had on stays on; recover switches off one left behind by a done run', function () {
    ['app' => $app] = upSetUp();
    app(Maintenance::class)->enable();

    upUpdater()->run('2.0.0');

    $s = app(UpdateState::class)->read();
    expect($s['status'])->toBe('done')->and($s['maintenance_was_on'])->toBeTrue()->and(app(Maintenance::class)->isActive())->toBeTrue();
    $this->artisan('ignis:update:recover')->expectsOutput('nothing to recover');
    expect(app(Maintenance::class)->isActive())->toBeTrue();

    $raw = json_decode((string) file_get_contents((string) config('ignis.update.state_path')), true);
    file_put_contents((string) config('ignis.update.state_path'), json_encode(['maintenance_was_on' => false, 'maintenance_left_on' => true] + $raw));
    $this->artisan('ignis:update:recover')->expectsOutput('the update had finished; maintenance mode switched off');
    expect(app(Maintenance::class)->isActive())->toBeFalse()->and(app(UpdateState::class)->read()['maintenance_left_on'])->toBeFalse();
});

it('maintenance the admin switches on after a finished update is left alone by recover', function () {
    upSetUp();
    upUpdater()->run('2.0.0');
    expect(app(UpdateState::class)->read()['maintenance_left_on'])->toBeFalse();
    app(Maintenance::class)->enable();

    $this->artisan('ignis:update:recover')->expectsOutput('nothing to recover');

    expect(app(Maintenance::class)->isActive())->toBeTrue();
});

it('maintenance the admin had on stays on after a rollback', function () {
    ['app' => $app] = upSetUp();
    app(Maintenance::class)->enable();
    $updater = upUpdater();
    $updater->fault = fn (string $at) => $at === 'migrating' ? throw new RuntimeException('boom') : null;

    $updater->run('2.0.0');

    expect(app(UpdateState::class)->read()['status'])->toBe('rolled_back')->and(app(Maintenance::class)->isActive())->toBeTrue()
        ->and(trim((string) file_get_contents("$app/VERSION")))->toBe('1.0.0');
});

it('too little free space is refused before maintenance mode goes on', function () {
    ['app' => $app] = upSetUp();
    $tree = upTree($app);
    $updater = upUpdater();
    $updater->freeSpace = fn () => 1000;

    $updater->run('2.0.0');

    $s = app(UpdateState::class)->read();
    expect($s['status'])->toBe('failed')->and($s['step'])->toBe('verifying')->and($s['error'])->toContain('MB')
        ->and(glob(config('ignis.update.backup_path').'/*'))->toBe([]);
    upExpectUnchanged($app, $tree);
});

it('recover of a run that died between the commit and done switches maintenance off and clears the flag', function () {
    ['app' => $app] = upSetUp();
    $state = new class extends UpdateState
    {
        public function done(): void
        {
            throw new RuntimeException('killed before done');
        }
    };
    app()->instance(UpdateState::class, $state);
    upUpdater()->run('2.0.0');
    $state->annotate(['maintenance_left_on' => true]);
    app(Maintenance::class)->enable();
    app()->instance(UpdateState::class, new UpdateState);

    $this->artisan('ignis:update:recover')->expectsOutput('the update had completed; marked as done')->assertSuccessful();

    $s = app(UpdateState::class)->read();
    expect($s['status'])->toBe('done')->and($s['maintenance_left_on'])->toBeFalse()->and(app(Maintenance::class)->isActive())->toBeFalse()
        ->and(trim((string) file_get_contents("$app/VERSION")))->toBe('2.0.0');

    app(Maintenance::class)->enable();
    $this->artisan('ignis:update:recover')->expectsOutput('nothing to recover');
    expect(app(Maintenance::class)->isActive())->toBeTrue();
});

it('preflight is clean for a supported setup: file SQLite, files driver, flat layout', function () {
    ['app' => $app] = upSetUp();

    expect(upUpdater()->preflight())->toBeNull();

    mkdir("$app/.update-old/1.0.0", 0777, true);
    file_put_contents("$app/.update-old/1.0.0/swap.json", '{}');
    expect(upUpdater()->preflight()?->reason)->toBe('recover_needed');
});

it('preflight is clean for the release layout with the SQLite file in shared/', function (bool $appPathIsRelease) {
    upReleaseLayout($appPathIsRelease);

    expect(upUpdater()->preflight())->toBeNull();
})->with([[false], [true]]);

it('a Git checkout refuses recover and rollback unless an update left something to finish', function () {
    ['app' => $app] = upSetUp();
    upUpdater()->run('2.0.0');
    mkdir("$app/.git");
    $refusal = __('update.error.blocked', ['reason' => __('update.blocker.git_checkout')]);

    $this->artisan('ignis:update:rollback --force')->expectsOutputToContain($refusal)->assertFailed();
    $this->artisan('ignis:update:recover')->expectsOutputToContain($refusal)->assertFailed();
    expect(trim((string) file_get_contents("$app/VERSION")))->toBe('2.0.0')
        ->and(app(UpdateState::class)->read()['status'])->toBe('done');

    app(UpdateState::class)->begin('2.0.0', '3.0.0');
    $this->artisan('ignis:update:recover')->expectsOutput('interrupted before any change; marked as failed')->assertSuccessful();
});

it('recover --force marks a failed rollback as resolved after the restore by hand, only after confirmation', function () {
    ['app' => $app] = upSetUp();
    $state = app(UpdateState::class);
    $state->begin('1.0.0', '2.0.0');
    $state->beginRollback('switching', 'x');
    $state->finishRollback(false, 'restore failed');

    $this->artisan('ignis:update:recover')->assertFailed();
    expect($state->read()['status'])->toBe('failed_rollback');
    $question = 'Only continue if code, database and .env were restored by hand (docs/dev/update-recovery.md). Mark the failed rollback as resolved?';
    $this->artisan('ignis:update:recover --force')->expectsConfirmation($question, 'no')->assertFailed();
    expect($state->read()['status'])->toBe('failed_rollback');

    mkdir("$app/.update-old/1.0.0", 0777, true);
    file_put_contents("$app/.update-old/1.0.0/swap.json", '{"items":{},"keep":[]}');
    $this->artisan('ignis:update:recover --force --yes')->assertFailed();
    expect($state->read()['status'])->toBe('failed_rollback');
    (new Filesystem)->deleteDirectory("$app/.update-old");

    $this->artisan('ignis:update:recover --force --yes')->expectsOutputToContain('resolved')->assertSuccessful();
    $s = $state->read();
    expect($s['status'])->toBe('rolled_back')->and($s['error'])->toContain('restore failed')->and($s['reason'])->toBe('resolved_by_hand');
    $this->artisan('ignis:update:recover')->expectsOutput('nothing to recover')->assertSuccessful();
});
