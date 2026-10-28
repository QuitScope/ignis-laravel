<?php

namespace Tests\Support;

use Illuminate\Filesystem\Filesystem;

/** Keeps maintenance tests away from the real storage/framework/down: they get a temporary storage directory. */
final class MaintenanceSandbox
{
    public static function enter(): void
    {
        $real = base_path('storage/framework/down');
        if (is_file($real)) {
            throw new \RuntimeException('storage/framework/down exists (the app is in maintenance mode): refusing to run maintenance tests');
        }
        $dir = sys_get_temp_dir().'/ignis-maint-'.bin2hex(random_bytes(4));
        mkdir($dir.'/framework', 0775, true);
        app()->useStoragePath($dir);
        register_shutdown_function(fn () => (new Filesystem)->deleteDirectory($dir));
    }
}
