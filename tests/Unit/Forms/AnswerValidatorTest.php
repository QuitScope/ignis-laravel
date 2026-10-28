<?php

use Illuminate\Validation\ValidationException;
use Modules\Forms\Models\FormField;
use Modules\Forms\Models\FormType;
use Modules\Forms\Support\AnswerValidator;
use Tests\TestCase;

uses(TestCase::class);

function av_type(array $fields, bool $absence = false): FormType
{
    $type = (new FormType)->forceFill(['name' => 'T', 'is_absence' => $absence]);
    $type->setRelation('fields', collect(array_map(fn (array $f) => (new FormField)->forceFill($f + ['label' => '', 'required' => false, 'readonly' => false, 'width' => 'full']), $fields)));

    return $type;
}

function av_errors(FormType $type, array $input): array
{
    try {
        AnswerValidator::validate($type, $input);
    } catch (ValidationException $e) {
        return $e->errors();
    }
    throw new RuntimeException('Expected ValidationException');
}

it('rejects an empty required text field', function () {
    $errors = av_errors(av_type([['key' => 'grund', 'type' => 'text', 'label' => 'Grund', 'required' => true]]), ['grund' => '']);

    expect($errors)->toHaveKey('grund')->and($errors['grund'][0])->toContain('Pflichtfeld');
});

it('accepts a required text field and trims it', function () {
    expect(AnswerValidator::validate(av_type([['key' => 'grund', 'type' => 'text', 'label' => 'Grund', 'required' => true]]), ['grund' => '  Urlaub  ']))
        ->toBe(['grund' => 'Urlaub']);
});

it('returns an empty string for an optional empty field', function () {
    expect(AnswerValidator::validate(av_type([['key' => 'notiz', 'type' => 'textarea']]), [])['notiz'])->toBe('');
});

it('skips readonly fields, even required ones and even when sent', function () {
    $type = av_type([['key' => 'fullname', 'type' => 'text', 'required' => true, 'readonly' => true]]);

    expect(AnswerValidator::validate($type, []))->not->toHaveKey('fullname')
        ->and(AnswerValidator::validate($type, ['fullname' => 'Gefälscht']))->toBe([]);
});

it('validates the email format', function () {
    $type = av_type([['key' => 'kontakt', 'type' => 'email', 'required' => true]]);

    expect(av_errors($type, ['kontakt' => 'nicht-eine-email']))->toHaveKey('kontakt')
        ->and(AnswerValidator::validate($type, ['kontakt' => 'test@example.com']))->toBe(['kontakt' => 'test@example.com']);
});

it('accepts only ISO dates that exist', function () {
    $type = av_type([['key' => 'datum', 'type' => 'date', 'required' => true]]);

    expect(av_errors($type, ['datum' => '17.05.2024']))->toHaveKey('datum')
        ->and(av_errors($type, ['datum' => '2024-02-31']))->toHaveKey('datum')
        ->and(AnswerValidator::validate($type, ['datum' => '2024-05-17']))->toBe(['datum' => '2024-05-17']);
});

it('validates times as HH:MM', function () {
    $type = av_type([['key' => 'zeit', 'type' => 'time', 'required' => true]]);

    expect(AnswerValidator::validate($type, ['zeit' => '14:30']))->toBe(['zeit' => '14:30'])
        ->and(av_errors($type, ['zeit' => '14h30']))->toHaveKey('zeit')
        ->and(av_errors($type, ['zeit' => '25:00']))->toHaveKey('zeit');
});

it('requires numbers to be numeric', function () {
    $type = av_type([['key' => 'tage', 'type' => 'number', 'required' => true]]);

    expect(av_errors($type, ['tage' => 'abc']))->toHaveKey('tage')
        ->and(AnswerValidator::validate($type, ['tage' => 3]))->toBe(['tage' => '3']);
});

it('keeps select values to the option list', function () {
    $type = av_type([['key' => 'typ', 'type' => 'select', 'options' => ['Urlaub', 'Dienstreise', 'Sonderurlaub'], 'required' => true]]);

    expect(AnswerValidator::validate($type, ['typ' => 'Dienstreise']))->toBe(['typ' => 'Dienstreise'])
        ->and(av_errors($type, ['typ' => 'Irgendwas']))->toHaveKey('typ');
});

