<?php

use App\Models\Rank;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Mail\Content\MailContentRenderer;
use Modules\Mail\Content\MailContentValidator;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

const MV_BADGE = '/assets/img/dienstgrade/bf/1.png';

beforeEach(fn () => Rank::factory()->create(['badge' => MV_BADGE]));

function mv_doc(array ...$blocks): array
{
    return ['type' => 'doc', 'content' => $blocks];
}

function mv_p(array ...$inline): array
{
    return $inline === [] ? ['type' => 'paragraph'] : ['type' => 'paragraph', 'content' => $inline];
}

function mv_t(string $text, array $marks = []): array
{
    return ['type' => 'text', 'text' => $text] + ($marks === [] ? [] : ['marks' => $marks]);
}

function mv_link(string $href): array
{
    return mv_doc(mv_p(mv_t('hier', [['type' => 'link', 'attrs' => ['href' => $href]]])));
}

function mv_rejects(mixed $doc): string
{
    try {
        MailContentValidator::validate($doc);
    } catch (InvalidArgumentException $e) {
        return $e->getMessage();
    }
    throw new RuntimeException('accepted: '.json_encode($doc));
}

it('accepts every node and mark of the preset unchanged', function () {
    $doc = mv_doc(
        mv_p(mv_t('fett', [['type' => 'bold'], ['type' => 'italic'], ['type' => 'strike']]), ['type' => 'hardBreak'], mv_t('Link', [['type' => 'link', 'attrs' => ['href' => 'mailto:a@ignis.ef']]])),
        mv_p(['type' => 'image', 'attrs' => ['src' => MV_BADGE, 'alt' => '']], mv_t('Brandmeister')),
        ['type' => 'blockquote', 'content' => [mv_p(mv_t('Zitat'))]],
        ['type' => 'bulletList', 'content' => [['type' => 'listItem', 'content' => [mv_p(mv_t('a'))]]]],
        ['type' => 'orderedList', 'attrs' => ['start' => 3], 'content' => [['type' => 'listItem', 'content' => [mv_p(mv_t('b'))]]]],
        mv_p(),
    );

    expect(MailContentValidator::validate($doc))->toBe($doc)
        ->and(MailContentValidator::validate(json_encode($doc)))->toBe($doc);
});

it('drops editor null attributes and recomputes link attributes', function () {
    $doc = mv_doc(mv_p(mv_t('x', [['type' => 'link', 'attrs' => ['href' => 'https://example.org', 'target' => '_blank', 'rel' => 'opener', 'class' => null]]])));

    expect(MailContentValidator::validate($doc)['content'][0]['content'][0]['marks'])->toBe([['type' => 'link', 'attrs' => ['href' => 'https://example.org']]]);
});

it('refuses unknown nodes, marks and attributes', function () {
    foreach ([
        'script node' => [mv_doc(['type' => 'script', 'content' => [mv_t('alert(1)')]])],
        'heading' => [mv_doc(['type' => 'heading', 'attrs' => ['level' => 1], 'content' => [mv_t('x')]])],
        'docVariable in a mail' => [mv_doc(mv_p(['type' => 'docVariable', 'attrs' => ['name' => 'absender.name']]))],
        'code mark' => [mv_doc(mv_p(mv_t('x', [['type' => 'code']])))],
        'underline mark' => [mv_doc(mv_p(mv_t('x', [['type' => 'underline']])))],
        'onclick attribute' => [mv_doc(['type' => 'paragraph', 'attrs' => ['onclick' => 'alert(1)'], 'content' => [mv_t('x')]])],
        'style attribute' => [mv_doc(['type' => 'paragraph', 'attrs' => ['style' => 'color:red']])],
        'onerror on a badge' => [mv_doc(mv_p(['type' => 'image', 'attrs' => ['src' => MV_BADGE, 'alt' => '', 'onerror' => 'alert(1)']]))],
        'html key on a node' => [mv_doc(['type' => 'paragraph', 'html' => '<script>alert(1)</script>'])],
        'list item at the top' => [mv_doc(['type' => 'listItem', 'content' => [mv_p()]])],
        'text not a string' => [mv_doc(mv_p(['type' => 'text', 'text' => ['<script>']]))],
        'text a number' => [mv_doc(mv_p(['type' => 'text', 'text' => 5]))],
        'empty text' => [mv_doc(mv_p(['type' => 'text', 'text' => '']))],
        'content not a list' => [mv_doc(['type' => 'paragraph', 'content' => 'x'])],
        'no doc' => [['type' => 'paragraph']],
    ] as [$doc]) {
        expect(mv_rejects($doc))->not->toBe('');
    }
});

