<?php

use App\Vehicles\TacticalSymbol;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

uses(TestCase::class);

it('reads its allowlist from the JSON shared with the front end', function () {
    $json = json_decode(file_get_contents(base_path('resources/js/lib/tacticalSymbolOptions.json')), true, flags: JSON_THROW_ON_ERROR);

    expect(TacticalSymbol::KEYS)->toBe(['grundzeichen', 'organisation', 'fachaufgabe', 'einheit', 'symbol', 'typ', 'text']);
    foreach (['grundzeichen', 'organisation', 'fachaufgabe', 'einheit', 'symbol'] as $key) {
        expect(TacticalSymbol::options()[$key])->toBe(array_column($json[$key], 'value'))->not->toBeEmpty();
    }
});

it('accepts every option value of the old lists that the library can draw', function () {
    foreach (['grundzeichen', 'organisation', 'fachaufgabe', 'einheit', 'symbol'] as $key) {
        foreach (TacticalSymbol::options()[$key] as $value) {
            expect(TacticalSymbol::validate([$key => $value]))->toBe([$key => $value]);
        }
    }
    expect(TacticalSymbol::options()['grundzeichen'])->toContain('kraftfahrzeug-gelaendegaengig', 'ohne', 'wechselbehaelter')
        ->and(TacticalSymbol::options()['organisation'])->toContain('feuerwehr', 'thw');
});

it('returns only the seven known keys and drops empty values', function () {
    $out = TacticalSymbol::validate([
        'grundzeichen' => ' fahrzeug ', 'organisation' => '', 'fachaufgabe' => null, 'typ' => ' HLF20 ', 'text' => "a\0b",
        'name' => 'x', 'farbe' => 'red', 'is_admin' => true,
    ]);

    expect($out)->toBe(['grundzeichen' => 'fahrzeug', 'typ' => 'HLF20', 'text' => 'ab']);
    expect(TacticalSymbol::validate([]))->toBe([])->and(TacticalSymbol::validate(['text' => "\0"]))->toBe([]);
});

it('rejects foreign values, arrays and over-long texts', function (array $input, string $key) {
    try {
        TacticalSymbol::validate($input);
        $this->fail('accepted');
    } catch (ValidationException $e) {
        expect(array_keys($e->errors()))->toBe([$key]);
        expect($e->errors()[$key][0])->not->toContain('validation.')->not->toContain('Exception');
    }
})->with([
    'unknown grundzeichen' => [['grundzeichen' => 'raumschiff'], 'grundzeichen'],
    'case differs' => [['organisation' => 'Feuerwehr'], 'organisation'],
    'array value' => [['einheit' => ['trupp']], 'einheit'],
    'dropped by the library' => [['symbol' => 'boot'], 'symbol'],
    'text 101' => [['text' => str_repeat('a', 101)], 'text'],
    'typ 101' => [['typ' => str_repeat('ä', 101)], 'typ'],
    'bad utf8' => [['typ' => "\xC3\x28"], 'typ'],
]);

it('accepts texts of exactly 100 characters and prefixes error keys', function () {
    expect(TacticalSymbol::validate(['text' => str_repeat('ä', 100)]))->toBe(['text' => str_repeat('ä', 100)]);

    try {
        TacticalSymbol::validate(['grundzeichen' => 'x'], 'symbol');
        $this->fail('accepted');
    } catch (ValidationException $e) {
        expect(array_keys($e->errors()))->toBe(['symbol.grundzeichen']);
    }
});

it('refuses a list and strips the CDATA end marker', function () {
    expect(fn () => TacticalSymbol::validate(['fahrzeug']))->toThrow(ValidationException::class);
    expect(TacticalSymbol::validate(['text' => 'a]]>b']))->toBe(['text' => 'ab']);
    expect(TacticalSymbol::validate([]))->toBe([]);
});
