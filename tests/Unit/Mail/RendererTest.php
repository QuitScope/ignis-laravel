<?php

use App\Models\Rank;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Mail\Content\MailContentRenderer;
use Modules\Mail\Content\PlainText;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function mr_doc(array ...$inline): array
{
    return ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => $inline]]];
}

function mr_link(string $href): array
{
    return mr_doc(['type' => 'text', 'text' => 'hier', 'marks' => [['type' => 'link', 'attrs' => ['href' => $href]]]]);
}

it('renders links only with an allowed scheme, in a new tab with noopener noreferrer', function () {
    expect(MailContentRenderer::html(mr_link('https://example.org/a')))
        ->toBe('<p><a href="https://example.org/a" target="_blank" rel="noopener noreferrer">hier</a></p>')
        ->and(MailContentRenderer::html(mr_link('mailto:a@ignis.ef')))->toContain('href="mailto:a@ignis.ef"')
        ->and(MailContentRenderer::html(mr_link('javascript:alert(1)')))->toBe('<p>hier</p>')
        ->and(MailContentRenderer::html(mr_link('data:text/html,<script>alert(1)</script>')))->toBe('<p>hier</p>')
        ->and(MailContentRenderer::html(mr_link('https://example.org/"onmouseover="alert(1)')))->not->toContain('"onmouseover');
});

it('shows the rank badge as an image with a class and drops foreign images', function () {
    Rank::factory()->create(['badge' => '/assets/img/dienstgrade/bf/1.png']);

    expect(MailContentRenderer::html(mr_doc(
        ['type' => 'image', 'attrs' => ['src' => '/assets/img/dienstgrade/bf/1.png?v=1', 'alt' => '']],
        ['type' => 'text', 'text' => 'Branddirektor'],
        ['type' => 'image', 'attrs' => ['src' => 'https://example.org/track.png', 'alt' => '']],
        ['type' => 'image', 'attrs' => ['src' => '/assets/img/dienstgrade/bf/1.png', 'alt' => 'x', 'onerror' => 'alert(1)']],
    )))->toBe('<p><img class="mail-badge" src="/assets/img/dienstgrade/bf/1.png?v=1" alt="">Branddirektor<img class="mail-badge" src="/assets/img/dienstgrade/bf/1.png" alt="x"></p>');
});

it('escapes text and never passes raw html through', function () {
    $html = MailContentRenderer::html(['type' => 'doc', 'content' => [
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => '<script>alert(1)</script><img src=x onerror=alert(2)>']]],
        ['type' => 'html', 'content' => '<script>alert(3)</script>'],
        ['type' => 'paragraph', 'attrs' => ['style' => 'background:url(javascript:x)', 'onclick' => 'alert(4)'], 'content' => [['type' => 'text', 'text' => 'a']]],
        ['type' => 'blockquote', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'z', 'marks' => [['type' => 'bold'], ['type' => 'textStyle', 'attrs' => ['color' => 'red']]]]]]]],
    ]]);

    expect($html)->toBe('<p>&lt;script&gt;alert(1)&lt;/script&gt;&lt;img src=x onerror=alert(2)&gt;</p><p>a</p><blockquote><p><strong>z</strong></p></blockquote>');
});

it('builds plain text for the search and the preview', function () {
    $doc = ['type' => 'doc', 'content' => [
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Hallo Anna,']]],
        ['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => 'anbei der Plan für '], ['type' => 'text', 'text' => 'Mo & Di', 'marks' => [['type' => 'bold']]],
            ['type' => 'text', 'text' => '.'], ['type' => 'hardBreak'], ['type' => 'text', 'text' => "Zeile\u{00A0}zwei"],
        ]],
        ['type' => 'bulletList', 'content' => [['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Punkt']]]]]]],
        ['type' => 'blockquote', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'a < b']]]]],
    ]];

    expect(PlainText::of($doc))->toBe('Hallo Anna, anbei der Plan für Mo & Di. Zeile zwei Punkt a < b')
        ->and(PlainText::of(['type' => 'doc', 'content' => []]))->toBe('')
        ->and(PlainText::search('Betreff', $doc))->toStartWith("Betreff\nHallo Anna,")
        ->and(strlen(PlainText::search('B', mr_doc(['type' => 'text', 'text' => str_repeat('ä', 50_000)]))))->toBeLessThanOrEqual(PlainText::SEARCH_MAX_BYTES);
});
