<?php

use Modules\KnowledgeBase\Content\KbContentRenderer as R;
use Modules\KnowledgeBase\Content\KbContentValidator as V;
use Modules\KnowledgeBase\Content\KbImage;
use Modules\KnowledgeBase\Content\KbLink;
use Tests\TestCase;

uses(TestCase::class);

const KBR_IMG = '/storage/kb-images/0123456789abcdef0123456789abcdef.png';

function kbr_doc(array ...$blocks): array
{
    return ['type' => 'doc', 'content' => $blocks];
}

function kbr_p(array ...$inline): array
{
    return ['type' => 'paragraph', 'content' => $inline];
}

function kbr_t(string $text, array ...$marks): array
{
    return $marks === [] ? ['type' => 'text', 'text' => $text] : ['type' => 'text', 'text' => $text, 'marks' => $marks];
}

function kbr_link(string $href, array $more = []): array
{
    return ['type' => 'link', 'attrs' => ['href' => $href] + $more];
}

it('renders every allowed node and mark with a fixed tag map', function () {
    $html = R::html(kbr_doc(
        ['type' => 'heading', 'attrs' => ['level' => 1], 'content' => [kbr_t('Abschnitt')]],
        ['type' => 'heading', 'attrs' => ['level' => 3], 'content' => [kbr_t('Unterpunkt')]],
        kbr_p(
            kbr_t('fett', ['type' => 'bold']),
            kbr_t(' kursiv', ['type' => 'italic']),
            kbr_t(' unter', ['type' => 'underline']),
            kbr_t(' durch', ['type' => 'strike']),
            ['type' => 'hardBreak'],
            kbr_t('Quelle', kbr_link('https://example.org/a?b=1&c=2', ['target' => '_blank'])),
            kbr_t(' intern', kbr_link('/lexicon/3')),
        ),
        ['type' => 'bulletList', 'content' => [['type' => 'listItem', 'content' => [kbr_p(kbr_t('Punkt'))]]]],
        ['type' => 'orderedList', 'attrs' => ['start' => 3], 'content' => [['type' => 'listItem', 'content' => [kbr_p(kbr_t('drei'))]]]],
        ['type' => 'blockquote', 'content' => [kbr_p(kbr_t('Zitat'))]],
        ['type' => 'table', 'content' => [['type' => 'tableRow', 'content' => [
            ['type' => 'tableHeader', 'content' => [kbr_p(kbr_t('Dosis'))]],
            ['type' => 'tableCell', 'attrs' => ['colspan' => 2, 'rowspan' => 3], 'content' => [kbr_p(kbr_t('4 mg'))]],
        ]]]],
        ['type' => 'image', 'attrs' => ['src' => KBR_IMG, 'alt' => 'Schema']],
        ['type' => 'horizontalRule'],
    ));

    expect($html)->toBe(
        '<h1>Abschnitt</h1><h3>Unterpunkt</h3>'
        .'<p><strong>fett</strong><em> kursiv</em><u> unter</u><s> durch</s><br>'
        .'<a href="https://example.org/a?b=1&amp;c=2" target="_blank" rel="noopener noreferrer">Quelle</a><a href="/lexicon/3"> intern</a></p>'
        .'<ul><li><p>Punkt</p></li></ul><ol start="3"><li><p>drei</p></li></ol>'
        .'<blockquote><p>Zitat</p></blockquote>'
        .'<table><tbody><tr><th><p>Dosis</p></th><td rowspan="3" colspan="2"><p>4 mg</p></td></tr></tbody></table>'
        .'<img src="'.KBR_IMG.'" alt="Schema" loading="lazy"><hr>'
    );
});

it('escapes text, so <script> and markup stay text', function () {
    expect(R::html(kbr_doc(kbr_p(kbr_t('Klick <script>alert(2)</script> & "q" \'s\' <img src=x onerror=alert(1)>')))))
        ->toBe('<p>Klick &lt;script&gt;alert(2)&lt;/script&gt; &amp; &quot;q&quot; &#039;s&#039; &lt;img src=x onerror=alert(1)&gt;</p>');
});

it('escapes attribute values: no breaking out of href or alt', function () {
    expect(R::html(kbr_doc(kbr_p(kbr_t('x', kbr_link('https://x.de/"><img/src=x/onerror=alert(1)>'))))))
        ->toBe('<p><a href="https://x.de/&quot;&gt;&lt;img/src=x/onerror=alert(1)&gt;">x</a></p>')
        ->and(R::html(kbr_doc(['type' => 'image', 'attrs' => ['src' => KBR_IMG, 'alt' => '"><script>alert(1)</script>']])))
        ->toBe('<img src="'.KBR_IMG.'" alt="&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;" loading="lazy">');
});

it('drops unsafe links (text stays), foreign images and unknown nodes when rendering stored content', function () {
    $html = R::html(kbr_doc(
        kbr_p(kbr_t('Klick', kbr_link('javascript:alert(1)')), kbr_t(' <script>alert(2)</script>')),
        ['type' => 'image', 'attrs' => ['src' => 'https://evil.example/x.png', 'alt' => '']],
        ['type' => 'image', 'attrs' => ['src' => '/logout', 'alt' => '']],
        ['type' => 'paragraph', 'attrs' => ['onclick' => 'alert(3)', 'style' => 'color:red'], 'content' => [kbr_t('Rest')]],
        ['type' => 'iframe', 'attrs' => ['src' => 'https://evil.example'], 'content' => [kbr_t('weg')]],
        ['type' => 'codeBlock', 'content' => [kbr_t('auch weg')]],
        kbr_p(kbr_t('bleibt', ['type' => 'highlight'], ['type' => 'bold'])),
        ['type' => 'heading', 'attrs' => ['level' => 6], 'content' => [kbr_t('zu tief')]],
    ));

    expect($html)->toBe('<p>Klick &lt;script&gt;alert(2)&lt;/script&gt;</p><p>Rest</p><p><strong>bleibt</strong></p><h3>zu tief</h3>');
});

