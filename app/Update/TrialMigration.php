<?php

namespace App\Update;

use App\Update\Backup\DatabaseDumper;
use App\Update\Backup\MariaDbDumper;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;
use PDOException;
use Symfony\Component\Process\PhpExecutableFinder;
use Throwable;

/**
 * Runs the migrations of the NEW code (`php artisan migrate --force` in a child process, working directory = new code)
 * against a copy of the database, never the real one: SQLite = temp file copy, MariaDB = helper database <db>_trial
 * from a dump, dropped afterwards. Anything that fails while preparing the copy (missing rights or programs, dump or
 * load errors and timeouts) = `trial_unavailable` (the caller may continue without a trial); a failing or hanging
 * migration = `trial_failed`, its output only in the update log.
 */
final class TrialMigration
{
    private const STDOUT_TAIL = 1800;

    public function __construct(private ?DatabaseDumper $dumper = null, private ?UpdateLog $log = null, private int $timeout = 300) {}

    public function run(string $newCodePath): void
    {
        $dumper = $this->dumper ?? DatabaseDumper::for();
        $dumper->log ??= $this->log;
        $php = (new PhpExecutableFinder)->find(false);
        if (($reason = $dumper->available()) !== null || $php === false) {
            $this->log?->write('trial migration unavailable: '.($reason ?? 'php CLI binary not found'));
            throw new UpdateException('trial_unavailable');
        }

        $copy = (string) tempnam(sys_get_temp_dir(), 'ignis-trial-');
        $cacheId = 'bootstrap/cache/ignis-trial-'.bin2hex(random_bytes(4));
        $caches = ['APP_CONFIG_CACHE' => 'config', 'APP_PACKAGES_CACHE' => 'packages', 'APP_SERVICES_CACHE' => 'services', 'APP_EVENTS_CACHE' => 'events', 'APP_ROUTES_CACHE' => 'routes'];
        $env = [
            'DB_CONNECTION' => (string) ($dumper->config()['driver'] ?? ''),
            'DB_URL' => '',
            ...array_map(fn (string $name) => "$cacheId-$name.php", $caches),
        ];
        $trialDb = null;
        try {
            try {
                $dumper->dump($copy);
                if ($dumper instanceof MariaDbDumper) {
                    $trialDb = $this->createTrialDatabase($dumper);
                    $dumper->withDatabase($trialDb)->restore($copy);
                    $c = $dumper->config();
                    $env += [
                        'DB_HOST' => (string) ($c['host'] ?? '127.0.0.1'),
                        'DB_PORT' => (string) ($c['port'] ?? '3306'),
                        'DB_SOCKET' => (string) ($c['unix_socket'] ?? ''),
                        'DB_USERNAME' => (string) ($c['username'] ?? ''),
                        'DB_PASSWORD' => (string) ($c['password'] ?? ''),
                        'DB_DATABASE' => $trialDb,
                    ];
                } else {
                    $env['DB_DATABASE'] = $copy;
                }
            } catch (Throwable $e) {
                $this->log?->write('trial migration: preparing the database copy failed: '.($e instanceof UpdateException ? $e->reason : $e->getMessage()));
                throw new UpdateException('trial_unavailable');
            }

            try {
                $result = Process::path($newCodePath)->env($env)->timeout($this->timeout)
                    ->run([$php, 'artisan', 'migrate', '--force', '--no-interaction', '--no-ansi']);
            } catch (ProcessTimedOutException) {
                $this->log?->write("trial migration exceeded {$this->timeout} s");
                throw new UpdateException('trial_failed');
            }
            if ($result->failed()) {
                $this->log?->write("trial migration failed (exit {$result->exitCode()}), stderr:\n".$result->errorOutput());
                $this->log?->write("trial migration stdout (end):\n".substr($result->output(), -self::STDOUT_TAIL));
                throw new UpdateException('trial_failed');
            }
            $this->log?->write("trial migration ok:\n".substr($result->output(), -self::STDOUT_TAIL));
        } finally {
            if ($trialDb !== null && $dumper instanceof MariaDbDumper) {
                try {
                    $dumper->pdo(false)->exec('DROP DATABASE IF EXISTS '.MariaDbDumper::quote($trialDb));
                } catch (PDOException $e) {
                    $this->log?->write('trial database could not be dropped: '.$e->getMessage());
                }
            }
            foreach ([$copy, "$copy-wal", "$copy-shm", "$copy-journal", ...array_map(fn (string $name) => "$newCodePath/$cacheId-$name.php", $caches)] as $file) {
                if (file_exists($file)) {
                    @unlink($file);
                }
            }
        }
    }

    private function createTrialDatabase(MariaDbDumper $dumper): string
    {
        $name = $dumper->database().'_trial';
        $c = $dumper->config();
        try {
            $pdo = $dumper->pdo(false);
            $pdo->exec('DROP DATABASE IF EXISTS '.MariaDbDumper::quote($name));
            $pdo->exec('CREATE DATABASE '.MariaDbDumper::quote($name)
                .' CHARACTER SET '.preg_replace('/\W/', '', (string) ($c['charset'] ?? 'utf8mb4'))
                .' COLLATE '.preg_replace('/\W/', '', (string) ($c['collation'] ?? 'utf8mb4_unicode_ci')));
        } catch (PDOException $e) {
            $this->log?->write('trial database could not be created (CREATE/DROP rights?): '.$e->getMessage());
            throw new UpdateException('trial_unavailable');
        }

        return $name;
    }
}
