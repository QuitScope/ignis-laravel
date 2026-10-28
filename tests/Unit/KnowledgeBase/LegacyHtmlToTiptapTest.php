<?php

use Modules\KnowledgeBase\Content\KbContentValidator;
use Modules\KnowledgeBase\Import\LegacyHtmlToTiptap;
use Tests\TestCase;

uses(TestCase::class);

const KBH_IMG = '/storage/kb-images/0123456789abcdef0123456789abcdef.png';

function kbh(string $html, ?LegacyHtmlToTiptap $c = null): array
{
    $c ??= new LegacyHtmlToTiptap;
    $doc = $c->convert($html);
    expect([$c->truncatedDepth, $c->truncatedNodes])->toBe([0, 0])
        ->and(KbContentValidator::validate($doc))->toBe($doc)
        ->and(KbContentValidator::normalize($doc))->toBe($doc);

    return $doc['content'];
}

function kbh_p(array ...$inline): array
{
    return ['type' => 'paragraph', 'content' => $inline];
}

function kbh_t(string $text, string ...$marks): array
{
    return ['type' => 'text', 'text' => $text] + ($marks === [] ? [] : ['marks' => array_map(fn ($m) => ['type' => $m], $marks)]);
}

it('converts paragraphs, breaks, rules and the four text marks', function () {
    expect(kbh('<p>a <strong>b</strong> <b>c</b> <em>d</em> <i>e</i> <u>f</u> <s>g</s></p><hr><p>x<br>y</p>'))->toBe([
        kbh_p(kbh_t('a '), kbh_t('b', 'bold'), kbh_t(' '), kbh_t('c', 'bold'), kbh_t(' '), kbh_t('d', 'italic'), kbh_t(' '),
            kbh_t('e', 'italic'), kbh_t(' '), kbh_t('f', 'underline'), kbh_t(' '), kbh_t('g', 'strike')),
        ['type' => 'horizontalRule'],
        kbh_p(kbh_t('x'), ['type' => 'hardBreak'], kbh_t('y')),
    ]);
});

it('nests marks and merges neighbouring text with the same marks', function () {
    expect(kbh('<p><strong>a<em>b</em></strong><strong>c</strong>d</p>'))->toBe([
        kbh_p(kbh_t('a', 'bold'), kbh_t('b', 'bold', 'italic'), kbh_t('c', 'bold'), kbh_t('d')),
    ]);
});

it('shifts headings one level up (h2-h4 => 1-3) and counts h1, h5, h6', function () {
    $c = new LegacyHtmlToTiptap;
    $levels = array_map(fn ($b) => $b['attrs']['level'], kbh('<h1>a</h1><h2>b</h2><h3>c</h3><h4>d</h4><h5>e</h5><h6>f</h6>', $c));

    expect($levels)->toBe([1, 1, 2, 3, 3, 3])
        ->and($c->headings)->toBe(['h1' => 1, 'h5' => 1, 'h6' => 1])->and($c->dropped)->toBe([]);
});

it('drops empty headings and empty paragraphs but keeps non-breaking spaces', function () {
    expect(kbh('<h2> </h2><p></p><p>   </p><p>&nbsp;</p>'))->toBe([kbh_p(kbh_t("\u{A0}"))]);
});

it('converts nested lists with their attributes', function () {
    expect(kbh('<ul><li>a<ul><li>b</li></ul></li><li><p>c</p></li></ul><ol start="3" type="a"><li>d</li></ol><ol start="1"><li>e</li></ol>'))->toBe([
        ['type' => 'bulletList', 'content' => [
            ['type' => 'listItem', 'content' => [kbh_p(kbh_t('a')), ['type' => 'bulletList', 'content' => [
                ['type' => 'listItem', 'content' => [kbh_p(kbh_t('b'))]],
            ]]]],
            ['type' => 'listItem', 'content' => [kbh_p(kbh_t('c'))]],
        ]],
        ['type' => 'orderedList', 'attrs' => ['start' => 3, 'type' => 'a'], 'content' => [['type' => 'listItem', 'content' => [kbh_p(kbh_t('d'))]]]],
        ['type' => 'orderedList', 'content' => [['type' => 'listItem', 'content' => [kbh_p(kbh_t('e'))]]]],
    ]);
});

