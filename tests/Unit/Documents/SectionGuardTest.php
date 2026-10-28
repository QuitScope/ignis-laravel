<?php

use App\Documents\SectionGuard;
use Tests\Support\DocJson as J;
use Tests\TestCase;

uses(TestCase::class);

const GUARD_LOCKED = '11111111-1111-4111-8111-111111111111';
const GUARD_FREE = '22222222-2222-4222-8222-222222222222';

function guardTemplate(): array
{
    return J::doc(
        J::section([J::para([J::text('Aus der Vorlage.')])], 'locked', GUARD_LOCKED),
        J::section([J::para()], 'free', GUARD_FREE),
    );
}

function guardTexts(array $doc): array
{
    $found = [];
    array_walk_recursive($doc, function ($v, $k) use (&$found) {
        if ($k === 'text') {
            $found[] = $v;
        }
    });

    return $found;
}

function guardFieldValue(array $node, string $id): ?string
{
    if (($node['type'] ?? null) === 'docField' && ($node['attrs']['fieldId'] ?? null) === $id) {
        return $node['attrs']['value'] ?? '';
    }
    foreach ($node['content'] ?? [] as $child) {
        if (($v = guardFieldValue($child, $id)) !== null) {
            return $v;
        }
    }

    return null;
}

it('restores a tampered locked section from the template and keeps the free one', function () {
    $tampered = guardTemplate();
    $tampered['content'][0]['content'][0]['content'][0]['text'] = 'Heimlich geändert.';
    $tampered['content'][1]['content'][0] = J::para([J::text('Frei geschrieben.')]);

    $result = (new SectionGuard)->restoreLockedSections(guardTemplate(), $tampered);

    expect(guardTexts($result->document))->toBe(['Aus der Vorlage.', 'Frei geschrieben.'])
        ->and($result->warnings)->toBe(['Abschnitt 1: gesperrter Inhalt wurde aus der Vorlage wiederhergestellt.']);
});

it('warns about nothing when the post equals the result', function (array $template) {
    expect((new SectionGuard)->restoreLockedSections($template, $template)->warnings)->toBe([]);
})->with(fn () => array_map(fn ($d) => [$d], J::legacyTemplates() + ['feature test' => guardTemplate()]));

it('ignores the empty defaults the editor sends', function () {
    $posted = guardTemplate();
    $posted['content'][0]['attrs'] += ['title' => null, 'hint' => null, 'templateSectionId' => null];

    expect((new SectionGuard)->restoreLockedSections(guardTemplate(), $posted)->warnings)->toBe([]);
});

it('warns about every kind of section tampering, naming the section', function () {
    foreach ([
        'locked text' => [function ($d) {
            $d['content'][0]['content'][0]['content'][0]['text'] = 'Anders';

            return $d;
        }, 'Abschnitt 1 „Kopf“: gesperrter Inhalt wurde aus der Vorlage wiederhergestellt.'],
        'locked to free' => [function ($d) {
            $d['content'][0]['attrs']['mode'] = 'free';

            return $d;
        }, 'Abschnitt 1 „Kopf“: Modus wurde auf „gesperrt“ zurückgesetzt.'],
        'free to locked' => [function ($d) {
            $d['content'][1]['attrs']['mode'] = 'locked';

            return $d;
        }, 'Abschnitt 2 „Grund“: Modus wurde auf „frei“ zurückgesetzt.'],
        'attributes' => [function ($d) {
            $d['content'][1]['attrs'] = ['title' => 'Neu', 'repeatable' => true, 'hint' => null] + $d['content'][1]['attrs'];

            return $d;
        }, 'Abschnitt 2 „Grund“: Eigenschaften (title, hint, repeatable) wurden aus der Vorlage übernommen.'],
        'group attribute' => [function ($d) {
            $d['content'][1]['attrs']['templateSectionId'] = 'grund';

            return $d;
        }, 'Abschnitt 2 „Grund“: Eigenschaften (templateSectionId) wurden aus der Vorlage übernommen.'],
        'reordered' => [fn ($d) => J::doc($d['content'][1], $d['content'][0]), 'Die Reihenfolge der Abschnitte wurde aus der Vorlage wiederhergestellt.'],
        'deleted' => [fn ($d) => J::doc($d['content'][0]), 'Abschnitt 2 „Grund“ fehlte und kommt aus der Vorlage.'],
        'foreign' => [fn ($d) => J::doc($d['content'][0], $d['content'][1], J::section([J::para()], 'free', 'x', ['title' => 'Fremd'])),
            'Ein Abschnitt, den die Vorlage nicht kennt („Fremd“), wurde verworfen.'],
        'duplicated' => [fn ($d) => J::doc($d['content'][0], $d['content'][1], $d['content'][1]),
            'Abschnitt 2 „Grund“ ist nicht wiederholbar, weitere Exemplare wurden verworfen.'],
        'other id' => [function ($d) {
            $d['content'][1]['attrs'] = ['sectionId' => 'anders', 'templateSectionId' => 'grund'] + $d['content'][1]['attrs'];

            return $d;
        }, 'Abschnitt 2 „Grund“: Kennung wurde aus der Vorlage übernommen.'],
    ] as [$tamper, $warning]) {
        $template = J::doc(
            J::section([J::para([J::text('Kopf')])], 'locked', 'kopf', ['title' => 'Kopf']),
            J::section([J::para()], 'free', 'grund', ['title' => 'Grund', 'hint' => 'Kurz']),
        );

        expect((new SectionGuard)->restoreLockedSections($template, $tamper($template))->warnings)->toContain($warning);
    }
});

