<?php

it('has no invalid UTF-8 in the source, resources, routes, lang, database and tests', function () {
    $bad = [];
    foreach (['app', 'resources', 'routes', 'lang', 'database', 'tests'] as $dir) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/'.$dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (! $file->isFile() || ! preg_match('/\.(php|js|ts|vue|json|css|md|sql|ya?ml|html|blade\.php)$/i', $file->getFilename())) {
                continue;
            }
            if (! mb_check_encoding((string) file_get_contents($file->getPathname()), 'UTF-8')) {
                $bad[] = $file->getPathname();
            }
        }
    }

    expect($bad)->toBe([]);
});
