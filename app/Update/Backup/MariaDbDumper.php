<?php

namespace App\Update\Backup;

use App\Update\UpdateException;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use PDO;
use PDOException;
use Symfony\Component\Process\ExecutableFinder;

/**
 * MariaDB/MySQL via mariadb-dump and the mariadb client. The password reaches the programs only through the
 * environment variable MYSQL_PWD of the child process, never as an argument (visible in the process list) or log line.
 */
class MariaDbDumper extends DatabaseDumper
{
    public const TIMEOUT = 3600;

    public function __construct(string $connection, private ?string $database = null)
    {
        parent::__construct($connection);
    }

    public function withDatabase(string $database): self
    {
        $copy = new self($this->connection, $database);
        $copy->log = $this->log;

        return $copy;
    }

    public function database(): string
    {
        return $this->database ?? (string) ($this->config()['database'] ?? '');
    }

    public function fileName(): string
    {
        return 'db.sql';
    }

    public function available(bool $forRestore = false): ?string
    {
        if ($this->dumpProgram() === null) {
            return __('update.error.dump_missing', ['program' => 'mariadb-dump']);
        }

        return $this->clientProgram() === null ? __('update.error.dump_missing', ['program' => 'mariadb']) : null;
    }

    public function dumpProgram(): ?string
    {
        $configured = config('ignis.update.mariadb_dump');
        if (is_string($configured) && $configured !== '') {
            return is_file($configured) ? $configured : null;
        }

        return $this->find(['mariadb-dump', 'mysqldump']);
    }

    public function clientProgram(): ?string
    {
        $dump = $this->dumpProgram();
        if ($dump === null) {
            return null;
        }
        foreach (['mariadb', 'mysql'] as $name) {
            foreach (['', '.exe'] as $ext) {
                if (is_file($path = dirname($dump).DIRECTORY_SEPARATOR.$name.$ext)) {
                    return $path;
                }
            }
        }

        return $this->find(['mariadb', 'mysql']);
    }

    public function sizeBytes(): int
    {
        try {
            $stmt = $this->pdo()->query('SELECT COALESCE(SUM(DATA_LENGTH + INDEX_LENGTH), 0) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()');

            return $stmt === false ? 0 : (int) $stmt->fetchColumn();
        } catch (PDOException) {
            return 0;
        }
    }

    public function dump(string $targetFile): void
    {
        try {
            $result = Process::env($this->env())->timeout(self::TIMEOUT)->run([
                (string) $this->dumpProgram(), ...$this->connectionArgs(),
                '--single-transaction', '--routines', '--triggers', '--default-character-set=utf8mb4',
                '--result-file='.$targetFile, $this->database(),
            ]);
        } catch (ProcessTimedOutException) {
            $this->log?->write('mariadb-dump exceeded '.self::TIMEOUT.' s');
            throw new UpdateException('dump_failed');
        }
        if ($result->failed()) {
            $this->log?->write('mariadb-dump failed: '.$result->errorOutput());
            throw new UpdateException('dump_failed');
        }
        $tail = (string) @file_get_contents($targetFile, false, null, max(0, (int) @filesize($targetFile) - 200));
        if (! str_contains($tail, '-- Dump completed')) {
            $this->log?->write('mariadb-dump output is incomplete (no "Dump completed" trailer)');
            throw new UpdateException('dump_failed');
        }
    }

    public function restore(string $sourceFile): void
    {
        try {
            $pdo = $this->pdo();
        } catch (PDOException $e) {
            $this->log?->write('mariadb restore failed (database unchanged): '.$e->getMessage());
            throw new UpdateException('restore_db_failed');
        }
        $input = $this->clientProgram() === null ? false : @fopen($sourceFile, 'rb');
        if ($input === false) {
            throw new UpdateException('restore_db_failed');
        }
        try {
            $this->dropAll($pdo);
            $result = Process::env($this->env())->timeout(self::TIMEOUT)->input($input)->run([
                (string) $this->clientProgram(), ...$this->connectionArgs(), '--default-character-set=utf8mb4', $this->database(),
            ]);
            $error = $result->failed() ? 'client exit '.$result->exitCode().': '.$result->errorOutput() : null;
        } catch (PDOException $e) {
            $error = $e->getMessage();
        } catch (ProcessTimedOutException) {
            $error = 'client exceeded '.self::TIMEOUT.' s';
        } finally {
            fclose($input);
            $pdo = null;
            DB::purge($this->connection);
        }
        if ($error !== null) {
            $this->log?->write("mariadb restore of {$this->database()} failed, the database may be empty or incomplete; load $sourceFile by hand: $error");
            throw new UpdateException('restore_db_partial', ['path' => $sourceFile]);
        }
    }

    /** A connection of our own (server only, or the database); PDO errors never carry the password. */
    public function pdo(bool $withDatabase = true): PDO
    {
        $c = $this->config();
        $dsn = ! empty($c['unix_socket'])
            ? 'mysql:unix_socket='.$c['unix_socket']
            : 'mysql:host='.($c['host'] ?? '127.0.0.1').';port='.($c['port'] ?? '3306');
        if ($withDatabase) {
            $dsn .= ';dbname='.$this->database();
        }

        return new PDO($dsn.';charset=utf8mb4', (string) ($c['username'] ?? ''), (string) ($c['password'] ?? ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    public static function quote(string $identifier): string
    {
        return '`'.str_replace('`', '``', $identifier).'`';
    }

    protected function dropAll(PDO $pdo): void
    {
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        $stmt = $pdo->query('SELECT TABLE_NAME, TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()');
        foreach ($stmt === false ? [] : $stmt->fetchAll(PDO::FETCH_NUM) as [$name, $type]) {
            $pdo->exec(($type === 'VIEW' ? 'DROP VIEW ' : 'DROP TABLE ').self::quote((string) $name));
        }
    }

    /** @return list<string> */
    private function connectionArgs(): array
    {
        $c = $this->config();
        $args = ['--user='.($c['username'] ?? '')];
        if (! empty($c['unix_socket'])) {
            $args[] = '--socket='.$c['unix_socket'];
        } else {
            $args[] = '--host='.($c['host'] ?? '127.0.0.1');
            $args[] = '--port='.($c['port'] ?? '3306');
        }

        return $args;
    }

    /** @return array<string, string> */
    private function env(): array
    {
        return ['MYSQL_PWD' => (string) ($this->config()['password'] ?? '')];
    }

    /** @param  list<string>  $names */
    private function find(array $names): ?string
    {
        $finder = new ExecutableFinder;
        foreach ($names as $name) {
            if (($path = $finder->find($name)) !== null) {
                return $path;
            }
        }

        return null;
    }
}
