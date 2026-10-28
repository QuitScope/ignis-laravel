<?php

/*
 * Plays the legacy intraRP Phinx migrations (core + plugins) into an empty MariaDB database,
 * using the Phinx stand-in from Shim.php. Only the migration files themselves are executed.
 *
 *   php tools/legacy-schema/load.php --legacy=../ignis --database=legacy_test
 *
 * Connection: LEGACY_TEST_DB_HOST, LEGACY_TEST_DB_PORT, LEGACY_TEST_DB_USERNAME, LEGACY_TEST_DB_PASSWORD,
 * LEGACY_TEST_DB_DATABASE (env; the same names the Pest legacy group uses, never LEGACY_DB_* which is the real import source).
 * The database name must start with legacy_test; the loader drops nothing else.
 * Exit code 1 when a migration failed; every failure is listed with its file name.
 */

declare(strict_types=1);

require_once __DIR__.'/Shim.php';

use Phinx\Db\Adapter\MysqlAdapter;

/**
 * Refuses to let the loader drop anything but a dedicated legacy database.
 *
 * @param  array{host?: mixed, port?: mixed, database?: mixed}  $target  the legacy connection
 * @param  array{host?: mixed, port?: mixed, database?: mixed}|null  $app  the app's default connection
 */
function legacy_schema_guard(array $target, ?array $app): void
{
    $database = (string) ($target['database'] ?? '');
    if ($database !== 'legacy' && ! str_starts_with($database, 'legacy_')) {
        throw new RuntimeException("Refusing to drop database '{$database}': the legacy database must be named legacy or legacy_*");
    }
    $key = fn (array $c) => [(string) ($c['host'] ?? ''), (string) ($c['port'] ?? ''), (string) ($c['database'] ?? '')];
    if ($app !== null && $key($target) === $key($app)) {
        throw new RuntimeException("Refusing to drop database '{$database}': it is the app's default connection");
    }
}

/**
 * Orders migration files by version like Phinx and maps each to the class name Phinx expects.
 *
 * @param  list<string>  $files
 * @return array<string, string> file => class
 */
function legacy_schema_migrations(array $files): array
{
    usort($files, fn (string $a, string $b) => strcmp(basename($a), basename($b)));

    $migrations = [];
    $seen = [];
    foreach ($files as $file) {
        if (! preg_match('/^(\d{14})_(\w+)\.php$/', basename($file), $match)) {
            throw new RuntimeException("Not a Phinx migration file name: {$file}");
        }
        $class = str_replace(' ', '', ucwords(str_replace('_', ' ', $match[2])));
        foreach (['version' => $match[1], 'class' => $class] as $kind => $value) {
            if (isset($seen[$kind][$value])) {
                throw new RuntimeException("Duplicate migration {$kind} {$value} in {$seen[$kind][$value]} and {$file}");
            }
            $seen[$kind][$value] = $file;
        }
        $migrations[$file] = $class;
    }

    return $migrations;
}

/**
 * @return array<string, string> failed migration file => error message
 */
function legacy_schema_load(PDO $pdo, string $legacyPath): array
{
    // Phinx orders core and plugin migrations together by their version prefix.
    $migrations = legacy_schema_migrations(array_merge(
        glob($legacyPath.'/database/migrations/*.php') ?: [],
        glob($legacyPath.'/plugins/*/migrations/*.php') ?: [],
    ));

    $adapter = new MysqlAdapter($pdo);
    $failures = [];
    foreach ($migrations as $file => $class) {
        try {
            require_once $file;
            if (! class_exists($class, false)) {
                throw new RuntimeException("class {$class} not declared");
            }
            $migration = new $class($adapter);
            method_exists($migration, 'change') ? $migration->change() : $migration->up();
        } catch (Throwable $e) {
            $failures[basename($file)] = $e->getMessage();
        }
    }

    return $failures;
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $args = getopt('', ['legacy:', 'database:']);
    $legacy = $args['legacy'] ?? __DIR__.'/../../../ignis';
    $database = $args['database'] ?? (getenv('LEGACY_TEST_DB_DATABASE') ?: 'legacy_test');
    $host = getenv('LEGACY_TEST_DB_HOST') ?: '127.0.0.1';
    $port = getenv('LEGACY_TEST_DB_PORT') ?: '3306';
    // The app's connection, when its env is set (DB_* as in .env).
    $app = getenv('DB_DATABASE') ? ['host' => getenv('DB_HOST') ?: '127.0.0.1', 'port' => getenv('DB_PORT') ?: '3306', 'database' => getenv('DB_DATABASE')] : null;
    try {
        if (! str_starts_with($database, 'legacy_test')) {
            throw new RuntimeException("Refusing to modify database '{$database}': test databases must be named legacy_test*");
        }
        legacy_schema_guard(['host' => $host, 'port' => $port, 'database' => $database], $app);
    } catch (RuntimeException $e) {
        fwrite(STDERR, $e->getMessage()."\n");
        exit(1);
    }

    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $host, $port),
        getenv('LEGACY_TEST_DB_USERNAME') ?: 'root',
        getenv('LEGACY_TEST_DB_PASSWORD') ?: '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `{$database}`");
    if ($pdo->query('SHOW TABLES')->fetch() !== false) {
        fwrite(STDERR, "Database {$database} is not empty; drop it first.\n");
        exit(1);
    }

    $failures = legacy_schema_load($pdo, $legacy);
    $tables = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'intra\\_%'")->fetchColumn();
    foreach ($failures as $file => $message) {
        fwrite(STDERR, "FAILED {$file}: {$message}\n");
    }
    echo "{$tables} intra_* tables, ".count($failures)." failed migrations\n";
    exit($failures === [] ? 0 : 1);
}