it('repairs list items that start with a block and lists without items', function () {
    expect(kbh('<ul><li><ul><li>x</li></ul></li></ul><ul><ul><li>y</li></ul></ul><ol>  </ol><ol start="-4" type="disc"><li>z</li></ol>'))->toBe([
        ['type' => 'bulletList', 'content' => [['type' => 'listItem', 'content' => [['type' => 'paragraph'], ['type' => 'bulletList', 'content' => [
            ['type' => 'listItem', 'content' => [kbh_p(kbh_t('x'))]]]]]]]],
        ['type' => 'bulletList', 'content' => [['type' => 'listItem', 'content' => [['type' => 'paragraph'], ['type' => 'bulletList', 'content' => [
            ['type' => 'listItem', 'content' => [kbh_p(kbh_t('y'))]]]]]]]],
        ['type' => 'orderedList', 'content' => [['type' => 'listItem', 'content' => [kbh_p(kbh_t('z'))]]]],
    ]);
});

it('converts blockquotes with loose text', function () {
    expect(kbh('<blockquote>a <b>b</b><p>c</p></blockquote><blockquote> </blockquote>'))->toBe([
        ['type' => 'blockquote', 'content' => [kbh_p(kbh_t('a '), kbh_t('b', 'bold')), kbh_p(kbh_t('c'))]],
    ]);
});

it('converts CKEditor tables in figures, with spans, header cells, empty cells and nesting', function () {
    $c = new LegacyHtmlToTiptap;
    $out = kbh('<figure class="table"><table><caption>Cap</caption><colgroup><col></colgroup><thead><tr><th colspan="2">H</th></tr></thead>'
        .'<tbody><tr><td rowspan="2" style="color:red">a</td><td colspan="1000"></td></tr><tr><td><table><tr><td>n</td></tr></table></td></tr></tbody></table></figure>', $c);

    expect($out)->toBe([
        kbh_p(kbh_t('Cap')),
        ['type' => 'table', 'content' => [
            ['type' => 'tableRow', 'content' => [['type' => 'tableHeader', 'attrs' => ['colspan' => 2], 'content' => [kbh_p(kbh_t('H'))]]]],
            ['type' => 'tableRow', 'content' => [
                ['type' => 'tableCell', 'attrs' => ['rowspan' => 2], 'content' => [kbh_p(kbh_t('a'))]],
                ['type' => 'tableCell', 'content' => [['type' => 'paragraph']]],
            ]],
            ['type' => 'tableRow', 'content' => [['type' => 'tableCell', 'content' => [['type' => 'table', 'content' => [
                ['type' => 'tableRow', 'content' => [['type' => 'tableCell', 'content' => [kbh_p(kbh_t('n'))]]]],
            ]]]]]],
        ]],
    ])->and($c->dropped)->toBe(['caption' => 1]);
});

it('drops a table without rows', function () {
    expect(kbh('<table><tbody></tbody></table><p>x</p>'))->toBe([kbh_p(kbh_t('x'))]);
});

it('keeps allowed links and target=_blank with rel', function () {
    $out = kbh('<p><a href="https://example.org/a?b=1" target="_BLANK" rel="opener" onclick="x()">e</a> <a href="mailto:a@b.de">m</a> '
        .'<a href="/lexicon/3">r</a> <a href="http://x.de">h</a></p>');

    expect(array_map(fn ($n) => $n['marks'][0]['attrs'] ?? null, array_values(array_filter($out[0]['content'], fn ($n) => isset($n['marks'])))))->toBe([
        ['href' => 'https://example.org/a?b=1', 'target' => '_blank', 'rel' => 'noopener noreferrer'],
        ['href' => 'mailto:a@b.de'], ['href' => '/lexicon/3'], ['href' => 'http://x.de'],
    ]);
});

it('drops unsafe links but keeps their text', function () {
    foreach ([
        'javascript:alert(1)', 'JaVaScRiPt:alert(1)', 'java&#9;script:alert(1)', '&#106;avascript:alert(1)', ' javascript:x',
        'java&#x0A;script:x', 'data:text/html;base64,PHNjcmlwdD4=', 'vbscript:x', 'file:///etc/passwd', '//evil.example', '/\\evil.example',
        '#anchor', 'relative/page', 'tel:123', 'ftp://x',
    ] as $href) {
        $c = new LegacyHtmlToTiptap;

        expect(kbh('<p><a href="'.$href.'">t</a></p>', $c))->toBe([kbh_p(kbh_t('t'))])
            ->and($c->droppedLinks)->toBe(1);
    }
});

it('treats anchors without href as text and the innermost link wins', function () {
    $c = new LegacyHtmlToTiptap;

    expect(kbh('<p><a name="top">a</a><a href="/x"><a href="/y">b</a></a></p>', $c)[0]['content'])->toBe([
        kbh_t('a'), ['type' => 'text', 'text' => 'b', 'marks' => [['type' => 'link', 'attrs' => ['href' => '/y']]]],
    ])->and($c->droppedLinks)->toBe(0);
});

it('encodes blanks inside a link target and strips control characters', function () {
    expect(kbh("<p><a href=\" /a b\tc \">t</a></p>")[0]['content'][0]['marks'][0]['attrs'])->toBe(['href' => '/a%20bc']);
});

