<?php

use App\Update\Backup\BackupManager;
use App\Update\Backup\DatabaseDumper;
use App\Update\Backup\MariaDbDumper;
use App\Update\TrialMigration;
use App\Update\UpdateException;
use App\Update\UpdateLog;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

uses()->group('slow');

const MD_PASSWORD = 'S3cretPw!x';

/** @return string a temp dir; connection update_maria with a known password, a fake dump program file */
function mdSetUp(): string
{
    $root = sys_get_temp_dir().'/ignis-md-'.bin2hex(random_bytes(4));
    mkdir($root);
    register_shutdown_function(fn () => (new Filesystem)->deleteDirectory($root));
    touch("$root/mariadb-dump");
    touch("$root/mariadb");
    config([
        'database.connections.update_maria' => ['driver' => 'mariadb', 'host' => '127.0.0.1', 'port' => '3306', 'database' => 'ignis', 'username' => 'ignis', 'password' => MD_PASSWORD],
        'ignis.update.mariadb_dump' => "$root/mariadb-dump",
        'ignis.update.backup_path' => "$root/backups",
    ]);

    return $root;
}

it('dump password never appears in process arguments or log', function () {
    $root = mdSetUp();
    Process::fake(['*' => Process::result(errorOutput: 'Access denied for user ignis (using password: '.MD_PASSWORD.')', exitCode: 2)]);
    $dumper = DatabaseDumper::for('update_maria');
    $dumper->log = new UpdateLog("$root/update.log");

    expect($dumper)->toBeInstanceOf(MariaDbDumper::class)
        ->and(fn () => $dumper->dump("$root/db.sql"))->toThrow(fn (UpdateException $e) => expect($e->reason)->toBe('dump_failed')->and($e->getMessage())->not->toContain(MD_PASSWORD));

    Process::assertRan(function (PendingProcess $p) use ($root) {
        $args = implode(' ', (array) $p->command);
        expect($args)->not->toContain(MD_PASSWORD)
            ->toContain('--single-transaction')->toContain('--routines')->toContain('--result-file='.$root.'/db.sql')
            ->and($p->environment['MYSQL_PWD'] ?? null)->toBe(MD_PASSWORD);

        return true;
    });
    $log = (string) file_get_contents("$root/update.log");
    expect($log)->toContain('Access denied')->not->toContain(MD_PASSWORD);
});

it('missing mariadb-dump reported before any change', function () {
    $root = mdSetUp();
    config(['ignis.update.mariadb_dump' => "$root/nope/mariadb-dump"]);
    Process::fake();
    $dumper = DatabaseDumper::for('update_maria');

    expect($dumper->available())->toContain('mariadb-dump')
        ->and(fn () => (new BackupManager($dumper, fn () => PHP_INT_MAX))->create('1.0.0', '1.1.0'))
        ->toThrow(fn (UpdateException $e) => expect($e->reason)->toBe('dump_unavailable'))
        ->and(fn () => (new TrialMigration($dumper))->run(base_path()))
        ->toThrow(fn (UpdateException $e) => expect($e->reason)->toBe('trial_unavailable'));
    expect(is_dir("$root/backups") ? glob("$root/backups/*") : [])->toBe([]);
    Process::assertNothingRan();
});

it('dump without the completion trailer counts as failed', function () {
    $root = mdSetUp();
    Process::fake();
    $dumper = DatabaseDumper::for('update_maria');

    expect(fn () => $dumper->dump("$root/db.sql"))->toThrow(fn (UpdateException $e) => expect($e->reason)->toBe('dump_failed'));
    file_put_contents("$root/db.sql", "CREATE TABLE x (id int);\n-- Dump completed on 2026-10-20 10:00:00\n");
    $dumper->dump("$root/db.sql");
});

