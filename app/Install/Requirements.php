<?php

namespace App\Install;

use App\Support\HealthChecks;
use Closure;
use Illuminate\Support\Facades\DB;
use Throwable;

final class Requirements
{
    private const MIN_PHP = '8.3.0';

    /**
     * @param  list<string>|null  $extensions  defaults to HealthChecks::EXTENSIONS
     * @param  array<string, string>|null  $dirs  label => path that must be writable
     * @return array<string, array{ok: bool, detail: string}>
     */
    public static function check(?array $extensions = null, ?array $dirs = null): array
    {
        $extensions ??= HealthChecks::EXTENSIONS;
        $dirs ??= ['storage' => storage_path(), 'bootstrap/cache' => base_path('bootstrap/cache')];

        return [
            'php' => self::guard(fn () => [
                'ok' => version_compare(PHP_VERSION, self::MIN_PHP, '>='),
                'detail' => PHP_VERSION.' (>= '.self::MIN_PHP.')',
            ]),
            'extensions' => self::guard(function () use ($extensions) {
                $missing = array_values(array_filter($extensions, fn (string $e) => ! extension_loaded($e)));

                return ['ok' => $missing === [], 'detail' => $missing === [] ? implode(', ', $extensions) : __('install.req.detail.missing', ['list' => implode(', ', $missing)])];
            }),
            'storage' => self::guard(function () use ($dirs) {
                $bad = array_keys(array_filter($dirs, fn (string $d) => ! is_writable($d)));

                return ['ok' => $bad === [], 'detail' => $bad === [] ? implode(', ', array_keys($dirs)) : __('install.req.detail.not_writable', ['list' => implode(', ', $bad)])];
            }),
            'database' => self::guard(function () {
                DB::select('select 1');

                return ['ok' => true, 'detail' => (string) DB::connection()->getDriverName()];
            }),
        ];
    }

    /**
     * @param  Closure(): array{ok: bool, detail: string}  $check
     * @return array{ok: bool, detail: string}
     */
    private static function guard(Closure $check): array
    {
        try {
            return $check();
        } catch (Throwable) {
            // No exception text: it can contain hosts or credentials and the page is public.
            return ['ok' => false, 'detail' => __('install.req.detail.unavailable')];
        }
    }
}
