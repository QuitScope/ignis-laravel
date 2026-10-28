<?php

namespace Tests\Support;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The throwaway database holding the old intraRP schema, built by tools/legacy-schema/load.php.
 * It is configured at runtime from LEGACY_TEST_DB_* and is NOT the real import source (LEGACY_DB_*).
 * Importer tests read from it; they belong to the `legacy` group.
 */
final class LegacyDatabase
{
    /** @return array{host: ?string, port: ?string, username: ?string, password: ?string, database: ?string} */
    private static function env(): array
    {
        $get = fn (string $key) => ($v = env('LEGACY_TEST_DB_'.$key)) === '' ? null : $v;

        return ['host' => $get('HOST'), 'port' => $get('PORT'), 'username' => $get('USERNAME'), 'password' => $get('PASSWORD'), 'database' => $get('DATABASE')];
    }

    public static function available(): bool
    {
        return self::env()['host'] !== null;
    }

    /**
     * Points the `legacy` connection at the test database. Returns false (and touches nothing)
     * when LEGACY_TEST_DB_HOST is not set.
     *
     * @param  array<string, ?string>|null  $env  override for tests
     */
    public static function configure(?array $env = null): bool
    {
        $env ??= self::env();
        if (empty($env['host'])) {
            return false;
        }
        config([
            'database.connections.legacy.host' => $env['host'],
            'database.connections.legacy.port' => $env['port'] ?? '3306',
            'database.connections.legacy.username' => $env['username'] ?? 'root',
            'database.connections.legacy.password' => $env['password'] ?? '',
            'database.connections.legacy.database' => $env['database'] ?? 'legacy_test',
        ]);
        DB::purge('legacy');
        DB::purge('legacy_readonly');

        return true;
    }

    /**
     * Destructive tests may only touch a database named legacy_test*, never the app's own.
     *
     * @param  array<string, mixed>  $legacy
     * @param  array<string, mixed>  $app
     */
    public static function assertTestDatabase(array $legacy, array $app): void
    {
        $database = (string) ($legacy['database'] ?? '');
        if (! str_starts_with($database, 'legacy_test')) {
            throw new RuntimeException("Refusing to modify database '{$database}': test databases must be named legacy_test*");
        }
        $key = fn (array $c) => [(string) ($c['host'] ?? ''), (string) ($c['port'] ?? ''), (string) ($c['database'] ?? '')];
        if ($key($legacy) === $key($app)) {
            throw new RuntimeException("Refusing to modify database '{$database}': it is the app's default connection");
        }
    }

    public static function guard(): void
    {
        self::assertTestDatabase(
            (array) config('database.connections.legacy'),
            (array) config('database.connections.'.config('database.default')),
        );
    }

    public static function connection(): ConnectionInterface
    {
        return DB::connection('legacy');
    }

    /**
     * Drops and recreates the test database, then plays every legacy migration into it.
     *
     * @param  array<string, ?string>|null  $env  override for tests
     * @return array<string, string> failed migration file => error message
     */
    public static function refresh(?array $env = null): array
    {
        if (! self::configure($env)) {
            throw new RuntimeException('LEGACY_TEST_DB_HOST is not set; the legacy test group cannot run.');
        }
        self::guard();

        $legacyPath = (string) env('LEGACY_PATH', base_path('../ignis'));
        if (! is_dir($legacyPath.'/database/migrations')) {
            throw new RuntimeException("Legacy tree not found at {$legacyPath} (set LEGACY_PATH)");
        }

        require_once base_path('tools/legacy-schema/load.php');

        legacy_schema_guard(
            (array) config('database.connections.legacy'),
            (array) config('database.connections.'.config('database.default')),
        );

        $connection = DB::connection('legacy');
        $database = $connection->getDatabaseName();
        $connection->statement("DROP DATABASE IF EXISTS `{$database}`");
        $connection->statement("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $connection->statement("USE `{$database}`");

        return legacy_schema_load($connection->getPdo(), $legacyPath);
    }
}
