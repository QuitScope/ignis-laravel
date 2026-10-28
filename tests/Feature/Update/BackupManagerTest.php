<?php

use App\Update\Backup\BackupManager;
use App\Update\Backup\DatabaseDumper;
use App\Update\Backup\SqliteDumper;
use App\Update\UpdateException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;

/**
 * A temporary app directory (code, storage, node_modules, .git, .env, a SQLite database inside database/), a backup
 * directory and the connection update_test pointing at that database. Never the project directory.
 *
 * @return array{app: string, db: string, backups: string}
 */
function bmSetUp(): array
{
    $root = sys_get_temp_dir().'/ignis-bm-'.bin2hex(random_bytes(4));
    register_shutdown_function(fn () => (new Filesystem)->deleteDirectory($root));
    $app = "$root/app";
    foreach (['app/Models', 'storage/app/public', 'node_modules/pkg', '.git', 'database', 'vendor/bin', 'resources/empty'] as $d) {
        mkdir("$app/$d", 0777, true);
    }
    file_put_contents("$app/app/Models/User.php", "<?php\n// user\n");
    file_put_contents("$app/artisan", "#!/usr/bin/env php\n<?php\n");
    file_put_contents("$app/vendor/bin/tool", random_bytes(3000));
    file_put_contents("$app/VERSION", "1.0.0\n");
    file_put_contents("$app/.env", "APP_KEY=base64:c2VjcmV0LWFwcC1rZXktZm9yLXRlc3RzLW9ubHktMTIzNA==\nDB_PASSWORD=envSecret99\n");
    file_put_contents("$app/storage/app/public/upload.png", 'old upload');
    file_put_contents("$app/node_modules/pkg/index.js", 'module');
    file_put_contents("$app/.git/HEAD", 'ref: refs/heads/main');

    $db = "$app/database/database.sqlite";
    touch($db);
    config([
        'database.connections.update_test' => ['driver' => 'sqlite', 'database' => $db, 'prefix' => '', 'foreign_key_constraints' => true],
        'ignis.update.app_path' => $app,
        'ignis.update.backup_path' => "$root/backups",
        'ignis.update.keep_backups' => 3,
    ]);
    $c = DB::connection('update_test');
    $c->statement('create table migrations (id integer primary key, migration text, batch integer)');
    $c->statement('create table things (id integer primary key, name text)');
    $c->table('migrations')->insert([['migration' => '0001_create_things', 'batch' => 1]]);
    $c->table('things')->insert([['name' => 'alpha'], ['name' => 'beta']]);

    return ['app' => $app, 'db' => $db, 'backups' => "$root/backups"];
}

function bmManager(?Closure $free = null): BackupManager
{
    return new BackupManager(DatabaseDumper::for('update_test'), $free ?? fn () => PHP_INT_MAX);
}

/** @return array<string, string> relative path => sha256 of the code files (what the backup covers) */
function bmCodeTree(string $app): array
{
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($app, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($it as $f) {
        $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($app) + 1));
        if (preg_match('~^(storage|node_modules|\.git|\.env)(/|$)|^database/database\.sqlite~', $rel)) {
            continue;
        }
        $out[$rel] = $f->isDir() ? 'dir' : hash_file('sha256', $f->getPathname());
    }
    ksort($out);

    return $out;
}

