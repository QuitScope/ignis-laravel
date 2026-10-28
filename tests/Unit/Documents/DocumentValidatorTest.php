<?php

use App\Documents\DocumentValidator;
use App\Documents\InvalidDocument;
use Tests\Support\DocJson as J;
use Tests\TestCase;

uses(TestCase::class);

function validatorRejection(array $doc, bool $template = false): array
{
    try {
        DocumentValidator::validate($doc, $template);

        return [];
    } catch (InvalidDocument $e) {
        return $e->reasons;
    }
}

it('accepts every legacy system template as template and as document', function (array $doc) {
    expect(DocumentValidator::validate($doc, true))->toEqual($doc)
        ->and(DocumentValidator::validate($doc, false))->toEqual($doc);
})->with(fn () => array_map(fn ($d) => [$d], J::legacyTemplates()));

it('accepts the legacy feature test template with a locked and a free section', function () {
    $doc = J::doc(
        J::section([J::para([J::text('Aus der Vorlage.')])], 'locked', '11111111-1111-4111-8111-111111111111'),
        J::section([J::para()], 'free', '22222222-2222-4222-8222-222222222222'),
    );

    expect(DocumentValidator::validate($doc, true))->toEqual($doc);
});

it('accepts every allowed node, mark and attribute', function () {
    $cell = fn (string $type) => ['type' => $type, 'attrs' => ['colspan' => 2, 'rowspan' => 1, 'colwidth' => [100, 50]], 'content' => [J::para([J::text('x')])]];
    $doc = J::doc(J::section([
        ['type' => 'heading', 'attrs' => ['level' => 3, 'textAlign' => 'justify'], 'content' => [J::text('H', 'bold', 'italic', 'underline', 'strike')]],
        J::para([J::text('a'), ['type' => 'hardBreak'], J::variable('mitarbeiter.name') + ['marks' => [['type' => 'bold']]]], ['textAlign' => 'right']),
        ['type' => 'bulletList', 'content' => [['type' => 'listItem', 'content' => [J::para([J::text('b')])]]]],
        ['type' => 'orderedList', 'attrs' => ['start' => 3], 'content' => [['type' => 'listItem', 'content' => [J::para([J::text('c')])]]]],
        ['type' => 'blockquote', 'content' => [J::para([J::text('d')])]],
        ['type' => 'table', 'content' => [['type' => 'tableRow', 'content' => [$cell('tableHeader'), $cell('tableCell')]]]],
        ['type' => 'horizontalRule'],
        ['type' => 'pageBreak'],
        J::para([J::field('f1', [
            'label' => 'Lehrgang', 'required' => true, 'maxLength' => 40, 'value' => 'Zugführer',
            'options' => [['label' => 'Zugführer/-in', 'male' => 'Zugführer', 'female' => 'Zugführerin'], ['label' => 'HEMS-TC']],
        ])]),
    ], 'free', 's1', ['templateSectionId' => 'g-1', 'repeatable' => true, 'title' => 'Grund', 'hint' => 'Kurz']));

    expect(DocumentValidator::validate($doc, true))->toEqual($doc);
});

it('drops the empty default attributes the editor sends', function () {
    $doc = J::doc(J::section([
        J::para([J::text('x')], ['textAlign' => null]),
        ['type' => 'orderedList', 'attrs' => ['start' => 1, 'type' => null], 'content' => [['type' => 'listItem', 'content' => [J::para()]]]],
        ['type' => 'table', 'content' => [['type' => 'tableRow', 'content' => [['type' => 'tableCell', 'attrs' => ['colspan' => 1, 'rowspan' => 1, 'colwidth' => null], 'content' => [J::para()]]]]]],
    ], 'free', 's1', ['templateSectionId' => null, 'repeatable' => false, 'title' => null, 'hint' => null]));

    expect(DocumentValidator::validate($doc, false))->toEqual(J::doc(J::section([
        J::para([J::text('x')]),
        ['type' => 'orderedList', 'attrs' => ['start' => 1], 'content' => [['type' => 'listItem', 'content' => [J::para()]]]],
        ['type' => 'table', 'content' => [['type' => 'tableRow', 'content' => [['type' => 'tableCell', 'attrs' => ['colspan' => 1, 'rowspan' => 1], 'content' => [J::para()]]]]]],
    ], 'free', 's1', ['repeatable' => false])));
});

