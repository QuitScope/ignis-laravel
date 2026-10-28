<?php

use App\Models\Role;
use App\Models\User;
use App\Update\HealthGate;
use App\Update\Maintenance;
use App\Update\ProcessUpdateStarter;
use App\Update\UpdateException;
use App\Update\UpdateLock;
use App\Update\UpdateRunner;
use App\Update\UpdateStarter;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Connection;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\MaintenanceSandbox;
use Tests\Support\UpdateFixtures;

uses(RefreshDatabase::class)->group('slow');

/** Runs the update in-process, against the temp database (the default connection only for the duration of the run). */
class E2eLauncher extends ProcessUpdateStarter
{
    public function start(string $version, bool $skipTrial = false): void
    {
        $this->inTempDb(fn () => app(UpdateRunner::class)->run($version, $skipTrial));
    }

    public function rollback(): void
    {
        $this->inTempDb(fn () => app(UpdateRunner::class)->rollbackToLatest());
    }

    private function inTempDb(Closure $run): void
    {
        $default = (string) config('database.default');
        $pdo = DB::connection($default)->getPdo();
        config(['database.default' => 'update_test']);
        try {
            $run();
        } finally {
            config(['database.default' => $default]);
            $connection = DB::connection($default);
            $connection->setPdo($pdo);
            Closure::bind(fn () => $this->transactions = 1, $connection, Connection::class)();
        }
    }
}

/** The real preflight judges the machine (the test database is in memory); the run itself checks its own database. */
class E2eUpdater extends UpdateRunner
{
    public function preflight(): ?UpdateException
    {
        return null;
    }
}

const E2E_ENV = "APP_KEY=base64:c2VjcmV0LWFwcC1rZXktZm9yLXRlc3RzLW9ubHktMTIzNA==\nDB_PASSWORD=envSecret99\n";

function e2eArtisan(): string
{
    return <<<'PHP'
<?php
$cmd = $argv[1] ?? '';
if (! in_array($cmd, ['migrate', 'ignis:update:health'], true)) { echo "ok $cmd\n"; exit(0); }
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
    if ($cmd === 'ignis:update:health') { $pending++; continue; }
    try { $pdo->exec(file_get_contents($f)); } catch (PDOException $e) { fwrite(STDERR, "migration $name failed: ".$e->getMessage()."\n"); exit(1); }
    $pdo->prepare('insert into migrations (migration, batch) values (?, 2)')->execute([$name]);
    echo "migrated $name\n";
}
if ($cmd === 'ignis:update:health') {
    echo json_encode(['version' => trim(file_get_contents(__DIR__.'/VERSION')), 'down' => [], 'queue' => 'ok', 'pending' => $pending]), "\n";
}
PHP;
}

/**
 * @param  array<string, string>  $migrations
 * @return array<string, string>
 */