it('sqlite backup restores byte-identical data and migrations table', function () {
    ['app' => $app, 'db' => $db] = bmSetUp();
    $code = bmCodeTree($app);
    $set = bmManager()->create('1.0.0', '1.1.0');

    expect($set->fromVersion)->toBe('1.0.0')->and($set->toVersion)->toBe('1.1.0')->and($set->sizeBytes)->toBeGreaterThan(0)
        ->and(basename($set->path))->toMatch('/^\d{8}-\d{6}-1\.0\.0-to-1\.1\.0$/')
        ->and(file_exists("$set->path/db.sqlite"))->toBeTrue()->and(file_exists("$set->path/code.zip"))->toBeTrue();
    $manifest = (string) file_get_contents("$set->path/manifest.json");
    expect($manifest)->not->toContain('c2VjcmV0')->not->toContain('envSecret99');

    $zip = new ZipArchive;
    $zip->open("$set->path/code.zip");
    $names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $names[] = $zip->getNameIndex($i);
    }
    $zip->close();
    expect($names)->toContain('app/Models/User.php', 'resources/empty/', 'VERSION')
        ->and(preg_grep('~^(storage|node_modules|\.git|\.env)|database\.sqlite~', $names))->toBe([]);

    $c = DB::connection('update_test');
    $c->table('things')->insert(['name' => 'gamma']);
    $c->table('migrations')->insert(['migration' => '0002_add_new', 'batch' => 2]);
    $c->statement('create table new_table (id integer)');
    file_put_contents("$app/app/Models/User.php", '<?php // changed');
    file_put_contents("$app/app/New.php", '<?php // added');
    mkdir("$app/app/Added");
    unlink("$app/artisan");
    file_put_contents("$app/storage/app/public/upload.png", 'new upload');

    bmManager()->restore($set);

    expect(hash_file('sha256', $db))->toBe(hash_file('sha256', "$set->path/db.sqlite"));
    $c = DB::connection('update_test');
    expect($c->table('things')->orderBy('id')->pluck('name')->all())->toBe(['alpha', 'beta'])
        ->and($c->table('migrations')->pluck('migration')->all())->toBe(['0001_create_things'])
        ->and($c->getSchemaBuilder()->hasTable('new_table'))->toBeFalse()
        ->and(bmCodeTree($app))->toBe($code)
        ->and(file_get_contents("$app/storage/app/public/upload.png"))->toBe('new upload')
        ->and(file_get_contents("$app/node_modules/pkg/index.js"))->toBe('module')
        ->and(glob("$app/.update-restore-*"))->toBe([]);
});

it('.env restored', function () {
    ['app' => $app] = bmSetUp();
    $original = file_get_contents("$app/.env");
    $set = bmManager()->create('1.0.0', '1.1.0');
    file_put_contents("$app/.env", 'APP_KEY=changed');

    bmManager()->restore($set);

    expect(file_get_contents("$app/.env"))->toBe($original);
});

it('backup refuses when disk space too low', function () {
    ['backups' => $backups] = bmSetUp();
    $asked = null;

    $manager = bmManager(function (string $dir) use (&$asked) {
        $asked = $dir;

        return 1000;
    });

    expect(fn () => $manager->create('1.0.0', '1.1.0'))->toThrow(fn (UpdateException $e) => expect($e->reason)->toBe('disk_space'));
    expect($asked)->toBe($backups)->and(glob("$backups/*"))->toBe([]);
});

it('backup manifest checksums detect tampering on restore', function () {
    ['app' => $app, 'db' => $db] = bmSetUp();
    $set = bmManager()->create('1.0.0', '1.1.0');
    file_put_contents("$app/app/Models/User.php", '<?php // new version');
    DB::connection('update_test')->table('things')->insert(['name' => 'gamma']);
    $dbHash = hash_file('sha256', $db);
    file_put_contents("$set->path/code.zip", 'x', FILE_APPEND);

    expect(fn () => bmManager()->restore($set))->toThrow(fn (UpdateException $e) => expect($e->reason)->toBe('backup_checksum'));

    expect(file_get_contents("$app/app/Models/User.php"))->toBe('<?php // new version')
        ->and(hash_file('sha256', $db))->toBe($dbHash);

    unlink("$set->path/env");
    expect(fn () => bmManager()->restore($set))->toThrow(fn (UpdateException $e) => expect($e->reason)->toBe('backup_checksum'));
});

it('prune keeps 3 newest and never the active one', function () {
    ['backups' => $backups] = bmSetUp();
    $sets = [];
    foreach (['1.0.0', '1.1.0', '1.2.0', '1.3.0', '1.4.0'] as $i => $v) {
        $this->travelTo(now()->utc()->setDate(2026, 10, 1 + $i));
        $sets[] = bmManager()->create($v, "$v-next");
    }
    $this->travelBack();

    expect(array_map(fn ($s) => $s->fromVersion, bmManager()->list()))->toBe(['1.4.0', '1.3.0', '1.2.0', '1.1.0', '1.0.0']);

    bmManager()->prune($sets[0]);

    expect(array_map(fn ($s) => $s->fromVersion, bmManager()->list()))->toBe(['1.4.0', '1.3.0', '1.2.0', '1.0.0'])
        ->and(is_dir($sets[1]->path))->toBeFalse();
    bmManager()->prune();
    expect(count(glob("$backups/*")))->toBe(3);
});