it('rejects hostile or unknown content', function () {
    foreach ([
        'script text with attributes' => [J::para([J::text('<script>alert(1)</script>') + ['attrs' => ['onclick' => 'x']]])],
        'unknown node' => [['type' => 'script', 'content' => [J::text('x')]]],
        'image node' => [['type' => 'image', 'attrs' => ['src' => 'file:///etc/passwd']]],
        'link mark' => [J::para([J::text('x') + ['marks' => [['type' => 'link', 'attrs' => ['href' => 'javascript:alert(1)']]]]])],
        'mark with attributes' => [J::para([J::text('x') + ['marks' => [['type' => 'bold', 'attrs' => ['style' => 'x']]]]])],
        'mark on a block' => [J::para() + ['marks' => [['type' => 'bold']]]],
        'unknown attribute' => [J::para([], ['onclick' => 'alert(1)'])],
        'style attribute' => [J::para([], ['style' => 'background:url(data:x)'])],
        'textAlign outside the enum' => [J::para([], ['textAlign' => 'left;background:red'])],
        'heading level 4' => [['type' => 'heading', 'attrs' => ['level' => 4]]],
        'heading level as string' => [['type' => 'heading', 'attrs' => ['level' => '1" onload="x']]],
        'extra node key' => [['type' => 'horizontalRule', 'html' => '<b>']],
        'attrs not an object' => [['type' => 'horizontalRule', 'attrs' => 'x']],
        'content on an atom' => [['type' => 'pageBreak', 'content' => [J::text('x')]]],
        'empty text' => [J::para([['type' => 'text', 'text' => '']])],
        'text as block' => [J::text('x')],
        'block inside paragraph' => [J::para([J::para()])],
        'nested section' => [J::section([J::para()], 'free', 'inner')],
        'variable with a bad name' => [J::para([J::variable('"><script>')])],
        'field without id' => [J::para([['type' => 'docField', 'attrs' => ['label' => 'x']]])],
        'field value not a string' => [J::para([J::field('f', ['value' => ['x']])])],
        'field value over maxLength' => [J::para([J::field('f', ['maxLength' => 3, 'value' => 'abcd'])])],
        'field options not structured' => [J::para([J::field('f', ['options' => ['a', 'b']])])],
        'field option with unknown key' => [J::para([J::field('f', ['options' => [['label' => 'a', 'href' => 'x']]])])],
        'table cell colspan 0' => [['type' => 'table', 'content' => [['type' => 'tableRow', 'content' => [['type' => 'tableCell', 'attrs' => ['colspan' => 0], 'content' => [J::para()]]]]]]],
        'list item outside a list' => [['type' => 'listItem', 'content' => [J::para()]]],
    ] as [$node]) {
        expect(validatorRejection(J::doc(J::section([$node]))))->not->toBe([]);
    }
});

it('rejects a section with a bad mode or without id', function (array $attrs) {
    expect(validatorRejection(J::doc(['type' => 'docSection', 'attrs' => $attrs, 'content' => [J::para()]])))->not->toBe([]);
})->with([[['mode' => 'open', 'sectionId' => 's']], [['mode' => 'free']], [['mode' => 'free', 'sectionId' => 'a b']]]);

it('limits the nesting depth to 64', function () {
    $nest = function (int $quotes) {
        $node = J::para([J::text('tief')]);
        for ($i = 0; $i < $quotes; $i++) {
            $node = ['type' => 'blockquote', 'content' => [$node]];
        }

        return J::doc($node);
    };

    expect(validatorRejection($nest(61)))->toBe([])
        ->and(validatorRejection($nest(62)))->toContain('Das Dokument ist zu tief verschachtelt (höchstens 64 Ebenen).')
        ->and(validatorRejection($nest(5000)))->not->toBe([]);
});

it('limits the size to 1 MB', function () {
    $doc = J::doc(J::section(array_fill(0, 1100, J::para([J::text(str_repeat('a', 1000))]))));

    expect(validatorRejection($doc))->toBe(['Das Dokument ist größer als 1 MB.']);
});

