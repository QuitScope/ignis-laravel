<?php

namespace App\Support;

use App\Update\InstalledVersion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Health report; contract taken from legacy ignis /healthz (see HealthTest).
 */
final class HealthChecks
{
    public const EXTENSIONS = ['fileinfo', 'gd', 'intl', 'json', 'mbstring', 'openssl', 'pdo', 'pdo_mysql', 'xml', 'zip'];

    /** @return array<string, mixed> */
    public static function run(Request $request): array
    {
        $checks = [
            'db' => self::db(),
            'queue' => self::queue(),
            'storage' => self::storage(),
            'migrations' => self::migrations(),
            'php_extensions' => self::extensions(),
            'rewrite' => self::rewrite($request),
        ];

        return [
            'status' => self::overall($checks),
            'checks' => $checks,
            'version' => InstalledVersion::current(),
            'checked_at' => now()->toIso8601String(),
        ];
    }

    /** @param array<string, array<string, mixed>> $checks */
    public static function overall(array $checks): string
    {
        $statuses = array_column($checks, 'status');

        return in_array('down', $statuses, true) ? 'down' : (in_array('degraded', $statuses, true) ? 'degraded' : 'ok');
    }

    /** @return array<string, mixed> */
    private static function db(): array
    {
        $start = hrtime(true);
        try {
            DB::select('select 1');
        } catch (Throwable $e) {
            return ['status' => 'down', 'error' => 'unreachable'];
        }

        return ['status' => 'ok', 'ms' => (int) round((hrtime(true) - $start) / 1e6)];
    }

    /** @return array<string, mixed> */
    private static function queue(): array
    {
        try {
            return [
                'status' => 'ok',
                'pending' => DB::table('jobs')->count(),
                'failed' => DB::table('failed_jobs')->count(),
            ];
        } catch (Throwable $e) {
            return ['status' => 'down', 'error' => 'unavailable'];
        }
    }

    /** @return array<string, mixed> */
    private static function storage(): array
    {
        $free = @disk_free_space(storage_path());

        return is_writable(storage_path())
            ? ['status' => 'ok', 'free_mb' => $free === false ? null : (int) ($free / 1048576)]
            : ['status' => 'down', 'error' => 'not writable'];
    }

    /** @return array<string, mixed> */
    private static function migrations(): array
    {
        try {
            $latest = DB::table('migrations')->orderByDesc('id')->value('migration');
        } catch (Throwable $e) {
            return ['status' => 'down', 'error' => 'unavailable'];
        }

        return $latest === null ? ['status' => 'down', 'error' => 'none ran'] : ['status' => 'ok', 'latest' => $latest];
    }

    /** @return array<string, mixed> */
    private static function extensions(): array
    {
        $missing = array_values(array_filter(self::EXTENSIONS, fn (string $e) => ! extension_loaded($e)));

        return ['status' => $missing === [] ? 'ok' : 'degraded', 'required' => self::EXTENSIONS, 'missing' => $missing];
    }

    /** @return array<string, mixed> */
    private static function rewrite(Request $request): array
    {
        $script = str_replace('\\', '/', (string) $request->server('SCRIPT_FILENAME', ''));
        $root = (string) $request->server('DOCUMENT_ROOT', '');
        $index = realpath(public_path('index.php'));
        $front = str_ends_with($script, '/public/index.php')
            || ($root !== '' && realpath($root) !== false && realpath($root) === realpath(public_path()))
            || ($index !== false && in_array($index, array_map('realpath', get_included_files()), true));

        return ['status' => $front ? 'ok' : 'degraded', 'front_controller' => $front];
    }
}
