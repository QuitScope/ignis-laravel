<?php

it('uses only :name placeholders in every core and module translation file', function () {
    $files = [...glob(dirname(__DIR__, 2).'/lang/*.json'), ...glob(dirname(__DIR__, 2).'/modules/*/lang/*.json')];
    expect($files)->not->toBeEmpty();

    $bad = [];
    foreach ($files as $file) {
        foreach (json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR) as $key => $text) {
            if (is_string($text) && preg_match('/\{[A-Za-z_][A-Za-z0-9_]*\}/', $text) === 1) {
                $bad[] = basename(dirname($file, 2)).'/'.basename($file).': '.$key;
            }
        }
    }

    expect($bad)->toBe([]);
});
