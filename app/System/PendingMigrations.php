<?php

namespace App\System;

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Migration files that have not run yet (the admin banner and the system overview). Reads the files and the migrations
 * table, never runs anything. Cached for 60 s because the banner asks on every page; a missing table counts as nothing
 * pending (the installer and the health check report that case).
 */
final class PendingMigrations
{
    public const CACHE_KEY = 'system.pending_migrations';

    public function __construct(private Migrator $migrator) {}

    /** @return list<string> names, in the order they would run */
    public function names(): array
    {
        return Cache::remember(self::CACHE_KEY, 60, fn () => $this->compute());
    }

    /** @return list<string> */
    private function compute(): array
    {
        try {
            $files = $this->migrator->getMigrationFiles([database_path('migrations'), ...$this->migrator->paths()]);
            $ran = $this->migrator->getRepository()->getRan();
        } catch (Throwable) {
            return [];
        }

        return array_values(array_diff(array_keys($files), $ran));
    }
}
