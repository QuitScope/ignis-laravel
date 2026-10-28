<?php

namespace App\Update\Backup;

use App\Update\UpdateException;
use App\Update\UpdateLog;

/** Copies one database connection to a file and back, for the update backup and the trial migration. */
abstract class DatabaseDumper
{
    public ?UpdateLog $log = null;

    public function __construct(public readonly string $connection) {}

    abstract public function dump(string $targetFile): void;

    abstract public function restore(string $sourceFile): void;

    abstract public function available(bool $forRestore = false): ?string;

    abstract public function sizeBytes(): int;

    abstract public function fileName(): string;

    /** @return list<string> database files that live on disk (left out of the code backup) */
    public function localFiles(): array
    {
        return [];
    }

    /** @return array<string, mixed> */
    public function config(): array
    {
        return (array) config("database.connections.{$this->connection}");
    }

    public static function for(?string $connection = null): self
    {
        $connection ??= (string) config('database.default');

        return match (config("database.connections.$connection.driver")) {
            'sqlite' => new SqliteDumper($connection),
            'mariadb', 'mysql' => new MariaDbDumper($connection),
            default => throw new UpdateException('db_unsupported'),
        };
    }
}
