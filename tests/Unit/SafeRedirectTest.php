<?php

use App\Support\SafeRedirect;

it('only lets internal paths through', function () {
    foreach ([
        ['/users', '/users'],
        ['//evil.example', '/'],
        ['https://x', '/'],
        ['\\evil', '/'],
        ['', '/'],
        [null, '/'],
        ['/a?b=1', '/a?b=1'],
        ['/\\evil.example', '/'],
        ["/\t/evil.example", '/'],
        ["/\n/evil.example", '/'],
        ['javascript:alert(1)', '/'],
    ] as [$target, $expected]) {
        expect(SafeRedirect::intended($target))->toBe($expected);
    }
});

it('uses the given fallback', function () {
    expect(SafeRedirect::intended('https://evil.example/', '/home'))->toBe('/home');
});
