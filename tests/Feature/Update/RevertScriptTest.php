<?php

use App\Update\FlatSwapper;
use Illuminate\Filesystem\Filesystem;

uses()->group('slow');

/** @return array{root: string, app: string, outside: string, journal: string} a flat app half swapped (crash after 3 renames) */
function rsSetUp(): array
{
    $root = sys_get_temp_dir().'/ignis-rs-'.bin2hex(random_bytes(4));
    register_shutdown_function(fn () => (new Filesystem)->deleteDirectory($root));
    $app = "$root/app";
    foreach (['app', 'config', 'database', 'public', 'vendor', '../stage/app', '../stage/config', '../outside', '../backup'] as $d) {
        mkdir("$app/$d", 0777, true);
    }
    file_put_contents("$app/VERSION", "1.0.0\n");
    file_put_contents("$app/app/a.php", 'old app');
    file_put_contents("$app/config/c.php", 'old config');
    file_put_contents("$app/vendor/v.php", 'old vendor');
    file_put_contents("$app/.env", 'APP_KEY=changed-by-new-version');
    $pdo = new PDO("sqlite:$app/database/database.sqlite");
    $pdo->exec('create table t (x integer); insert into t values (2)');
    $pdo = null;
    $backup = new PDO("sqlite:$root/backup/db.sqlite");
    $backup->exec('create table t (x integer); insert into t values (1)');
    $backup = null;
    file_put_contents("$root/backup/env", 'APP_KEY=original');
    file_put_contents("$root/backup/manifest.json", '{}');
    file_put_contents("$root/stage/app/a.php", 'new app');
    file_put_contents("$root/stage/config/c.php", 'new config');
    file_put_contents("$root/outside/victim.txt", 'victim');
    file_put_contents("$root/outside/.env", 'outside env');
    copy("$root/backup/db.sqlite", "$root/outside/victim.sqlite");
    file_put_contents("$root/outside/victim.sqlite", 'x', FILE_APPEND);

    $swapper = new FlatSwapper($app, ["$app/database/database.sqlite"]);
    $swapper->recovery = ['db_type' => 'sqlite', 'db_file' => "$app/database/database.sqlite", 'db_backup' => "$root/backup/db.sqlite", 'env' => "$app/.env", 'env_backup' => "$root/backup/env"];
    $moves = 0;
    $swapper->rename = function (string $a, string $b) use (&$moves): bool {
        if ($moves++ === 3) {
            throw new LogicException('killed');
        }

        return @rename($a, $b);
    };
    try {
        $swapper->swap("$root/stage");
    } catch (LogicException) {
    }

    return ['root' => $root, 'app' => $app, 'outside' => "$root/outside", 'journal' => "$app/.update-old/1.0.0/swap.json"];
}

/** @return array<string, string> */
function rsTree(string $dir): array
{
    $out = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $f) {
        $out[str_replace('\\', '/', substr($f->getPathname(), strlen($dir)))] = $f->isDir() ? 'dir' : hash_file('sha256', $f->getPathname());
    }
    ksort($out);

    return $out;
}

