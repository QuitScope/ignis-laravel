<?php

namespace App\Update;

use App\System\Redact;
use Throwable;

/** <log_dir>/update-<time>.log of one update run (default storage/logs); every line goes through Redact. Never throws. */
class UpdateLog
{
    public function __construct(private ?string $path = null) {}

    public function path(): string
    {
        return $this->path ??= self::dir().'/update-'.now()->utc()->format('Ymd-His').'.log';
    }

    public static function dir(): string
    {
        $dir = config('ignis.update.log_dir');

        return rtrim(is_string($dir) && $dir !== '' ? $dir : storage_path('logs'), '/\\');
    }

    public function write(string $line): void
    {
        try {
            $dir = dirname($this->path());
            if (! is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            @file_put_contents($this->path(), '['.now()->utc()->toIso8601ZuluString().'] '.Redact::text($line, 2000)."\n", FILE_APPEND | LOCK_EX);
        } catch (Throwable) {
        }
    }
}
