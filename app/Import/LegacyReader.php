<?php

namespace App\Import;

use Generator;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Read-only view on the legacy database. Importers get this instead of the raw connection.
 * The wrapped connection refuses every statement that is not a read (also via table()->insert()).
 * Use a dedicated connection (open()), never one that other code writes through.
 * This is a safety net, not the guarantee: the real guarantee is a SELECT-only database user for
 * LEGACY_DB_*. table()->getConnection() is a deliberate escape hatch that importers must not use.
 */
final class LegacyReader
{
    public function __construct(private readonly Connection $connection)
    {
        $connection->beforeExecuting(function (string $query): void {
            if (! preg_match('/^\s*(select|show|describe|desc|explain)\b/i', $query)
                || preg_match('/\binto\s+(outfile|dumpfile)\b|\bfor\s+update\b|\block\s+in\s+share\s+mode\b/i', $query)) {
                throw new LogicException('The legacy connection is read-only.');
            }
        });
    }

    public static function open(): self
    {
        DB::connectUsing('legacy_readonly', (array) config('database.connections.legacy'), true);

        return new self(DB::connection('legacy_readonly'));
    }

    public function table(string $table): Builder
    {
        return $this->connection->table($table);
    }

    /**
     * Every column of $table plus `<column>__unix` = UNIX_TIMESTAMP(column) for each TIMESTAMP column. A TIMESTAMP is
     * an instant (MariaDB stores UTC); UNIX_TIMESTAMP returns it without a time-zone conversion, so the value does not
     * depend on the server's or the session's time_zone (DST, dump/restore) and needs no zone tables. Read it with
     * LegacyClock::instant(). Not for DATETIME columns: those are wall time.
     *
     * @param  list<string>  $timestampColumns
     */
    public function tableWithInstants(string $table, array $timestampColumns): Builder
    {
        $query = $this->table($table)->select('*');
        foreach ($timestampColumns as $column) {
            $query->selectRaw("UNIX_TIMESTAMP(`{$column}`) AS `{$column}__unix`");
        }

        return $query;
    }

    /**
     * @param  array<int|string, mixed>  $bindings
     * @return array<int, mixed>
     */
    public function select(string $query, array $bindings = []): array
    {
        return $this->connection->select($query, $bindings);
    }

    /**
     * @param  array<int|string, mixed>  $bindings
     * @return Generator<int, mixed>
     */
    public function cursor(string $query, array $bindings = []): Generator
    {
        return $this->connection->cursor($query, $bindings);
    }

    public function tableExists(string $table): bool
    {
        return $this->connection->getSchemaBuilder()->hasTable($table);
    }
}
