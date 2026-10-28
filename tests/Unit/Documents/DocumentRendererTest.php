<?php

use App\Documents\DocumentRenderer;
use App\Documents\InvalidDocument;
use App\Documents\VariableCatalog;
use Tests\Support\DocJson as J;
use Tests\TestCase;

uses(TestCase::class);

function renderDoc(array $doc, array $vars = [], bool $blank = false): string
{
    return (new DocumentRenderer)->render($doc, $vars, $blank)->html;
}

it('renders the allowed nodes and marks to a fixed set of tags', function () {
    $cell = fn (string $type) => ['type' => $type, 'attrs' => ['colspan' => 2], 'content' => [J::para([J::text('z')])]];
    $html = renderDoc(J::doc(J::section([
        ['type' => 'heading', 'attrs' => ['level' => 2, 'textAlign' => 'center'], 'content' => [J::text('Titel')]],
        J::para([J::text('fett', 'bold'), J::text(' kursiv', 'italic'), J::text('u', 'underline'), J::text('s', 'strike'), ['type' => 'hardBreak']], ['textAlign' => 'justify']),
        ['type' => 'bulletList', 'content' => [['type' => 'listItem', 'content' => [J::para([J::text('a')])]]]],
        ['type' => 'orderedList', 'attrs' => ['start' => 3], 'content' => [['type' => 'listItem', 'content' => [J::para([J::text('b')])]]]],
        ['type' => 'blockquote', 'content' => [J::para([J::text('c')])]],
        ['type' => 'table', 'content' => [['type' => 'tableRow', 'content' => [$cell('tableHeader'), $cell('tableCell')]]]],
        ['type' => 'horizontalRule'],
        ['type' => 'pageBreak'],
    ], 'free', 's1', ['title' => 'Nie im HTML', 'hint' => 'Auch nicht'])));

    expect($html)->toBe(
        '<section class="efe-section">'
        .'<h2 style="text-align: center;">Titel</h2>'
        .'<p style="text-align: justify;"><strong>fett</strong><em> kursiv</em><u>u</u><s>s</s><br></p>'
        .'<ul><li><p>a</p></li></ul>'
        .'<ol start="3"><li><p>b</p></li></ol>'
        .'<blockquote><p>c</p></blockquote>'
        .'<table><tbody><tr><th colspan="2"><p>z</p></th><td colspan="2"><p>z</p></td></tr></tbody></table>'
        .'<hr>'
        .'<div class="efe-page-break"></div>'
        .'</section>'
    );
});

it('escapes text, variable values and field values', function () {
    $html = renderDoc(J::doc(J::section([J::para([
        J::text('<script>alert(1)</script> & "x"'),
        J::variable('mitarbeiter.name'),
        J::field('f', ['value' => '<img src=x onerror=alert(1)>']),
    ])])), ['mitarbeiter.name' => '<b>Name</b>']);

    expect($html)->toBe('<section class="efe-section"><p>&lt;script&gt;alert(1)&lt;/script&gt; &amp; &quot;x&quot;'
        .'<span class="efe-variable">&lt;b&gt;Name&lt;/b&gt;</span>'
        .'<span class="efe-field">&lt;img src=x onerror=alert(1)&gt;</span></p></section>')
        ->and($html)->not->toContain('<script')->not->toContain('<img');
});

it('refuses to render a document the validator rejects', function () {
    renderDoc(J::doc(J::para([J::text('x') + ['marks' => [['type' => 'link', 'attrs' => ['href' => 'javascript:alert(1)']]]]])));
})->throws(InvalidDocument::class);

it('shows unresolved variables as placeholder or blanks them', function () {
    $doc = J::doc(J::para([J::text('Gezeichnet: '), J::variable('mitarbeiter.name'), J::variable('mitarbeiter.titel')]));

    expect(renderDoc($doc, ['mitarbeiter.name' => 'Testperson']))
        ->toBe('<p>Gezeichnet: <span class="efe-variable">Testperson</span><span class="efe-variable">{{mitarbeiter.titel}}</span></p>')
        ->and(renderDoc($doc, ['mitarbeiter.name' => 'Testperson'], true))
        ->toContain('<span class="efe-variable">Testperson</span><span class="efe-variable"></span>')
        ->not->toContain('{{')
        ->and(DocumentRenderer::unresolvedVariables($doc, ['mitarbeiter.name' => 'Testperson']))->toBe(['mitarbeiter.titel']);
});

it('takes a field value with options only from the list', function () {
    $options = [['label' => 'Zugführer/-in', 'male' => 'Zugführer', 'female' => 'Zugführerin'], ['label' => 'HEMS-TC']];
    $doc = fn (string $value) => J::doc(J::para([J::field('q', ['label' => 'Lehrgang', 'required' => true, 'options' => $options, 'value' => $value])]));
    $renderer = new DocumentRenderer;

    expect($renderer->render($doc('Zugführerin'), [], true)->html)->toContain('<span class="efe-field">Zugführerin</span>')
        ->and($renderer->render($doc('HEMS-TC'), [], true)->html)->toContain('>HEMS-TC<')
        ->and($renderer->render($doc('Zugführer/-in'), [], true)->html)->toContain('>Zugführer/-in<');

    $foreign = $renderer->render($doc('Ehrenkommandant'), [], true);
    expect($foreign->html)->toContain('<span class="efe-field"></span>')
        ->and($foreign->warnings)->toBe(['Pflichtfeld leer: Lehrgang']);
});

it('warns about empty required fields only', function () {
    $doc = J::doc(J::para([
        J::field('a', ['label' => 'Dienstgrad', 'required' => true, 'value' => '']),
        J::field('b', ['label' => 'Optional', 'required' => false, 'value' => '']),
        J::field('c', ['label' => 'Datum', 'required' => true, 'value' => '1.1.2026']),
        J::field('d', ['required' => true, 'value' => '  ']),
    ]));

    expect((new DocumentRenderer)->render($doc, [], true)->warnings)->toBe(['Pflichtfeld leer: Dienstgrad', 'Pflichtfeld leer: d']);
});

it('renders every legacy system template without structural warnings', function (array $doc) {
    $vars = array_map(fn () => 'X', VariableCatalog::catalog());
    $result = (new DocumentRenderer)->render($doc, $vars, true);

    expect($result->html)->not->toBe('')->not->toContain('{{')
        ->and(array_filter($result->warnings, fn ($w) => ! str_starts_with($w, 'Pflichtfeld leer: ')))->toBe([]);
})->with(fn () => array_map(fn ($d) => [$d], J::legacyTemplates()));

it('prints a non-scalar variable value as empty', function () {
    $doc = J::doc(J::para([J::variable('mitarbeiter.name'), J::variable('datum')]));

    expect(renderDoc($doc, ['mitarbeiter.name' => ['x'], 'datum' => 5]))
        ->toBe('<p><span class="efe-variable"></span><span class="efe-variable">5</span></p>');
});
