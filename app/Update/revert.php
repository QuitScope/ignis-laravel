<?php

/*
 * Framework-free revert of a half-done FlatSwapper swap, PHP built-ins only. FlatSwapper copies this file next to the
 * journal (.update-old/<version>/revert.php) before the first rename, and uses the same function for its own revert,
 * so there is one implementation. When the app cannot boot any more (the swap stopped half-way):
 *
 *   php .update-old/<version>/revert.php
 *
 * puts the old code back, restores a SQLite database and .env from the backup (MariaDB: prints the command), then
 * tells what to run next (docs/dev/update-recovery.md).
 *
 * The journal is not trusted: every entry is checked against the fixed code list, the app directory is where this
 * script lies (<app>/.update-old/<version>/), and backup files must be the fixed names in one backup directory.
 */

if (! function_exists('ignis_update_revert')) {
    /**
     * Top-level code entries of a release; must equal App\Update\FlatSwapper::CODE (a test checks it).
     *
     * @return list<string>
     */
    function ignis_update_code(): array
    {
        return ['app', 'bootstrap', 'config', 'database', 'docs', 'lang', 'modules', 'public', 'resources', 'routes', 'vendor', 'artisan', 'composer.json', 'composer.lock', 'VERSION'];
    }

    /**
     * The checked journal: items = code entry => existed before, keep = relative paths below a code entry.
     *
     * @return array{items: array<string, bool>, keep: list<string>}
     */
    function ignis_update_journal(string $journalFile): array
    {
        $data = json_decode((string) @file_get_contents($journalFile), true);
        if (! is_array($data) || ! is_array($data['items'] ?? null) || ! is_array($data['keep'] ?? null)) {
            throw new RuntimeException("journal $journalFile unreadable");
        }
        $items = [];
        foreach ($data['items'] as $c => $existed) {
            if (! in_array($c, ignis_update_code(), true) || ! is_bool($existed)) {
                throw new RuntimeException("journal entry $c refused");
            }
            $items[$c] = $existed;
        }
        $keep = [];
        foreach ($data['keep'] as $rel) {
            $parts = is_string($rel) ? explode('/', $rel) : [];
            if (! is_string($rel) || count($parts) < 2 || ! in_array($parts[0], ignis_update_code(), true)
                || str_contains($rel, '\\') || str_contains($rel, ':') || str_contains($rel, "\0")
                || array_intersect($parts, ['', '.', '..']) !== []) {
                throw new RuntimeException('journal keep entry refused');
            }
            $keep[] = $rel;
        }

        return ['items' => $items, 'keep' => $keep];
    }

    /**
     * Undoes the swap described by $journalFile (swap.json in <app>/.update-old/<version>/): carried-over files go back
     * into the old directories, then every code entry moves back; entries the new version added are moved aside.
     * Idempotent; the journal is removed at the end. Throws RuntimeException (before any move) for a journal entry
     * outside the code list, and when a rename keeps failing.
     *
     * @param  callable(string, string): bool  $rename
     * @param  callable(string): void  $log
     */
    function ignis_update_revert(string $journalFile, string $app, callable $rename, callable $log): void
    {
        $old = dirname($journalFile);
        $data = ignis_update_journal($journalFile);
        $exists = fn (string $p): bool => file_exists($p) || is_link($p);
        $move = function (string $from, string $to) use ($rename): void {
            $dir = dirname($to);
            if (! is_dir($dir) && ! @mkdir($dir, 0700, true) && ! is_dir($dir)) {
                throw new RuntimeException("$dir cannot be created");
            }
            for ($i = 1; ! $rename($from, $to); $i++) {
                if ($i >= 5) {
                    throw new RuntimeException("$from cannot be moved to $to (locked?)");
                }
                usleep(500_000);
            }
        };
        $log("revert swap from $old");
        $discard = "$old/.discard";
        foreach ($data['keep'] as $rel) {
            if ($exists("$app/$rel") && ! $exists("$old/$rel") && is_dir("$old/".explode('/', $rel)[0])) {
                $move("$app/$rel", "$old/$rel");
            }
        }
        foreach (array_reverse($data['items'], true) as $c => $existed) {
            if ($exists("$old/$c")) {
                if ($exists("$app/$c")) {
                    $move("$app/$c", "$discard/$c");
                }
                $move("$old/$c", "$app/$c");
            } elseif (! $existed && $exists("$app/$c")) {
                $move("$app/$c", "$discard/$c");
            }
        }
        for ($i = 1; ! @unlink($journalFile); $i++) {
            if ($i >= 5) {
                throw new RuntimeException('journal cannot be removed');
            }
            usleep(500_000);
        }
        if (! ignis_update_rmtree($old)) {
            $log("$old could not be removed completely");
        }
    }

    function ignis_update_rmtree(string $dir): bool
    {
        if (is_link($dir) || ! is_dir($dir)) {
            return ! file_exists($dir) && ! is_link($dir) || @unlink($dir) || @rmdir($dir);
        }
        $ok = true;
        foreach (scandir($dir) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') {
                $ok = ignis_update_rmtree("$dir/$name") && $ok;
            }
        }

        return @rmdir($dir) && $ok;
    }

    function ignis_update_inside(string $path, string $dir): bool
    {
        $norm = fn (string $p): string => rtrim(str_replace('\\', '/', PHP_OS_FAMILY === 'Windows' ? strtolower($p) : $p), '/');

        return str_starts_with($norm($path).'/', $norm($dir).'/') && $norm($path) !== $norm($dir);
    }

    function ignis_update_is_sqlite(string $file): bool
    {
        return is_file($file) && (string) @file_get_contents($file, false, null, 0, 16) === "SQLite format 3\0";
    }
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    $journal = __DIR__.'/swap.json';
    if (! is_file($journal)) {
        fwrite(STDERR, "No unfinished swap here (swap.json missing): nothing to revert.\n");
        exit(1);
    }
    $app = (string) realpath(dirname(__DIR__, 2));
    $data = json_decode((string) file_get_contents($journal), true);
    if (basename(dirname(__DIR__)) !== '.update-old'
        || (is_array($data) && isset($data['app']) && realpath((string) $data['app']) !== $app)) {
        fwrite(STDERR, "This script is not in <app>/.update-old/<version>/ of the app its journal names; nothing was changed.\n");
        exit(1);
    }
    $r = is_array($data) && is_array($data['recovery'] ?? null) ? $data['recovery'] : [];
    $copyOver = function (string $from, string $to): bool {
        $tmp = $to.'.revert-'.bin2hex(random_bytes(4));

        return @copy($from, $tmp) && @rename($tmp, $to);
    };
    try {
        ignis_update_revert($journal, $app, fn (string $a, string $b): bool => @rename($a, $b), function (string $line): void {
            echo $line, "\n";
        });
    } catch (Throwable $e) {
        fwrite(STDERR, 'Revert failed: '.$e->getMessage()."\nFix the cause (locked files?) and run this again.\n");
        exit(1);
    }
    echo "Code restored in $app.\n";

    $backup = is_string($r['db_backup'] ?? null) ? $r['db_backup'] : '';
    $dir = $backup !== '' ? dirname($backup) : '';
    $validBackup = $dir !== '' && in_array(basename($backup), ['db.sqlite', 'db.sql'], true) && is_file("$dir/manifest.json");
    $envBackup = is_string($r['env_backup'] ?? null) ? $r['env_backup'] : '';
    $validEnv = $envBackup !== '' && basename($envBackup) === 'env' && is_file(dirname($envBackup).'/manifest.json') && is_file($envBackup)
        && (! $validBackup || dirname($envBackup) === $dir);
    $type = (string) ($r['db_type'] ?? '');
    if (! $validBackup) {
        echo "No usable database backup is recorded here: restore by hand (docs/dev/update-recovery.md).\n";
    } elseif ($type === 'sqlite' && basename($backup) === 'db.sqlite' && ignis_update_is_sqlite($backup)) {
        $file = is_string($r['db_file'] ?? null) ? $r['db_file'] : '';
        $real = $file !== '' ? realpath($file) : false;
        if ($real === false || ! ignis_update_inside($real, $app) || ignis_update_inside($real, "$app/.update-old") || ! ignis_update_is_sqlite($real)) {
            echo "Database NOT restored automatically: copy $backup over the SQLite database by hand.\n";
        } else {
            foreach (["$real-wal", "$real-shm", "$real-journal"] as $side) {
                @unlink($side);
            }
            echo $copyOver($backup, $real) ? "Database restored from $backup.\n" : "Database NOT restored: copy $backup over $real by hand.\n";
        }
    } elseif ($type === 'mariadb' && basename($backup) === 'db.sql') {
        $db = is_string($r['database'] ?? null) && $r['database'] !== '' ? $r['database'] : '<database>';
        echo "Load the database dump by hand (empty the database first):\n  mariadb ".escapeshellarg($db).' < '.escapeshellarg($backup)."\n";
    } else {
        echo "The recorded database backup does not match its type: restore by hand (docs/dev/update-recovery.md).\n";
    }
    if ($validEnv) {
        $env = "$app/.env";
        $target = is_link($env) ? (realpath($env) ?: $env) : $env;
        echo $copyOver($envBackup, $target) ? ".env restored.\n" : ".env NOT restored: copy $envBackup over $env by hand.\n";
    } else {
        echo "No usable .env backup is recorded here: check .env by hand (docs/dev/update-recovery.md).\n";
    }
    echo "Next: php artisan ignis:update:recover (finishes the update state; after a failed rollback: --force), then php artisan up and reload PHP-FPM.\n";
}