it('warns when a field value is cleared or cut, naming field and section', function () {
    $template = J::doc(J::section([J::para([
        J::field('kurs', ['label' => 'Lehrgang', 'value' => '', 'options' => [['label' => 'A']]]),
        J::field('zeit', ['label' => 'Zeitraum', 'maxLength' => 3, 'value' => '']),
    ])], 'locked', 'k', ['title' => 'Kopf']));
    $posted = J::doc(J::section([J::para([
        J::field('kurs', ['label' => 'Lehrgang', 'value' => 'B', 'options' => [['label' => 'A']]]),
        J::field('zeit', ['label' => 'Zeitraum', 'maxLength' => 9, 'value' => 'abcdef']),
    ])], 'locked', 'k', ['title' => 'Kopf']));

    expect((new SectionGuard)->restoreLockedSections($template, $posted)->warnings)->toContain(
        'Abschnitt 1 „Kopf“: Feld „Lehrgang“: der Wert steht nicht in der Auswahl und wurde geleert.',
        'Abschnitt 1 „Kopf“: Feld „Zeitraum“: der Wert wurde auf 3 Zeichen gekürzt.',
    );
});

it('keeps the template definition of a field in a free section', function () {
    $field = ['label' => 'Lehrgang', 'required' => true, 'maxLength' => 20, 'value' => '', 'options' => [['label' => 'A']]];
    $template = J::doc(J::section([J::para([J::text('Kurs: '), J::field('kurs', $field), J::field('weg', ['label' => 'Weg', 'value' => ''])])], 'free', 'f'));
    $posted = J::doc(J::section([J::para([J::text('Eigener Text '), J::field('kurs', ['label' => 'Lehrgang', 'value' => 'Ehrenkommandant']), J::field('neu', ['label' => 'Neu', 'value' => 'x'])])], 'free', 'f'));

    $result = (new SectionGuard)->restoreLockedSections($template, $posted);
    $inline = $result->document['content'][0]['content'][0]['content'];

    expect($inline)->toHaveCount(2)
        ->and($inline[0]['text'])->toBe('Eigener Text ')
        ->and($inline[1]['attrs'])->toBe(['fieldId' => 'kurs'] + $field)
        ->and($result->warnings)->toBe([
            'Abschnitt 1: Feld „Lehrgang“: Einstellungen wurden aus der Vorlage übernommen.',
            'Abschnitt 1: Feld „Lehrgang“: der Wert steht nicht in der Auswahl und wurde geleert.',
            'Abschnitt 1: Feld „Neu“ kennt die Vorlage nicht, es wurde entfernt.',
        ]);

    $ok = J::doc(J::section([J::para([J::field('kurs', ['value' => 'A'] + $field)])], 'free', 'f'));
    $kept = (new SectionGuard)->restoreLockedSections($template, $ok);
    expect(guardFieldValue($kept->document, 'kurs'))->toBe('A')->and($kept->warnings)->toBe([]);
});

it('takes the locked text from the current template', function () {
    $posted = guardTemplate();
    $changed = guardTemplate();
    $changed['content'][0]['content'][0]['content'][0]['text'] = 'Neue Fassung.';

    expect(guardTexts((new SectionGuard)->restoreLockedSections($changed, $posted)->document))->toBe(['Neue Fassung.']);
});