it('passes hrefs and image sources through the rewrite hooks', function () {
    $c = new LegacyHtmlToTiptap(
        fn (string $h) => $h === '/lexicon/view?id=1' ? '/lexicon/77' : ($h === '/gone' ? null : $h),
        fn (string $s) => $s === 'legacy.png' ? KBH_IMG : null,
    );
    $out = kbh('<p><a href="/lexicon/view?id=1">a</a><a href="/gone">b</a></p><img src="legacy.png" alt="A"><img src="other.png">', $c);

    expect($out)->toBe([
        kbh_p(['type' => 'text', 'text' => 'a', 'marks' => [['type' => 'link', 'attrs' => ['href' => '/lexicon/77']]]], kbh_t('b')),
        ['type' => 'image', 'attrs' => ['src' => KBH_IMG, 'alt' => 'A']],
    ])->and($c->droppedLinks)->toBe(0)->and($c->droppedImages)->toBe(0);
});

it('keeps only our own image uploads and splits paragraphs around them', function () {
    $c = new LegacyHtmlToTiptap;
    $out = kbh('<p>a<img src="'.KBH_IMG.'" alt="'.str_repeat('x', 300).'" onerror="alert(1)" style="x">b</p>'
        .'<img src="data:image/png;base64,AAAA"><img src="https://evil.example/x.png"><img src="/storage/kb-images/x.svg"><img><img src="javascript:x">', $c);

    expect($out)->toBe([
        kbh_p(kbh_t('a')), ['type' => 'image', 'attrs' => ['src' => KBH_IMG, 'alt' => str_repeat('x', 255)]], kbh_p(kbh_t('b')),
    ])->and($c->droppedImages)->toBe(5);
});

it('unwraps figures around images', function () {
    expect(kbh('<figure class="image"><img src="'.KBH_IMG.'"><figcaption>Bild</figcaption></figure>'))->toBe([
        ['type' => 'image', 'attrs' => ['src' => KBH_IMG]], kbh_p(kbh_t('Bild')),
    ]);
});

it('drops dangerous elements with their content and counts them', function () {
    $c = new LegacyHtmlToTiptap;
    $out = kbh('<p>a</p><script>alert(1)</script><style>p{}</style><iframe src="https://x">i</iframe><svg><text>s</text></svg>'
        .'<object>o</object><embed src="x"><noscript>n</noscript><template>t</template><textarea>ta</textarea><math>m</math><p>b</p>', $c);

    expect($out)->toBe([kbh_p(kbh_t('a')), kbh_p(kbh_t('b'))])
        ->and($c->dropped)->toBe(['script' => 1, 'style' => 1, 'iframe' => 1, 'svg' => 1, 'object' => 1, 'embed' => 1,
            'noscript' => 1, 'template' => 1, 'textarea' => 1, 'math' => 1]);
});

it('unwraps unknown elements, keeps their text and counts them', function () {
    $c = new LegacyHtmlToTiptap;
    $out = kbh('<div class="x">a <span style="color:red" onclick="x()">b</span> <font>c</font></div><section><p>d</p></section><pre>e   f</pre><mark>g</mark>', $c);

    expect($out)->toBe([kbh_p(kbh_t('a b c')), kbh_p(kbh_t('d')), kbh_p(kbh_t('e f')), kbh_p(kbh_t('g'))])
        ->and($c->dropped)->toBe(['div' => 1, 'span' => 1, 'font' => 1, 'section' => 1, 'pre' => 1, 'mark' => 1]);
});

it('treats script text written as text as plain text', function () {
    expect(kbh('<p>&lt;script&gt;alert(1)&lt;/script&gt; &amp; &quot;x&quot; &auml; &#8364;</p>'))
        ->toBe([kbh_p(kbh_t('<script>alert(1)</script> & "x" ä €'))]);
});

it('keeps UTF-8 text and ignores a meta charset in the content', function () {
    expect(kbh('<meta charset="iso-8859-1"><p>Größe – 🚑 日本</p>'))->toBe([kbh_p(kbh_t('Größe – 🚑 日本'))]);
});

it('collapses insignificant whitespace between and inside blocks', function () {
    expect(kbh("\n  <p>\n  a\n  <b> b </b>  c  </p>\n\n  <p>d <br>  e  <br> </p>  \n"))->toBe([
        kbh_p(kbh_t('a '), kbh_t('b ', 'bold'), kbh_t('c')),
        kbh_p(kbh_t('d'), ['type' => 'hardBreak'], kbh_t('e')),
    ]);
});