/** @return array{0: int, 1: string} exit code and output of php revert.php */
function rsRun(string $app): array
{
    $p = proc_open([PHP_BINARY, '.update-old/1.0.0/revert.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $app);
    $out = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);

    return [proc_close($p), $out];
}

it('the code list of revert.php is the one of FlatSwapper', function () {
    require_once app_path('Update/revert.php');

    expect(ignis_update_code())->toBe(FlatSwapper::CODE)
        ->and(FlatSwapper::CODE)->toContain('modules');
    foreach (['app/Update/ArchiveExtractor.php', 'tools/release/build-archive.php'] as $file) {
        expect((string) file_get_contents(base_path($file)))->toContain('FlatSwapper::CODE');
    }
});

it('an untampered journal is reverted with database and .env', function () {
    ['app' => $app, 'root' => $root] = rsSetUp();

    [$code, $out] = rsRun($app);

    expect($code)->toBe(0, $out)->and(file_get_contents("$app/app/a.php"))->toBe('old app')
        ->and(hash_file('sha256', "$app/database/database.sqlite"))->toBe(hash_file('sha256', "$root/backup/db.sqlite"))
        ->and(file_get_contents("$app/.env"))->toBe('APP_KEY=original');
});

it('a tampered journal never touches anything outside', function (Closure $tamper, bool $refused) {
    ['app' => $app, 'root' => $root, 'outside' => $outside, 'journal' => $journal] = rsSetUp();
    $data = json_decode((string) file_get_contents($journal), true);
    file_put_contents($journal, json_encode($tamper($data, $root)));
    $before = rsTree($outside);
    $appBefore = rsTree($app);

    [$code, $out] = rsRun($app);

    expect(rsTree($outside))->toBe($before, $out);
    if ($refused) {
        expect($code)->toBe(1)->and(rsTree($app))->toBe($appBefore);
    } else {
        expect($code)->toBe(0, $out)->and(file_get_contents("$app/app/a.php"))->toBe('old app');
    }
})->with([
    'item outside the code list' => [fn (array $d) => ['items' => ['../outside' => true] + $d['items']] + $d, true],
    'keep with ..' => [fn (array $d) => ['keep' => ['database/../../outside/victim.txt']] + $d, true],
    'keep absolute' => [fn (array $d, string $root) => ['keep' => ["$root/outside/victim.txt"]] + $d, true],
    'keep top-level only' => [fn (array $d) => ['keep' => ['.env']] + $d, true],
    'other app' => [fn (array $d, string $root) => ['app' => "$root/outside"] + $d, true],
    'env elsewhere' => [fn (array $d, string $root) => ['recovery' => ['env' => "$root/outside/.env"] + $d['recovery']] + $d, false],
    'db file elsewhere' => [fn (array $d, string $root) => ['recovery' => ['db_file' => "$root/outside/victim.sqlite"] + $d['recovery']] + $d, false],
    'backup with another name' => [fn (array $d, string $root) => ['recovery' => ['db_backup' => "$root/outside/victim.sqlite", 'env_backup' => "$root/outside/.env"] + $d['recovery']] + $d, false],
    'backup dir without manifest' => [fn (array $d, string $root) => ['recovery' => ['db_backup' => "$root/outside/db.sqlite", 'env_backup' => "$root/outside/env"] + $d['recovery']] + $d, false],
]);

it('the printed MariaDB command quotes the database name', function () {
    ['app' => $app, 'root' => $root, 'journal' => $journal] = rsSetUp();
    file_put_contents("$root/backup/db.sql", '-- dump');
    $data = json_decode((string) file_get_contents($journal), true);
    $data['recovery'] = ['db_type' => 'mariadb', 'database' => 'ignis; rm -rf /', 'db_backup' => "$root/backup/db.sql", 'env_backup' => "$root/backup/env"];
    file_put_contents($journal, json_encode($data));

    [$code, $out] = rsRun($app);

    expect($code)->toBe(0, $out)->and($out)->toContain(escapeshellarg('ignis; rm -rf /'));
});

it('.env is restored even when the database backup is unusable', function () {
    ['app' => $app, 'root' => $root, 'journal' => $journal] = rsSetUp();
    $data = json_decode((string) file_get_contents($journal), true);
    $data['recovery']['db_backup'] = "$root/nowhere/db.sqlite";
    file_put_contents($journal, json_encode($data));
    $db = hash_file('sha256', "$app/.update-old/1.0.0/database/database.sqlite");

    [$code, $out] = rsRun($app);

    expect($code)->toBe(0, $out)->and($out)->toContain('No usable database backup')
        ->and(file_get_contents("$app/.env"))->toBe('APP_KEY=original')
        ->and(hash_file('sha256', "$app/database/database.sqlite"))->toBe($db);
});
