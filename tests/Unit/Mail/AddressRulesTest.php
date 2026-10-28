<?php

use Modules\Mail\Support\AddressRules;

it('parses a domain list: lower case, valid, unique, in order', function () {
    expect(AddressRules::parseDomains([' IGNIS.ef ', 'feuerwehr.example', 'ignis.ef', 'no domain', '-bad.ef', 'x', 42, 'a.b']))
        ->toBe(['ignis.ef', 'feuerwehr.example', 'a.b']);
});

it('knows a domain', function () {
    foreach ([
        ['ignis.ef', true], ['sub.ignis-test.ef', true], ['ignis', false], ['Ignis.ef', false], ['ignis..ef', false],
        ['ignis.ef-', false], [str_repeat('a', 99).'.e', false], [str_repeat('a', 98).'.e', true], ["ignis.ef\n", false],
    ] as [$domain, $ok]) {
        expect(AddressRules::isDomain($domain))->toBe($ok);
    }
});

it('normalizes to trimmed lower case', function () {
    expect(AddressRules::normalize('  Max.MÜLLER@Ignis.EF '))->toBe('max.müller@ignis.ef');
});