it('wraps loose top-level text in paragraphs', function () {
    expect(kbh('a<b>b</b><p>c</p>d'))->toBe([kbh_p(kbh_t('a'), kbh_t('b', 'bold')), kbh_p(kbh_t('c')), kbh_p(kbh_t('d'))]);
});

it('survives broken HTML', function (string $html, array $texts) {
    $out = kbh($html);
    $collect = function (array $nodes) use (&$collect): array {
        $t = [];
        foreach ($nodes as $n) {
            if (isset($n['text'])) {
                $t[] = $n['text'];
            }
            array_push($t, ...$collect($n['content'] ?? []));
        }

        return $t;
    };

    expect($collect($out))->toBe($texts);
})->with([
    'unclosed' => ['<p>a<p>b<ul><li>c<li>d', ['a', 'b', 'c', 'd']],
    'misnested' => ['<b><i>a</b>b</i>', ['a', 'b']],
    'stray closing tags' => ['</p></div>a</li>', ['a']],
    'stray cells' => ['<td>a</td><tr><td>b</td></tr>', ['a', 'b']],
    'text after html' => ['<p>a</p></body></html><p>b</p>', ['a', 'b']],
    'comment and cdata' => ['<!-- x --><p>a<![CDATA[y]]></p>', ['a']],
    'invalid utf8' => ["<p>a\xC3\x28b\0c</p>", ['a?(bc']],
]);

it('returns an empty document for empty input', function () {
    foreach ([null, '', '   ', "\n\t", '<p></p>', '<br>', '<script>x</script>', '<!-- c -->'] as $html) {
        expect((new LegacyHtmlToTiptap)->convert($html))->toBe(['type' => 'doc', 'content' => []]);
    }
});

it('handles huge input and refuses a result over the size limit', function () {
    $para = '<p>'.str_repeat('Wort ', 200).'</p>';
    $doc = (new LegacyHtmlToTiptap)->convert(str_repeat($para, 1000));

    expect(count($doc['content']))->toBe(1000)
        ->and(fn () => (new LegacyHtmlToTiptap)->convert(str_repeat($para, 3000)))->toThrow(InvalidArgumentException::class);
});

it('is idempotent through normalize and resets its counters per document', function () {
    $c = new LegacyHtmlToTiptap;
    $c->convert('<script>x</script><a href="javascript:x">a</a><img src="x">');
    expect([$c->dropped, $c->droppedLinks, $c->droppedImages])->toBe([['script' => 1], 1, 1]);

    $doc = $c->convert('<p>ok</p>');
    expect([$c->dropped, $c->droppedLinks, $c->droppedImages])->toBe([[], 0, 0])
        ->and(KbContentValidator::normalize(KbContentValidator::normalize($doc)))->toBe($doc);
});

function kbh_nested(int $levels, string $leaf = 'x'): string
{
    return $levels === 0 ? $leaf : '<ul><li>l'.$levels.kbh_nested($levels - 1, $leaf).'</li></ul>';
}

it('counts what normalize() cuts beyond the depth limit', function (string $html) {
    $c = new LegacyHtmlToTiptap;
    $doc = $c->convert($html);

    expect($c->truncatedDepth)->toBeGreaterThan(0)->and($c->truncatedNodes)->toBe(0)
        ->and(KbContentValidator::validate($doc))->toBe($doc);
})->with([
    '5-level list' => [kbh_nested(5)],
    '4-level list in a table cell' => ['<table><tr><td>'.kbh_nested(4).'</td></tr></table>'],
]);

it('keeps 4 list levels completely', function () {
    kbh(kbh_nested(4));
});

it('counts what normalize() cuts beyond the node limit', function (string $html) {
    $c = new LegacyHtmlToTiptap;
    $doc = $c->convert($html);

    expect($c->truncatedNodes)->toBeGreaterThan(0)->and($c->truncatedDepth)->toBe(0)
        ->and(KbContentValidator::validate($doc))->toBe($doc);
})->with([
    '1500 x 8 table' => ['<table>'.str_repeat('<tr>'.str_repeat('<td>z</td>', 8).'</tr>', 1500).'</table>'],
    '10 000 paragraphs' => [str_repeat('<p>p</p>', 10000)],
]);

it('trims link targets without breaking multibyte characters', function (string $href) {
    expect(kbh('<p><a href=" '.$href."\u{A0} \">t</a></p>")[0]['content'][0]['marks'][0]['attrs']['href'])->toBe($href);
})->with(['/wiki/à', '/wiki/Š', '/wiki/Р', 'https://example.org/é']);

it('refuses raw HTML over 8 MB before parsing', function () {
    expect(fn () => (new LegacyHtmlToTiptap)->convert(str_repeat('a', LegacyHtmlToTiptap::MAX_INPUT_BYTES + 1)))
        ->toThrow(InvalidArgumentException::class, '8 MB');
});
