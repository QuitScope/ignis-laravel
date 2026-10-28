<?php

namespace App\Update\Backup;

use App\Update\UpdateException;
use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;

/** SQLite: VACUUM INTO gives a consistent copy; restore swaps the file in with one rename. */
class SqliteDumper extends DatabaseDumper
{
    public function file(): string
    {
        return (string) ($this->config()['database'] ?? '');
    }

    public function fileName(): string
    {
        return 'db.sqlite';
    }

    public function available(bool $forRestore = false): ?string
    {
        $file = $this->file();
        if ($file === '' || $file === ':memory:' || str_contains($file, 'mode=memory')) {
            return __('update.error.sqlite_memory');
        }

        return $forRestore || is_file($file) ? null : __('update.error.sqlite_missing');
    }

    public function sizeBytes(): int
    {
        return (int) @filesize($this->file());
    }

    public function localFiles(): array
    {
        $file = $this->file();

        return [$file, "$file-wal", "$file-shm", "$file-journal", ...(glob($file.'.restore-*') ?: [])];
    }

    public function dump(string $targetFile): void
    {
        try {
            $pdo = new PDO('sqlite:'.$this->file(), null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 30]);
            $pdo->exec('VACUUM INTO '.$pdo->quote($targetFile));
        } catch (PDOException $e) {
            $this->log?->write('sqlite backup failed: '.$e->getMessage());
            throw new UpdateException('dump_failed');
        } finally {
            $pdo = null;
        }
    }

    public function restore(string $sourceFile): void
    {
        $file = $this->file();
        $tmp = $file.'.restore-'.bin2hex(random_bytes(4));
        if (! @copy($sourceFile, $tmp)) {
            throw new UpdateException('restore_db_failed');
        }
        DB::purge($this->connection);
        try {
            foreach (["$file-wal", "$file-shm", "$file-journal"] as $side) {
                if (file_exists($side) && ! BackupManager::retry(fn () => @unlink($side))) {
                    throw new UpdateException('restore_db_failed');
                }
            }
            if (! BackupManager::retry(fn () => @rename($tmp, $file))) {
                throw new UpdateException('restore_db_failed');
            }
        } finally {
            if (file_exists($tmp)) {
                @unlink($tmp);
            }
        }
    }
}
