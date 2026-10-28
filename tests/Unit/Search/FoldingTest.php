<?php

use App\Search\SearchTextNormalizer;

it('folds umlauts, sharp s, other diacritics and case', function () {
    foreach ([
        ['Müller', 'mueller'],
        ['MÜLLER', 'mueller'],
        ['Größe', 'groesse'],
        ['Äpfel Öl', 'aepfel oel'],
        ['Straße', 'strasse'],
        ['ẞ', 'ss'],
        ['Café Señor', 'cafe senor'],
        ["Mu\u{0308}ller", 'mueller'],
        ['Łódź', 'łodz'],
        ['', ''],
    ] as [$in, $out]) {
        expect(SearchTextNormalizer::fold($in))->toBe($out);
    }
});

it('splits a query into word tokens', function () {
    expect(SearchTextNormalizer::tokens('  Hans-Peter  Müller_1/83 '))->toBe(['Hans', 'Peter', 'Müller', '1', '83'])
        ->and(SearchTextNormalizer::tokens('%%'))->toBe([])
        ->and(SearchTextNormalizer::tokens(''))->toBe([]);
});
