<?php

const TOKENS = ['bg', 'bg-2', 'surface', 'surface-2', 'surface-3', 'border', 'border-strong', 'text', 'text-2', 'text-3', 'accent', 'accent-hover', 'ok', 'warn', 'danger', 'info'];

function tokensBlock(string $css, string $selector): string
{
    preg_match('/'.preg_quote($selector, '/').'\s*\{(.*?)\n\}/s', $css, $m);

    return $m[1] ?? '';
}

it('defines every token in the dark and the light block', function () {
    $css = file_get_contents(dirname(__DIR__, 2).'/resources/css/tokens.css');

    foreach ([':root' => TOKENS, ':root[data-theme="light"]' => array_diff(TOKENS, ['accent', 'accent-hover'])] as $selector => $tokens) {
        $block = tokensBlock($css, $selector);
        foreach ($tokens as $token) {
            expect($block)->toContain("--{$token}:");
        }
    }
});

it('keeps hex colours inside tokens.css', function () {
    $root = dirname(__DIR__, 2);
    $files = [$root.'/resources/css/app.css'];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/resources/js'));
    foreach ($it as $f) {
        if ($f->getExtension() === 'vue') {
            $files[] = $f->getPathname();
        }
    }

    foreach ($files as $file) {
        expect(preg_match('/#[0-9a-f]{3,8}\b/i', file_get_contents($file)))->toBe(0, $file);
    }
});