it('neutralises reordering, deleting, adding and relabelling sections', function () {
    $posted = J::doc(
        J::section([J::para([J::text('Frei.')])], 'free', GUARD_FREE),
        J::section([J::para([J::text('Eingeschmuggelt.')])], 'free', 'fremd'),
        J::para([J::text('Lose oben.')]),
    );
    $result = (new SectionGuard)->restoreLockedSections(guardTemplate(), $posted);

    expect($result->document['content'][0]['attrs'])->toBe(['mode' => 'locked', 'sectionId' => GUARD_LOCKED])
        ->and($result->document['content'][1]['attrs']['sectionId'])->toBe(GUARD_FREE)
        ->and(guardTexts($result->document))->toBe(['Aus der Vorlage.', 'Frei.'])
        ->and($result->warnings)->toHaveCount(3);

    $flipped = guardTemplate();
    $flipped['content'][0]['attrs']['mode'] = 'free';
    $flipped['content'][0]['content'] = [J::para([J::text('Übernommen?')])];
    expect(guardTexts((new SectionGuard)->restoreLockedSections(guardTemplate(), $flipped)->document))->toBe(['Aus der Vorlage.']);
});

it('restores a deleted free section from the template', function () {
    $result = (new SectionGuard)->restoreLockedSections(guardTemplate(), J::doc(guardTemplate()['content'][0]));

    expect($result->document)->toEqual(guardTemplate())
        ->and($result->warnings)->toHaveCount(1);
});

it('keeps the field values of a locked section, but only from the option list and within maxLength', function () {
    $field = fn (string $value) => J::field('kurs', ['label' => 'Kurs', 'required' => true, 'maxLength' => 12, 'value' => $value,
        'options' => [['label' => 'Zugführer/-in', 'male' => 'Zugführer', 'female' => 'Zugführerin']]]);
    $template = J::doc(J::section([J::para([J::text('Lehrgang '), $field('')]), J::para([J::field('frei', ['maxLength' => 5, 'value' => ''])])], 'locked', GUARD_LOCKED));
    $post = function (string $kurs, string $frei, array $extra = []) use ($field) {
        $kursField = $field($kurs);
        $kursField['attrs'] = $extra + $kursField['attrs'];

        return J::doc(J::section([J::para([J::text('Lehrgang '), $kursField]), J::para([J::field('frei', ['value' => $frei])])], 'locked', GUARD_LOCKED));
    };
    $guard = new SectionGuard;

    $ok = $guard->restoreLockedSections($template, $post('Zugführerin', 'abc'))->document;
    expect(guardFieldValue($ok, 'kurs'))->toBe('Zugführerin')->and(guardFieldValue($ok, 'frei'))->toBe('abc');

    $bad = $guard->restoreLockedSections($template, $post('Ehrenkommandant', 'abcdefgh', ['label' => 'Gehackt', 'options' => []]))->document;
    expect(guardFieldValue($bad, 'kurs'))->toBe('')
        ->and(guardFieldValue($bad, 'frei'))->toBe('abcde')
        ->and($bad['content'][0]['content'][0]['content'][1]['attrs']['label'])->toBe('Kurs')
        ->and($bad['content'][0]['content'][0]['content'][1]['attrs']['options'])->toHaveCount(1);
});

it('validates the course choice of the legacy course certificates', function () {
    $template = J::legacyTemplates()['Lehrgangszertifikat'];
    $withValue = function (array $node, string $value) use (&$withValue) {
        if (($node['type'] ?? null) === 'docField' && $node['attrs']['fieldId'] === 'qualifikation') {
            $node['attrs']['value'] = $value;
        }
        foreach ($node['content'] ?? [] as $i => $child) {
            $node['content'][$i] = $withValue($child, $value);
        }

        return $node;
    };
    $guard = new SectionGuard;

    expect(guardFieldValue($guard->restoreLockedSections($template, $withValue($template, 'Brandmeisterin'))->document, 'qualifikation'))->toBe('Brandmeisterin')
        ->and(guardFieldValue($guard->restoreLockedSections($template, $withValue($template, 'Ehrenkommandant'))->document, 'qualifikation'))->toBe('');
});