it('backup directory is not world readable', function () {
    bmSetUp();
    $set = bmManager()->create('1.0.0', '1.1.0');

    foreach ([$set->path, ...glob("$set->path/*")] as $p) {
        expect(fileperms($p) & 0077)->toBe(0, $p);
    }
})->skip(PHP_OS_FAMILY === 'Windows', 'no POSIX permissions on Windows');

it('partial backup is removed when a step fails', function () {
    ['backups' => $backups] = bmSetUp();
    $dumper = new class('update_test') extends DatabaseDumper
    {
        public function dump(string $targetFile): void
        {
            file_put_contents($targetFile, 'half');
            throw new RuntimeException('disk full');
        }

        public function restore(string $sourceFile): void {}

        public function available(bool $forRestore = false): ?string
        {
            return null;
        }

        public function sizeBytes(): int
        {
            return 1;
        }

        public function fileName(): string
        {
            return 'db.sqlite';
        }
    };

    expect(fn () => (new BackupManager($dumper, fn () => PHP_INT_MAX))->create('1.0.0', '1.1.0'))
        ->toThrow(fn (UpdateException $e) => expect($e->reason)->toBe('backup_failed'));
    expect(glob("$backups/*"))->toBe([]);
});

it('missing sqlite file is reported before any change', function () {
    ['backups' => $backups, 'db' => $db] = bmSetUp();
    config(['database.connections.update_test.database' => $db.'.missing']);

    expect(DatabaseDumper::for('update_test')->available())->toBeString()
        ->and(fn () => bmManager()->create('1.0.0', '1.1.0'))->toThrow(fn (UpdateException $e) => expect($e->reason)->toBe('dump_unavailable'));
    expect(glob("$backups/*"))->toBe([]);
});

it('restore refuses without free space and changes nothing', function () {
    ['app' => $app] = bmSetUp();
    $set = bmManager()->create('1.0.0', '1.1.0');
    file_put_contents("$app/app/Models/User.php", '<?php // new version');
    $asked = null;
    $manager = bmManager(function (string $dir) use (&$asked) {
        $asked = $dir;

        return 10;
    });

    expect(fn () => $manager->restore($set))->toThrow(fn (UpdateException $e) => expect($e->reason)->toBe('disk_space'));
    expect($asked)->toBe($app)->and(file_get_contents("$app/app/Models/User.php"))->toBe('<?php // new version')
        ->and(glob("$app/.update-restore-*"))->toBe([]);
});

it('failing database restore leaves code and .env untouched', function () {
    ['app' => $app] = bmSetUp();
    $set = bmManager()->create('1.0.0', '1.1.0');
    file_put_contents("$app/app/Models/User.php", '<?php // new version');
    file_put_contents("$app/.env", 'APP_KEY=changed');
    $dumper = new class('update_test') extends SqliteDumper
    {
        public function restore(string $sourceFile): void
        {
            throw new RuntimeException('database locked');
        }
    };

    expect(fn () => (new BackupManager($dumper, fn () => PHP_INT_MAX))->restore($set))
        ->toThrow(fn (UpdateException $e) => expect($e->reason)->toBe('restore_db_failed'));
    expect(file_get_contents("$app/app/Models/User.php"))->toBe('<?php // new version')
        ->and(file_get_contents("$app/.env"))->toBe('APP_KEY=changed')
        ->and(glob("$app/.update-restore-*"))->toBe([]);
});

it('restore never writes through links planted by the new version', function () {
    ['app' => $app] = bmSetUp();
    $set = bmManager()->create('1.0.0', '1.1.0');
    $outside = dirname($app).'/outside';
    mkdir($outside);
    file_put_contents("$outside/keep.txt", 'keep');
    (new Filesystem)->deleteDirectory("$app/app/Models");
    if (! @symlink($outside, "$app/app/Models") || ! @symlink($outside, "$app/evil")) {
        $this->markTestSkipped('symlinks not permitted here');
    }

    bmManager()->restore($set);

    expect(scandir($outside))->toBe(['.', '..', 'keep.txt'])
        ->and(is_link("$app/app/Models"))->toBeFalse()
        ->and(file_get_contents("$app/app/Models/User.php"))->toBe("<?php\n// user\n")
        ->and(file_exists("$app/evil") || is_link("$app/evil"))->toBeFalse();
});