it('never prints a dangerous href, whatever the encoding', function () {
    foreach ([
        'javascript' => ['javascript:alert(1)'],
        'entity' => ['&#106;avascript:alert(1)'],
        'tab entity' => ['java&#x09;script:alert(1)'],
        'newline' => ["java\nscript:alert(1)"],
        'control upper' => ["\x01 JAVASCRIPT:alert(1)"],
        'data' => ['data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg=='],
        'vbscript' => ['vbscript:msgbox(1)'],
        'protocol relative' => ['//evil.example'],
        'backslash' => ['/\\evil.example'],
    ] as [$href]) {
        $html = R::html(kbr_doc(kbr_p(kbr_t('x', kbr_link($href)))));

        expect($html)->toBe('<p>x</p>')->not->toContain('href');
    }
});

it('gives target=_blank always rel="noopener noreferrer", and a link without target no rel', function () {
    expect(R::html(kbr_doc(kbr_p(kbr_t('x', kbr_link('https://x.de', ['target' => '_blank', 'rel' => 'opener']))))))
        ->toBe('<p><a href="https://x.de" target="_blank" rel="noopener noreferrer">x</a></p>')
        ->and(R::html(kbr_doc(kbr_p(kbr_t('x', kbr_link('https://x.de', ['target' => '_top', 'rel' => 'opener']))))))
        ->toBe('<p><a href="https://x.de">x</a></p>');
});

it('the own link and image classes print nothing unchecked even when handed raw attributes', function () {
    $link = new KbLink;
    $image = new KbImage;

    expect($link->renderHTML(new stdClass, ['href' => 'javascript:alert(1)', 'target' => '_blank', 'onclick' => 'x']))
        ->toBe(['a', ['target' => '_blank', 'rel' => 'noopener noreferrer'], 0])
        ->and($image->renderHTML(new stdClass, ['src' => 'https://x.de/a.png', 'alt' => 'a']))->toBeNull()
        ->and($image->renderHTML(new stdClass, ['src' => KBR_IMG, 'onerror' => 'x', 'style' => 'y']))->toBe(['content' => '<img src="'.KBR_IMG.'" alt="" loading="lazy">']);
});

it('builds plain text for the search: blocks on lines, breaks kept, nothing escaped, no markup', function () {
    $doc = kbr_doc(
        ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [kbr_t('Adrenalin')]],
        kbr_p(kbr_t('Dosis '), kbr_t('1 mg', ['type' => 'bold']), ['type' => 'hardBreak'], kbr_t('i.v. & <b>')),
        ['type' => 'bulletList', 'content' => [['type' => 'listItem', 'content' => [kbr_p(kbr_t('eins'))]], ['type' => 'listItem', 'content' => [kbr_p(kbr_t('zwei'))]]]],
        ['type' => 'table', 'content' => [['type' => 'tableRow', 'content' => [['type' => 'tableCell', 'content' => [kbr_p(kbr_t('A'))]], ['type' => 'tableCell', 'content' => [kbr_p(kbr_t('B'))]]]]]],
        ['type' => 'image', 'attrs' => ['src' => KBR_IMG, 'alt' => 'Bild']],
        ['type' => 'horizontalRule'],
        ['type' => 'iframe', 'content' => [kbr_t('versteckt')]],
        kbr_p(kbr_t('Link', kbr_link('javascript:alert(1)'))),
    );

    expect(R::plainText($doc))->toBe("Adrenalin\nDosis 1 mg\ni.v. & <b>\neins\nzwei\nA\nB\nLink")
        ->and(R::plainText(V::empty()))->toBe('');
});

it('is idempotent: validate -> render -> plainText gives the same on validated input', function () {
    $doc = kbr_doc(
        kbr_p(kbr_t('a', kbr_link(' https://x.de/a', ['target' => '_blank'])), kbr_t('<i>b</i>', ['type' => 'italic'], ['type' => 'code'])),
        ['type' => 'image', 'attrs' => ['src' => KBR_IMG, 'alt' => 'x', 'onerror' => 'y']],
        ['type' => 'heading', 'attrs' => ['level' => 5], 'content' => [kbr_t('h')]],
    );
    $once = V::normalize($doc);

    expect(V::validate($once))->toBe($once)
        ->and(V::normalize($once))->toBe($once)
        ->and(R::html($once))->toBe(R::html($doc))
        ->and(R::html(V::validate($once)))->toBe(R::html($once))
        ->and(R::plainText($once))->toBe(R::plainText($doc))->toBe("a<i>b</i>\nh");
});

it('refuses (does not render) content that is no document', function () {
    expect(fn () => R::html(['type' => 'paragraph']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => R::plainText(['<p>x</p>']))->toThrow(InvalidArgumentException::class);
});

it('prints alt="" for an image without alt text (decorative), and only the checked src', function () {
    expect(R::html(kbr_doc(['type' => 'image', 'attrs' => ['src' => KBR_IMG]])))->toBe('<img src="'.KBR_IMG.'" alt="" loading="lazy">')
        ->and(R::html(kbr_doc(['type' => 'image', 'attrs' => ['src' => KBR_IMG, 'alt' => '']], kbr_p(kbr_t('x')))))->toBe('<img src="'.KBR_IMG.'" alt="" loading="lazy"><p>x</p>');
});