it('ignores undeclared fields', function () {
    $type = av_type([['key' => 'grund', 'type' => 'text', 'required' => true]]);

    expect(AnswerValidator::validate($type, ['grund' => 'Urlaub', 'admin_flag' => '1', 'is_approved' => 'yes']))->toBe(['grund' => 'Urlaub']);
});

it('allows digits and the usual separators in phone numbers', function () {
    $type = av_type([['key' => 'telefon', 'type' => 'tel', 'required' => true]]);

    expect(AnswerValidator::validate($type, ['telefon' => '+49 (0) 123-456 789']))->toBe(['telefon' => '+49 (0) 123-456 789'])
        ->and(av_errors($type, ['telefon' => '0123 abc']))->toHaveKey('telefon');
});

it('stores checkboxes as booleans and accepts the usual values', function () {
    $type = av_type([['key' => 'zustimmung', 'type' => 'checkbox']]);

    foreach (['0' => false, '1' => true, 'on' => true, 'off' => false, 'true' => true, 'false' => false, 'ON' => true] as $in => $out) {
        expect(AnswerValidator::validate($type, ['zustimmung' => (string) $in])['zustimmung'])->toBe($out);
    }
    expect(AnswerValidator::validate($type, ['zustimmung' => true])['zustimmung'])->toBeTrue()
        ->and(AnswerValidator::validate($type, [])['zustimmung'])->toBeFalse()
        ->and(av_errors($type, ['zustimmung' => 'vielleicht']))->toHaveKey('zustimmung');
});

it('requires a required checkbox to be ticked', function () {
    $type = av_type([['key' => 'ok', 'type' => 'checkbox', 'required' => true]]);

    expect(av_errors($type, ['ok' => false]))->toHaveKey('ok')
        ->and(AnswerValidator::validate($type, ['ok' => 'on']))->toBe(['ok' => true]);
});

it('throws a 422 validation exception with one message per field, in German and English', function () {
    $type = av_type([['key' => 'x', 'type' => 'text', 'label' => 'Grund', 'required' => true], ['key' => 'y', 'type' => 'number', 'label' => 'Tage']]);

    try {
        AnswerValidator::validate($type, ['y' => 'abc']);
        $this->fail('Expected ValidationException');
    } catch (ValidationException $e) {
        expect($e->status)->toBe(422)->and($e->errors())->toBe(['x' => ['Grund ist ein Pflichtfeld.'], 'y' => ['Tage: ungültiger Wert.']]);
    }
    app()->setLocale('en');
    expect(av_errors($type, ['y' => 'abc']))->toBe(['x' => ['Grund is required.'], 'y' => ['Tage: invalid value.']]);
});

it('treats array input as empty', function () {
    $type = av_type([['key' => 'a', 'type' => 'text', 'required' => true], ['key' => 'b', 'type' => 'text']]);

    expect(av_errors($type, ['a' => ['x']]))->toHaveKey('a')
        ->and(AnswerValidator::validate($type, ['a' => 'ok', 'b' => ['x']]))->toBe(['a' => 'ok', 'b' => '']);
});

it('caps the text lengths', function () {
    $type = av_type([['key' => 't', 'type' => 'text'], ['key' => 'ta', 'type' => 'textarea']]);

    expect(av_errors($type, ['t' => str_repeat('a', 1001)]))->toHaveKey('t')
        ->and(av_errors($type, ['ta' => str_repeat('a', 10001)]))->toHaveKey('ta')
        ->and(AnswerValidator::validate($type, ['t' => str_repeat('ä', 1000), 'ta' => str_repeat('a', 10000)]))->toHaveKeys(['t', 'ta']);
});

it('rejects an absence that ends before it starts, on bis_datum', function () {
    $fields = [['key' => 'von_datum', 'type' => 'date', 'required' => true], ['key' => 'bis_datum', 'type' => 'date', 'required' => true]];

    expect(av_errors(av_type($fields, true), ['von_datum' => '2026-10-10', 'bis_datum' => '2026-10-09']))->toHaveKeys(['bis_datum'])
        ->and(AnswerValidator::validate(av_type($fields, true), ['von_datum' => '2026-10-10', 'bis_datum' => '2026-10-10']))->toHaveCount(2)
        ->and(AnswerValidator::validate(av_type($fields), ['von_datum' => '2026-10-10', 'bis_datum' => '2026-10-09']))->toHaveCount(2);
});
