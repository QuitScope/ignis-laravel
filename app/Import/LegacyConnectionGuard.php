<?php

namespace App\Import;

use RuntimeException;

final class LegacyConnectionGuard
{
    /**
     * @param  array<string, mixed>  $legacy  the `legacy` connection config
     * @param  array<string, mixed>  $app  the app's default connection config
     */
    public static function assertSeparate(array $legacy, array $app): void
    {
        if (empty($legacy['host'])) {
            throw new RuntimeException('The legacy connection is not configured (set LEGACY_DB_HOST and the other LEGACY_DB_* variables).');
        }

        $key = fn (array $c) => [(string) ($c['host'] ?? ''), (string) ($c['port'] ?? ''), (string) ($c['database'] ?? '')];
        if ($key($legacy) === $key($app)) {
            throw new RuntimeException("The legacy database '{$legacy['database']}' is the app's own database; refusing to import from it.");
        }
    }
}
