<?php

use App\Update\Backup\DatabaseDumper;
use App\Update\TrialMigration;
use App\Update\UpdateException;
use App\Update\UpdateLog;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;

uses()->group('slow');

/**
 * A temporary SQLite database (connection update_trial) with a table things, and a temp dir for fixtures.
 *
 * @return array{root: string, db: string}
 */
function tmSetUp(): array
{
    $root = sys_get_temp_dir().'/ignis-tm-'.bin2hex(random_bytes(4));
    mkdir($root);
    register_shutdown_function(fn () => (new Filesystem)->deleteDirectory($root));
    $db = "$root/real.sqlite";
    touch($db);
    config(['database.connections.update_trial' => ['driver' => 'sqlite', 'database' => $db, 'prefix' => '', 'foreign_key_constraints' => true]]);
    DB::connection('update_trial')->statement('create table things (id integer primary key, name text)');
    DB::connection('update_trial')->table('things')->insert([['name' => 'alpha'], ['name' => 'beta']]);
    DB::purge('update_trial');

    return ['root' => $root, 'db' => $db];
}

function tmFailingCode(string $root, string $body = 'exit(1);'): string
{
    $dir = "$root/new-code";
    mkdir($dir);
    file_put_contents("$dir/artisan", '<?php
$pdo = new PDO("sqlite:".getenv("DB_DATABASE"));
$pdo->exec("DROP TABLE things");
file_put_contents(__DIR__."/seen-db.txt", getenv("DB_DATABASE"));
fwrite(STDERR, "migration exploded password=hunter2secret\n");
'.$body);

    return $dir;
}

it('trial migration leaves the real database untouched on failure', function () {
    ['root' => $root, 'db' => $db] = tmSetUp();
    $hash = hash_file('sha256', $db);
    $code = tmFailingCode($root);

    try {
        (new TrialMigration(DatabaseDumper::for('update_trial'), new UpdateLog("$root/update.log")))->run($code);
    } catch (UpdateException) {
    }

    $seen = (string) file_get_contents("$code/seen-db.txt");
    expect($seen)->not->toBe($db)->and(file_exists($seen))->toBeFalse()
        ->and(hash_file('sha256', $db))->toBe($hash)
        ->and(DB::connection('update_trial')->table('things')->count())->toBe(2);
});

it('trial migration failure raises UpdateException', function () {
    ['root' => $root] = tmSetUp();
    $code = tmFailingCode($root);

    expect(fn () => (new TrialMigration(DatabaseDumper::for('update_trial'), new UpdateLog("$root/update.log")))->run($code))
        ->toThrow(fn (UpdateException $e) => expect($e->reason)->toBe('trial_failed'));
    $log = (string) file_get_contents("$root/update.log");
    expect($log)->toContain('migration exploded')->not->toContain('hunter2secret');
});

it('trial migration that runs too long fails', function () {
    ['root' => $root] = tmSetUp();
    $code = tmFailingCode($root, 'sleep(20);');

    expect(fn () => (new TrialMigration(DatabaseDumper::for('update_trial'), new UpdateLog("$root/update.log"), timeout: 2))->run($code))
        ->toThrow(fn (UpdateException $e) => expect($e->reason)->toBe('trial_failed'));
});

it('trial migration runs the new code migrations against a copy', function () {
    ['root' => $root, 'db' => $db] = tmSetUp();
    $hash = hash_file('sha256', $db);

    (new TrialMigration(DatabaseDumper::for('update_trial'), new UpdateLog("$root/update.log")))->run(base_path());

    expect(hash_file('sha256', $db))->toBe($hash)
        ->and(DB::connection('update_trial')->getSchemaBuilder()->hasTable('migrations'))->toBeFalse()
        ->and((string) file_get_contents("$root/update.log"))->toContain('trial migration ok')->toContain('DONE');
});

it('trial migration is unavailable for an in-memory database', function () {
    config(['database.connections.update_trial' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);

    expect(fn () => (new TrialMigration(DatabaseDumper::for('update_trial')))->run(base_path()))
        ->toThrow(fn (UpdateException $e) => expect($e->reason)->toBe('trial_unavailable'));
});

it('trial log keeps stderr and the end of a long stdout', function () {
    ['root' => $root] = tmSetUp();
    $code = tmFailingCode($root, 'echo str_repeat("2026_01_01_000000_some_migration .... DONE\n", 300)."FINAL ERROR LINE\n"; exit(1);');

    expect(fn () => (new TrialMigration(DatabaseDumper::for('update_trial'), new UpdateLog("$root/update.log")))->run($code))
        ->toThrow(fn (UpdateException $e) => expect($e->reason)->toBe('trial_failed'));
    expect((string) file_get_contents("$root/update.log"))->toContain('migration exploded')->toContain('FINAL ERROR LINE');
});
