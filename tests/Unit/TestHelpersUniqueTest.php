<?php

it('declares no global test helper function twice', function () {
    $seen = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__), FilesystemIterator::SKIP_DOTS)) as $f) {
        if ($f->getExtension() !== 'php') {
            continue;
        }
        preg_match_all('/^function (\w+)/m', file_get_contents($f->getPathname()), $m);
        foreach ($m[1] as $name) {
            $seen[$name][] = $f->getPathname();
        }
    }

    expect(array_filter($seen, fn ($files) => count($files) > 1))->toBeEmpty();
});