it('requires a doc root', function (array $doc) {
    expect(validatorRejection($doc))->not->toBe([]);
})->with([[['type' => 'paragraph']], [['content' => []]], [['type' => 'doc', 'content' => 'x']]]);

it('allows only sections on the top level of a template', function () {
    expect(validatorRejection(J::doc(J::para()), true))->toContain('Eine Vorlage darf auf oberster Ebene nur Abschnitte enthalten.')
        ->and(validatorRejection(J::doc(), true))->not->toBe([])
        ->and(validatorRejection(J::doc(J::para()), false))->toBe([]);
});

it('rejects duplicate section ids, groups and field ids', function () {
    $twice = J::doc(J::section([J::para()], 'free', 'a'), J::section([J::para()], 'locked', 'a'));
    $group = J::doc(J::section([J::para()], 'free', 'a', ['templateSectionId' => 'g']), J::section([J::para()], 'free', 'b', ['templateSectionId' => 'g']));

    expect(validatorRejection($twice, true))->toContain('Die Abschnitts-Kennung „a“ kommt mehrfach vor.')
        ->and(validatorRejection($twice, false))->toContain('Die Abschnitts-Kennung „a“ kommt mehrfach vor.')
        ->and(validatorRejection($group, true))->toContain('Die Abschnittsgruppe „g“ kommt in der Vorlage mehrfach vor.')
        ->and(validatorRejection($group, false))->toBe([])
        ->and(validatorRejection(J::doc(J::section([J::para([J::field('x')]), J::para([J::field('x')])])), true))
        ->toContain('Die Feld-Kennung „x“ kommt in einem Abschnitt mehrfach vor.')
        ->and(validatorRejection(J::doc(J::section([J::para([J::field('x')])], 'free', 'a'), J::section([J::para([J::field('x')])], 'free', 'b')), true))->toBe([]);
});

it('rejects a template whose group id is the id of another section', function () {
    $doc = J::doc(J::section([J::para()], 'locked', 'a'), J::section([J::para()], 'free', 'b', ['templateSectionId' => 'a']));

    expect(validatorRejection($doc, true))->toContain('Die Abschnittsgruppe „a“ ist zugleich die Kennung eines anderen Abschnitts.');
});

it('shortens user-controlled names in the reasons', function () {
    $type = str_repeat('x', 500_000);

    try {
        DocumentValidator::validate(J::doc(J::section([['type' => $type], J::para([], [$type => 1])])), false);
        $this->fail('rejected expected');
    } catch (InvalidDocument $e) {
        expect(strlen($e->getMessage()))->toBeLessThan(500)
            ->and($e->reasons)->toContain('Unbekannter Inhalt „'.str_repeat('x', 40).'…“.');
    }
});

function spanTable(int $rows, int $cells, array $attrs = []): array
{
    $cell = ['type' => 'tableCell', 'attrs' => $attrs, 'content' => [J::para([J::text('x')])]];
    $row = ['type' => 'tableRow', 'content' => array_fill(0, $cells, $cell)];

    return J::doc(['type' => 'table', 'content' => array_fill(0, $rows, $row)]);
}

it('rejects tables whose span grid would exhaust dompdf', function (array $doc) {
    expect(fn () => DocumentValidator::validate($doc, false))->toThrow(InvalidDocument::class);
})->with([
    'maximal spans' => [spanTable(30, 20, ['colspan' => 100, 'rowspan' => 100])],
    'colspan 21' => [spanTable(1, 1, ['colspan' => 21])],
    'rowspan 51' => [spanTable(1, 1, ['rowspan' => 51])],
    'area over 2000' => [spanTable(13, 10, ['colspan' => 4, 'rowspan' => 4])],
    '201 rows' => [spanTable(201, 1)],
    '31 cells per row' => [spanTable(1, 31)],
]);

it('accepts a large table without spans', function () {
    expect(DocumentValidator::validate(spanTable(40, 8), false)['content'])->toHaveCount(1);
});

it('words its reasons in the app locale', function () {
    app()->setLocale('en');

    expect(validatorRejection(['type' => 'x']))->toBe(['The document has no valid structure.']);
});