it('keeps repeated instances of a repeatable group in their posted order', function () {
    $template = J::doc(
        J::section([J::para([J::text('Kopf')])], 'locked', 'kopf'),
        J::section([J::para([J::text('Vorfall: '), J::field('datum', ['value' => ''])])], 'locked', 'vorfall', ['repeatable' => true]),
        J::section([J::para()], 'free', 'grund', ['repeatable' => true]),
    );
    $posted = J::doc(
        J::section([J::para([J::text('Kopf')])], 'locked', 'kopf'),
        J::section([J::para([J::text('Vorfall: '), J::field('datum', ['value' => '1.1.'])])], 'locked', 'vorfall', ['repeatable' => true]),
        J::section([J::para([J::text('Gefälscht: '), J::field('datum', ['value' => '2.1.'])])], 'locked', 'v2', ['repeatable' => true, 'templateSectionId' => 'vorfall']),
        J::section([J::para([J::text('B')])], 'free', 'g2', ['templateSectionId' => 'grund', 'repeatable' => true]),
        J::section([J::para([J::text('A')])], 'free', 'grund', ['repeatable' => true]),
    );

    $result = (new SectionGuard)->restoreLockedSections($template, $posted);
    $ids = array_map(fn ($s) => $s['attrs']['sectionId'], $result->document['content']);

    expect($ids)->toBe(['kopf', 'vorfall', 'v2', 'g2', 'grund'])
        ->and(guardTexts($result->document))->toBe(['Kopf', 'Vorfall: ', 'Vorfall: ', 'B', 'A'])
        ->and(guardFieldValue($result->document['content'][2], 'datum'))->toBe('2.1.')
        ->and($result->document['content'][2]['attrs']['templateSectionId'])->toBe('vorfall')
        ->and($result->warnings)->toBe(['Abschnitt 2: gesperrter Inhalt wurde aus der Vorlage wiederhergestellt.']);
});

it('drops extra instances of a section that is not repeatable and gives clashing ids a new one', function () {
    $posted = J::doc(
        guardTemplate()['content'][0],
        J::section([J::para([J::text('Eins')])], 'free', GUARD_FREE),
        J::section([J::para([J::text('Zwei')])], 'free', 'x', ['templateSectionId' => GUARD_FREE]),
    );
    $result = (new SectionGuard)->restoreLockedSections(guardTemplate(), $posted);

    expect(guardTexts($result->document))->toBe(['Aus der Vorlage.', 'Eins'])->and($result->warnings)->toHaveCount(1);

    $repeatable = J::doc(J::section([J::para()], 'free', 'r', ['repeatable' => true]));
    $clash = J::doc(J::section([J::para()], 'free', 'r'), J::section([J::para()], 'free', 'r', ['templateSectionId' => 'r']));
    $ids = array_map(fn ($s) => $s['attrs']['sectionId'], (new SectionGuard)->restoreLockedSections($repeatable, $clash)->document['content']);
    expect($ids[0])->toBe('r')->and($ids[1])->not->toBe('r')->and($ids[1])->toMatch('/^[0-9a-f-]{36}$/');
});

it('refuses a broken template', function (array $template) {
    (new SectionGuard)->restoreLockedSections($template, guardTemplate());
})->with([
    'no sections' => [J::doc()],
    'loose paragraph' => [J::doc(J::para())],
    'duplicate id' => [J::doc(J::section([J::para()], 'locked', 'a'), J::section([J::para()], 'free', 'a'))],
    'not a doc' => [['type' => 'paragraph']],
])->throws(InvalidArgumentException::class);

it('takes the editor default attributes and mark order for the stored template', function () {
    $list = fn (array $attrs) => array_filter(['type' => 'orderedList', 'attrs' => $attrs, 'content' => [['type' => 'listItem', 'content' => [J::para([J::text('Punkt')])]]]]);
    $table = fn (array $attrs) => ['type' => 'table', 'content' => [['type' => 'tableRow', 'content' => [array_filter(['type' => 'tableCell', 'attrs' => $attrs, 'content' => [J::para()]])]]]];
    $locked = fn (array $listAttrs, array $cellAttrs, array $marks) => J::doc(J::section([
        J::para([J::text('Fett und kursiv', ...$marks)]), $list($listAttrs), $table($cellAttrs),
    ], 'locked', GUARD_LOCKED));

    $template = $locked([], [], ['italic', 'bold']);
    $editor = $locked(['start' => 1], ['colspan' => 1, 'rowspan' => 1], ['bold', 'italic']);
    expect((new SectionGuard)->restoreLockedSections($template, $editor)->warnings)->toBe([]);

    $tampered = $locked(['start' => 2], ['colspan' => 1, 'rowspan' => 1], ['bold', 'italic']);
    expect((new SectionGuard)->restoreLockedSections($template, $tampered)->warnings)
        ->toBe(['Abschnitt 1: gesperrter Inhalt wurde aus der Vorlage wiederhergestellt.']);
    $unmarked = $locked([], [], ['bold']);
    expect((new SectionGuard)->restoreLockedSections($template, $unmarked)->warnings)->toHaveCount(1);
});