it('manifest with unsafe link paths or a missing .env copy is refused', function () {
    ['app' => $app] = bmSetUp();
    $set = bmManager()->create('1.0.0', '1.1.0');
    $manifest = json_decode((string) file_get_contents("$set->path/manifest.json"), true);

    foreach (['../outside', '/etc/x', 'a/../../b', 'C:/x'] as $bad) {
        file_put_contents("$set->path/manifest.json", json_encode(['links' => [$bad => '/tmp']] + $manifest));
        expect(fn () => bmManager()->restore($set))->toThrow(fn (UpdateException $e) => expect($e->reason)->toBe('backup_checksum'));
    }
    $noEnv = $manifest;
    unset($noEnv['files']['env']);
    file_put_contents("$set->path/manifest.json", json_encode($noEnv));
    expect(fn () => bmManager()->restore($set))->toThrow(fn (UpdateException $e) => expect($e->reason)->toBe('backup_checksum'));
});

it('sqlite restore recreates a missing database file', function () {
    ['db' => $db] = bmSetUp();
    $set = bmManager()->create('1.0.0', '1.1.0');
    DB::purge('update_test');
    unlink($db);

    bmManager()->restore($set);

    expect(DB::connection('update_test')->table('things')->count())->toBe(2);
});

it('prune removes aborted backups older than an hour and leftover restore copies stay out of the code', function () {
    ['backups' => $backups, 'db' => $db] = bmSetUp();
    file_put_contents("$db.restore-abcd1234", 'leftover');
    $set = bmManager()->create('1.0.0', '1.1.0');
    $zip = new ZipArchive;
    $zip->open("$set->path/code.zip");
    expect($zip->locateName('database/database.sqlite.restore-abcd1234'))->toBeFalse();
    $zip->close();

    mkdir("$backups/old-aborted");
    touch("$backups/old-aborted", time() - 7200);
    mkdir("$backups/fresh-aborted");

    bmManager()->prune();

    expect(is_dir("$backups/old-aborted"))->toBeFalse()->and(is_dir("$backups/fresh-aborted"))->toBeTrue()->and(is_dir($set->path))->toBeTrue();
});

it('restore without code puts back database and .env only', function () {
    ['app' => $app, 'db' => $db] = bmSetUp();
    $env = file_get_contents("$app/.env");
    $set = bmManager()->create('1.0.0', '1.1.0');
    DB::connection('update_test')->table('things')->insert(['name' => 'gamma']);
    file_put_contents("$app/.env", 'APP_KEY=changed');
    file_put_contents("$app/app/Models/User.php", '<?php // reverted elsewhere');

    bmManager()->restore($set, code: false);

    expect(hash_file('sha256', $db))->toBe(hash_file('sha256', "$set->path/db.sqlite"))
        ->and(file_get_contents("$app/.env"))->toBe($env)
        ->and(file_get_contents("$app/app/Models/User.php"))->toBe('<?php // reverted elsewhere')
        ->and(glob("$app/.update-restore-*"))->toBe([]);
});

it('a backup of an unknown manifest format is refused with its own reason before anything changes', function () {
    ['app' => $app] = bmSetUp();
    $set = bmManager()->create('1.0.0', '1.1.0');
    $manifest = json_decode((string) file_get_contents("$set->path/manifest.json"), true);
    expect($manifest['format'])->toBe(1);
    file_put_contents("$app/app/Models/User.php", '<?php // new version');

    foreach ([2, '1', null] as $format) {
        file_put_contents("$set->path/manifest.json", json_encode(['format' => $format] + $manifest));
        expect(fn () => bmManager()->restore($set))->toThrow(fn (UpdateException $e) => expect($e->reason)->toBe('backup_format')
            ->and($e->getMessage())->toContain(json_encode($format)));
    }
    expect(file_get_contents("$app/app/Models/User.php"))->toBe('<?php // new version');
});
