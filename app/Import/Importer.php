<?php

namespace App\Import;

/**
 * Imports one legacy entity into the new instance. Registered in config('ignis.importers');
 * resolved from the container by `php artisan ignis:import-legacy`.
 *
 * Rules for implementations:
 * - Re-runnable: map legacy ids with $ctx->ids() and update what is already mapped instead of duplicating.
 * - Only add or update in the target; never delete.
 * - Read the legacy data only through $ctx->legacy() (read-only).
 * - A dry run executes inside a database transaction on the target connection that is rolled back
 *   afterwards. Therefore, when $ctx->dryRun() is true, do NOT perform non-transactional side
 *   effects: no filesystem copies, no HTTP calls, no cache writes (the command only resets the
 *   settings and permission caches afterwards).
 */
interface Importer
{
    public function entity(): string;

    /** @return list<string> entities that have to run first */
    public function dependsOn(): array;

    /** @return list<string> legacy tables this importer reads; checked before anything runs */
    public function requiredTables(): array;

    public function import(ImportContext $ctx): void;
}
