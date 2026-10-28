<?php

use Modules\Mail\Support\AddressGenerator;

function ag(string $name, string $pattern = 'initial_dot_last', array $taken = []): string
{
    return AddressGenerator::generate($name, $pattern, 'ignis.ef', fn (string $a) => in_array($a, $taken, true));
}

it('builds the address from the name', function () {
    foreach ([
        'initial' => ['Max Muster', 'initial_dot_last', 'm.muster@ignis.ef'],
        'first' => ['Max Muster', 'first_dot_last', 'max.muster@ignis.ef'],
        'umlauts and ß' => ['Jörg Müller-Weiß', 'first_dot_last', 'joerg.mueller-weiss@ignis.ef'],
        'capital umlaut' => ['Ömer Ärger', 'first_dot_last', 'oemer.aerger@ignis.ef'],
        'accents' => ['José Núñez', 'first_dot_last', 'jose.nunez@ignis.ef'],
        'particles become hyphens' => ['Max von der Heide', 'initial_dot_last', 'm.von-der-heide@ignis.ef'],
        'extra spaces' => ['  Max   Muster  ', 'initial_dot_last', 'm.muster@ignis.ef'],
        'hyphenated first name' => ['Hans-Peter Klein', 'first_dot_last', 'hans-peter.klein@ignis.ef'],
        'one word' => ['Madonna', 'initial_dot_last', 'madonna@ignis.ef'],
        'symbols dropped' => ["Max O'Neil", 'first_dot_last', 'max.oneil@ignis.ef'],
    ] as [$name, $pattern, $expected]) {
        expect(ag($name, $pattern))->toBe($expected);
    }
});

it('extends the initial on a collision, then counts', function () {
    expect(ag('Anja Schmidt', taken: ['a.schmidt@ignis.ef']))->toBe('an.schmidt@ignis.ef')
        ->and(ag('Klaus Lang', taken: ['k.lang@ignis.ef', 'k.lang2@ignis.ef']))->toBe('kl.lang@ignis.ef')
        ->and(ag('Al Bo', taken: ['a.bo@ignis.ef', 'al.bo@ignis.ef']))->toBe('a.bo2@ignis.ef')
        ->and(ag('Al Bo', taken: ['a.bo@ignis.ef', 'al.bo@ignis.ef', 'a.bo2@ignis.ef']))->toBe('a.bo3@ignis.ef');
});

it('never stops on a hyphen while extending the initial', function () {
    expect(ag('Jo-Ann Lee', taken: ['j.lee@ignis.ef', 'jo.lee@ignis.ef']))->toBe('jo-a.lee@ignis.ef');
});

it('counts with first_dot_last', function () {
    expect(ag('Jörg Müller', 'first_dot_last', ['joerg.mueller@ignis.ef']))->toBe('joerg.mueller2@ignis.ef')
        ->and(ag('Jörg Müller', 'first_dot_last', ['joerg.mueller@ignis.ef', 'joerg.mueller2@ignis.ef']))->toBe('joerg.mueller3@ignis.ef');
});

it('keeps the local part within 64 characters, counter included', function () {
    $long = 'Max '.str_repeat('Lang', 30);
    $first = ag($long, 'first_dot_last');
    $second = ag($long, 'first_dot_last', [$first]);

    expect(strlen(strstr($first, '@', true)))->toBe(64)
        ->and(strstr($second, '@', true))->toBe(substr('max.'.str_repeat('lang', 30), 0, 63).'2')
        ->and(strlen(strstr(ag($long), '@', true)))->toBe(64);
});

it('rejects a name without letters or digits', function () {
    ag('!!! ???');
})->throws(InvalidArgumentException::class);