it('allows links only to http, https and mailto', function () {
    foreach ([
        'javascript:alert(1)', 'JAVASCRIPT:alert(1)', ' javascript:alert(1)', "java\tscript:alert(1)", 'data:text/html;base64,PHNjcmlwdD4=',
        'vbscript:x', '/relative', '//evil.example', 'https:/\\evil.example', '&#106;avascript:alert(1)', 'https://', 'mailto:',
    ] as $href) {
        expect(mv_rejects(mv_link($href)))->toContain(__('mail.content.link'));
    }
});

it('allows images only for rank badges of this instance', function () {
    foreach ([
        'https://example.org/track.png', '//evil.example'.MV_BADGE, 'data:image/png;base64,AAAA', 'javascript:alert(1)',
        '/assets/img/dienstgrade/bf/2.png', '/assets/img/dienstgrade/bf/../../secret.png', MV_BADGE.'?x=1', MV_BADGE.'" onerror="alert(1)',
    ] as $src) {
        expect(mv_rejects(mv_doc(mv_p(['type' => 'image', 'attrs' => ['src' => $src, 'alt' => '']]))))->toContain(__('mail.content.image'));
    }
});

it('accepts the legacy cache buster of a badge', function () {
    $doc = mv_doc(mv_p(['type' => 'image', 'attrs' => ['src' => MV_BADGE.'?v=12', 'alt' => '']]));

    expect(MailContentValidator::validate($doc))->toBe($doc);
});

it('refuses oversize and too deep text before decoding', function () {
    $big = json_encode(mv_doc(mv_p(mv_t(str_repeat('a', 200_001)))));
    expect(mv_rejects($big))->toBe(__('mail.body.too_large', ['max' => 200]))
        ->and(mv_rejects(json_decode($big, true)))->toBe(__('mail.body.too_large', ['max' => 200]));

    $node = mv_p(mv_t('tief'));
    for ($i = 0; $i < 15; $i++) {
        $node = ['type' => 'blockquote', 'content' => [$node]];
    }
    expect(mv_rejects(json_encode(mv_doc($node))))->toBe(__('mail.body.too_deep'))
        ->and(mv_rejects(mv_doc($node)))->toBe(__('mail.body.too_deep'))
        ->and(mv_rejects('{kaputt'))->toBe(__('mail.body.invalid'));
});

it('cuts quotes nested deeper than ten levels', function () {
    $node = mv_p(mv_t('innen'));
    for ($i = 0; $i < 12; $i++) {
        $node = ['type' => 'blockquote', 'content' => [$node]];
    }
    $capped = MailContentValidator::validate(mv_doc($node));

    $depth = function (array $n) use (&$depth): int {
        $inner = 0;
        foreach ($n['content'] ?? [] as $c) {
            $inner = max($inner, $depth($c));
        }

        return $inner + ($n['type'] === 'blockquote' ? 1 : 0);
    };
    expect($depth($capped))->toBe(10)
        ->and(json_encode($capped, JSON_UNESCAPED_UNICODE))->toContain(__('mail.quote.capped'))->not->toContain('innen');
});

it('normalize drops what is not allowed and keeps the text', function () {
    $doc = mv_doc(
        ['type' => 'script', 'content' => [mv_t('alert(1)')]],
        mv_p(mv_t('Link', [['type' => 'link', 'attrs' => ['href' => 'javascript:alert(1)']], ['type' => 'code']]), ['type' => 'image', 'attrs' => ['src' => 'https://evil.example/a.png']]),
    );

    expect(MailContentValidator::normalize($doc))->toBe(mv_doc(mv_p(mv_t('Link'))));
});

it('drops marks on a badge in both modes and keeps the rest', function () {
    $marks = [['type' => 'bold'], ['type' => 'link', 'attrs' => ['href' => 'https://example.org']]];
    $doc = mv_doc(mv_p(['type' => 'image', 'attrs' => ['src' => MV_BADGE, 'alt' => ''], 'marks' => $marks], mv_t('Brandmeister', $marks)));
    $clean = mv_doc(mv_p(['type' => 'image', 'attrs' => ['src' => MV_BADGE, 'alt' => '']], mv_t('Brandmeister', [['type' => 'bold'], ['type' => 'link', 'attrs' => ['href' => 'https://example.org']]])));

    expect(MailContentValidator::validate($doc))->toBe($clean)
        ->and(MailContentValidator::normalize($doc))->toBe($clean)
        ->and(MailContentRenderer::html($doc))
        ->toBe('<p><img class="mail-badge" src="'.MV_BADGE.'" alt=""><strong><a href="https://example.org" target="_blank" rel="noopener noreferrer">Brandmeister</a></strong></p>');
});