function e2eCode(string $version, array $migrations): array
{
    $files = [
        'VERSION' => "$version\n",
        'artisan' => e2eArtisan(),
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

/** @return array{app: string, db: string} installed 1.0.0 with the release 2.0.0 waiting on the fake GitHub */
function e2eSetUp(string $migration): array
{
    MaintenanceSandbox::enter();
    $root = sys_get_temp_dir().'/ignis-e2e-'.bin2hex(random_bytes(4));
    register_shutdown_function(fn () => (new Filesystem)->deleteDirectory($root));
    $app = "$root/app";
    $db = "$app/database/database.sqlite";
    $first = ['0001_create_things' => 'CREATE TABLE things (id integer primary key, name text);'];
    foreach (e2eCode('1.0.0', $first) as $name => $content) {
        @mkdir(dirname("$app/$name"), 0777, true);
        file_put_contents("$app/$name", $content);
    }
    mkdir("$app/bootstrap/cache", 0777, true);
    mkdir("$app/storage/app/public", 0777, true);
    file_put_contents("$app/.env", E2E_ENV."DB_PATH=$db\n");
    touch($db);

    config([
        'database.connections.update_test' => ['driver' => 'sqlite', 'database' => $db, 'prefix' => '', 'foreign_key_constraints' => true],
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

    UpdateFixtures::fake(archive: UpdateFixtures::zip(e2eCode('2.0.0', $first + ['0002_change' => $migration])));
    app()->instance(HealthGate::class, new HealthGate(null, 2, 10));
    app()->instance(UpdateRunner::class, app()->make(E2eUpdater::class));
    app()->instance(UpdateStarter::class, new E2eLauncher);

    return ['app' => $app, 'db' => $db];
}

function e2eAdmin(): User
{
    $role = Role::create(['name' => 'E2E'.uniqid(), 'guard_name' => 'web', 'priority' => 500, 'color' => 'info']);
    $role->syncPermissions(['admin']);

    return User::factory()->withRole($role)->create();
}

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

it('click update runs the pipeline to done and the status endpoint shows it', function () {
    ['app' => $app] = e2eSetUp("ALTER TABLE things ADD COLUMN color text;\nUPDATE things SET color = 'red';");
    $admin = e2eAdmin();
    $env = file_get_contents("$app/.env");

    $this->actingAs($admin)->postJson('/settings/system/update', ['version' => '2.0.0'])->assertStatus(202);

    $status = $this->actingAs($admin)->getJson('/settings/system/update/status')->assertOk();
    expect($status->json('state.status'))->toBe('done', (string) $status->json('state.error'))
        ->and($status->json('state.from'))->toBe('1.0.0')
        ->and($status->json('state.to'))->toBe('2.0.0')
        ->and($status->json('state.error'))->toBeNull()
        ->and($status->json('installed'))->toBe('2.0.0')
        ->and($status->json('maintenance'))->toBeFalse()
        ->and(array_column($status->json('state.steps'), 'name'))->toContain('migrating', 'health_check')
        ->and(trim((string) file_get_contents("$app/VERSION")))->toBe('2.0.0')
        ->and(file_get_contents("$app/.env"))->toBe($env)
        ->and(DB::connection('update_test')->table('things')->pluck('color')->all())->toBe(['red', 'red'])
        ->and(app(Maintenance::class)->isActive())->toBeFalse()
        ->and((new UpdateLock)->ownerAlive())->toBeFalse();
});

it('click update with a failing migration is rolled back, visible in the status, nothing changed', function () {
    ['app' => $app, 'db' => $db] = e2eSetUp("ALTER TABLE things ADD COLUMN color text;\nINSERT INTO missing_table VALUES (1);");
    $admin = e2eAdmin();

    $this->actingAs($admin)->postJson('/settings/system/update', ['version' => '2.0.0', 'without_trial' => true])->assertStatus(202);

    $status = $this->actingAs($admin)->getJson('/settings/system/update/status')->assertOk();
    expect($status->json('state.status'))->toBe('rolled_back')
        ->and($status->json('state.step'))->toBe('migrating')
        ->and($status->json('state.rolled_back'))->toBeTrue()
        ->and($status->json('state.error'))->toContain('migrate')
        ->and($status->json('installed'))->toBe('1.0.0')
        ->and($status->json('maintenance'))->toBeFalse()
        ->and($status->json('backup_available'))->toBeFalse()
        ->and(trim((string) file_get_contents("$app/VERSION")))->toBe('1.0.0')
        ->and(file_get_contents("$app/app/Models/User.php"))->toBe('<?php // user 1.0.0')
        ->and(hash_file('sha256', $db))->toBe(hash_file('sha256', glob(config('ignis.update.backup_path').'/*/db.sqlite')[0]))
        ->and(DB::connection('update_test')->table('things')->orderBy('id')->pluck('name')->all())->toBe(['alpha', 'beta'])
        ->and(app(Maintenance::class)->isActive())->toBeFalse();
});
