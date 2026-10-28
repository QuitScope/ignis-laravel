<?php

use App\Documents\DocumentRenderer;
use App\Documents\DocumentValidator;
use App\Documents\SectionGuard;
use App\Documents\SystemTemplates;
use App\Documents\VariableCatalog;
use Tests\Support\DocJson;
use Tests\TestCase;

uses(TestCase::class);

dataset('templates', fn () => array_map(fn ($t) => [$t['content']], SystemTemplates::all()));

function findField(array $node, string $id): ?array
{
    if (($node['type'] ?? null) === 'docField' && ($node['attrs']['fieldId'] ?? null) === $id) {
        return $node;
    }
    foreach ($node['content'] ?? [] as $child) {
        if ($found = findField($child, $id)) {
            return $found;
        }
    }

    return null;
}

function withCourse(array $node, string $value): array
{
    if (($node['type'] ?? null) === 'docField' && ($node['attrs']['fieldId'] ?? null) === 'qualifikation') {
        $node['attrs']['value'] = $value;
    }
    foreach ($node['content'] ?? [] as $i => $child) {
        $node['content'][$i] = withCourse($child, $value);
    }

    return $node;
}

it('ships the ten legacy templates in legacy order', function () {
    expect(array_keys(SystemTemplates::all()))->toBe([
        'Beförderungsurkunde', 'Ernennungsurkunde', 'Entlassungsurkunde', 'Ausbildungszertifikat',
        'Lehrgangszertifikat', 'Lehrgangszertifikat Fachdienste', 'Schriftliche Abmahnung',
        'Vorläufige Dienstenthebung', 'Dienstentfernung', 'Außerordentliche Kündigung',
    ])->and(array_keys(DocJson::legacyTemplates()))->toBe(array_keys(SystemTemplates::all()));
});

it('equals the legacy export except for the section ids', function () {
    $strip = function (array $n) use (&$strip) {
        unset($n['attrs']['sectionId']);

        return array_map(fn ($v) => is_array($v) ? $strip($v) : $v, $n);
    };

    foreach (SystemTemplates::all() as $name => $t) {
        expect($strip($t['content']))->toEqual($strip(DocJson::legacyTemplates()[$name]), $name);
    }
});

it('has stable section ids', function () {
    expect(SystemTemplates::all())->toBe(SystemTemplates::all());
});

it('passes the validator in template mode', function (array $doc) {
    expect(fn () => DocumentValidator::validate($doc, true))->not->toThrow(Throwable::class);
})->with('templates');

it('renders without structure warnings', function (array $doc) {
    $result = (new DocumentRenderer)->render($doc, array_map(fn () => 'X', VariableCatalog::catalog()));

    expect($result->html)->not->toBe('')
        ->and(array_values(array_filter($result->warnings, fn ($w) => ! str_starts_with($w, 'Pflichtfeld leer: '))))->toBe([]);
})->with('templates');

it('uses only variables of the catalog', function (array $doc) {
    $used = [];
    array_walk_recursive($doc, function ($value, $key) use (&$used) {
        if ($key === 'name' && is_string($value) && str_contains($value, '.')) {
            $used[] = $value;
        }
    });

    expect(array_diff($used, array_keys(VariableCatalog::catalog())))->toBe([]);
})->with('templates');

it('asks the issuer for exactly what is intended', function (string $name, string $kind) {
    $json = json_encode(SystemTemplates::all()[$name]['content']);

    $field = str_contains($json, '"docField"');
    $free = str_contains($json, '"mode":"free"');

    expect([$field, $free])->toBe(match ($kind) {
        'field' => [true, false],
        'free' => [$field, true],
        'none' => [false, false],
    });
})->with([
    ['Beförderungsurkunde', 'field'], ['Ernennungsurkunde', 'field'], ['Entlassungsurkunde', 'none'],
    ['Ausbildungszertifikat', 'field'], ['Lehrgangszertifikat', 'field'], ['Lehrgangszertifikat Fachdienste', 'field'],
    ['Schriftliche Abmahnung', 'free'], ['Vorläufige Dienstenthebung', 'free'],
    ['Dienstentfernung', 'free'], ['Außerordentliche Kündigung', 'free'],
]);

it('offers the course lists as field options and accepts only those', function (string $name, array $list) {
    $template = SystemTemplates::all()[$name]['content'];
    $value = $list[0]['female'] ?? $list[0]['label'];
    expect(findField($template, 'qualifikation')['attrs']['options'])->toBe($list);

    $guard = new SectionGuard;
    $accepted = $guard->restoreLockedSections($template, withCourse($template, $value));
    $rejected = $guard->restoreLockedSections($template, withCourse($template, 'Ehrenkommandant'));

    expect(findField($accepted->document, 'qualifikation')['attrs']['value'])->toBe($value)
        ->and(findField($rejected->document, 'qualifikation')['attrs']['value'])->toBe('');
})->with([
    ['Lehrgangszertifikat', SystemTemplates::LEHRGAENGE],
    ['Lehrgangszertifikat Fachdienste', SystemTemplates::FACHDIENSTE],
]);