it('failing client during restore reports a possibly empty database and the dump path', function () {
    $root = mdSetUp();
    file_put_contents("$root/db.sql", "-- Dump completed\n");
    Process::fake(['*' => Process::result(errorOutput: 'ERROR 2013: Lost connection, password='.MD_PASSWORD, exitCode: 1)]);
    $dumper = new class('update_maria') extends MariaDbDumper
    {
        public bool $dropped = false;

        public function pdo(bool $withDatabase = true): PDO
        {
            return new PDO('sqlite::memory:');
        }

        protected function dropAll(PDO $pdo): void
        {
            $this->dropped = true;
        }
    };
    $dumper->log = new UpdateLog("$root/update.log");

    expect(fn () => $dumper->restore("$root/db.sql"))->toThrow(function (UpdateException $e) use ($root) {
        expect($e->reason)->toBe('restore_db_partial')->and($e->getMessage())->toContain("$root/db.sql")->not->toContain(MD_PASSWORD);
    });
    $log = (string) file_get_contents("$root/update.log");
    expect($dumper->dropped)->toBeTrue()->and($log)->toContain('Lost connection')->toContain('may be empty')->not->toContain(MD_PASSWORD);
});

it('failing dump while preparing the trial copy is trial_unavailable', function () {
    $root = mdSetUp();
    Process::fake(['*' => Process::result(errorOutput: 'Got error: 1045', exitCode: 2)]);

    expect(fn () => (new TrialMigration(DatabaseDumper::for('update_maria'), new UpdateLog("$root/update.log")))->run(base_path()))
        ->toThrow(fn (UpdateException $e) => expect($e->reason)->toBe('trial_unavailable'));
    Process::assertRanTimes(fn (PendingProcess $p) => str_contains(implode(' ', (array) $p->command), 'artisan'), 0);
});

it('mariadb backup, restore and trial migration against a real server', function () {
    $root = mdSetUp();
    config([
        'ignis.update.mariadb_dump' => getenv('IGNIS_UPDATE_MARIADB_DUMP') ?: null,
        'database.connections.update_maria' => [
            'driver' => 'mariadb', 'host' => getenv('UPDATE_TEST_DB_HOST'), 'port' => getenv('UPDATE_TEST_DB_PORT') ?: '3306',
            'database' => 'ignis_update_test', 'username' => getenv('UPDATE_TEST_DB_USERNAME') ?: 'root', 'password' => (string) getenv('UPDATE_TEST_DB_PASSWORD'),
            'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '',
        ],
    ]);
    /** @var MariaDbDumper $dumper */
    $dumper = DatabaseDumper::for('update_maria');
    if (($reason = $dumper->available()) !== null) {
        $this->markTestSkipped($reason);
    }
    $server = $dumper->pdo(false);
    $server->exec('DROP DATABASE IF EXISTS ignis_update_test');
    $server->exec('CREATE DATABASE ignis_update_test');
    try {
        $pdo = $dumper->pdo();
        $pdo->exec('create table things (id int primary key, name varchar(20))');
        $pdo->exec("insert into things values (1, 'alpha'), (2, 'beta')");
        $dumper->dump("$root/db.sql");
        expect((string) file_get_contents("$root/db.sql"))->not->toContain(MD_PASSWORD);

        $pdo->exec("insert into things values (3, 'gamma')");
        $pdo->exec('create table new_table (id int)');
        $dumper->restore("$root/db.sql");

        $pdo = $dumper->pdo();
        expect($pdo->query('select name from things order by id')->fetchAll(PDO::FETCH_COLUMN))->toBe(['alpha', 'beta'])
            ->and($pdo->query("show tables like 'new_table'")->fetchAll())->toBe([]);

        (new TrialMigration($dumper, new UpdateLog("$root/update.log")))->run(base_path());
        expect($pdo->query("show tables like 'migrations'")->fetchAll())->toBe([])
            ->and($server->query("show databases like 'ignis_update_test_trial'")->fetchAll())->toBe([]);
    } finally {
        $server->exec('DROP DATABASE IF EXISTS ignis_update_test');
    }
})->skip(fn () => ! getenv('UPDATE_TEST_DB_HOST'), 'UPDATE_TEST_DB_HOST not set');
