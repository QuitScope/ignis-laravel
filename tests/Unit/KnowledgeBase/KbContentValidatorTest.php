<?php

use Modules\KnowledgeBase\Content\KbContentValidator as V;
use Tests\TestCase;

uses(TestCase::class);

const KBV_IMG = '/storage/kb-images/0123456789abcdef0123456789abcdef.png';

function kbv_doc(array ...$blocks): array
{
    return ['type' => 'doc', 'content' => $blocks];
}

function kbv_p(array ...$inline): array
{
    return $inline === [] ? ['type' => 'paragraph'] : ['type' => 'paragraph', 'content' => $inline];
}

function kbv_t(string $text, array ...$marks): array
{
    return $marks === [] ? ['type' => 'text', 'text' => $text] : ['type' => 'text', 'text' => $text, 'marks' => $marks];
}

function kbv_link(string $href, array $more = []): array
{
    return ['type' => 'link', 'attrs' => ['href' => $href] + $more];
}

function kbv_full(): array
{
    $cell = fn (string $type, array $attrs, string $t) => ['type' => $type, 'attrs' => $attrs, 'content' => [kbv_p(kbv_t($t))]];

    return kbv_doc(
        ['type' => 'heading', 'attrs' => ['level' => 1], 'content' => [kbv_t('H1')]],
        ['type' => 'heading', 'attrs' => ['level' => 3], 'content' => [kbv_t('H3')]],
        kbv_p(
            kbv_t('fett', ['type' => 'bold']),
            kbv_t('kursiv', ['type' => 'italic'], ['type' => 'underline'], ['type' => 'strike']),
            ['type' => 'hardBreak'],
            kbv_t('extern', kbv_link('https://example.org/a?b=1&c=2', ['target' => '_blank', 'rel' => 'noopener noreferrer'])),
            kbv_t(' mail', kbv_link('mailto:rd@example.org')),
            kbv_t(' intern', kbv_link('/lexicon/3')),
            kbv_t(' http', kbv_link('HTTP://example.org')),
        ),
        ['type' => 'bulletList', 'content' => [['type' => 'listItem', 'content' => [kbv_p(kbv_t('eins')), ['type' => 'orderedList', 'attrs' => ['start' => 3, 'type' => 'a'], 'content' => [['type' => 'listItem', 'content' => [kbv_p(kbv_t('tief'))]]]]]]]],
        ['type' => 'blockquote', 'content' => [kbv_p(kbv_t('Zitat'))]],
        ['type' => 'table', 'content' => [
            ['type' => 'tableRow', 'content' => [$cell('tableHeader', ['colspan' => 1, 'rowspan' => 1], 'A'), $cell('tableHeader', ['colspan' => 1, 'rowspan' => 1], 'B')]],
            ['type' => 'tableRow', 'content' => [$cell('tableCell', ['colspan' => 2, 'rowspan' => 999], 'x')]],
        ]],
        ['type' => 'horizontalRule'],
        ['type' => 'image', 'attrs' => ['src' => KBV_IMG, 'alt' => 'Schema']],
        kbv_p(),
    );
}

function kbv_rejects(mixed $doc): string
{
    $message = null;
    try {
        V::validate($doc);
    } catch (InvalidArgumentException $e) {
        $message = $e->getMessage();
    }
    expect($message)->toBeString('validate() accepted the document');

    return (string) $message;
}

it('accepts every allowed node, mark and attribute unchanged and is idempotent', function () {
    expect(V::validate(kbv_full()))->toBe(kbv_full())
        ->and(V::validate(V::validate(kbv_full())))->toBe(kbv_full())
        ->and(V::normalize(kbv_full()))->toBe(kbv_full())
        ->and(V::validate(json_encode(kbv_full())))->toBe(kbv_full());
});

it('accepts an empty document and drops null attributes as the editor sends them', function () {
    expect(V::validate(['type' => 'doc']))->toBe(V::empty())
        ->and(V::validate(kbv_doc(['type' => 'orderedList', 'attrs' => ['start' => 1, 'type' => null], 'content' => [['type' => 'listItem', 'content' => [kbv_p()]]]])))
        ->toBe(kbv_doc(['type' => 'orderedList', 'attrs' => ['start' => 1], 'content' => [['type' => 'listItem', 'content' => [kbv_p()]]]]))
        ->and(V::validate(kbv_doc(kbv_p(kbv_t('x', kbv_link('/a', ['target' => null, 'rel' => null, 'class' => null]))))))
        ->toBe(kbv_doc(kbv_p(kbv_t('x', kbv_link('/a')))));
});

it('forces rel="noopener noreferrer" on target=_blank and drops rel without it', function () {
    foreach ([null, 'nofollow', 'noopener'] as $rel) {
        expect(V::validate(kbv_doc(kbv_p(kbv_t('x', kbv_link('https://x.de', ['target' => '_blank', 'rel' => $rel]))))))
            ->toBe(kbv_doc(kbv_p(kbv_t('x', kbv_link('https://x.de', ['target' => '_blank', 'rel' => 'noopener noreferrer'])))));
    }
    expect(V::validate(kbv_doc(kbv_p(kbv_t('x', kbv_link('https://x.de', ['rel' => 'noopener']))))))->toBe(kbv_doc(kbv_p(kbv_t('x', kbv_link('https://x.de')))));
    foreach (['opener', 'noopener ugc', 'external sponsored', ['x']] as $rel) {
        $blank = kbv_doc(kbv_p(kbv_t('x', kbv_link('https://x.de', ['target' => '_blank', 'rel' => $rel]))));
        $plain = kbv_doc(kbv_p(kbv_t('x', kbv_link('https://x.de', ['rel' => $rel]))));
        expect(V::validate($blank))->toBe(kbv_doc(kbv_p(kbv_t('x', kbv_link('https://x.de', ['target' => '_blank', 'rel' => 'noopener noreferrer'])))))
            ->and(V::validate($plain))->toBe(kbv_doc(kbv_p(kbv_t('x', kbv_link('https://x.de')))));
    }
    expect(V::normalize(kbv_doc(kbv_p(kbv_t('x', kbv_link('https://x.de', ['target' => '_blank', 'rel' => 'opener']))))))
        ->toBe(kbv_doc(kbv_p(kbv_t('x', kbv_link('https://x.de', ['target' => '_blank', 'rel' => 'noopener noreferrer'])))));
});

it('rejects dangerous or foreign link targets, and normalize keeps only the text', function () {
    foreach ([
        'javascript' => ['javascript:alert(1)'],
        'javascript upper' => ['JAVASCRIPT:alert(1)'],
        'javascript mixed case' => ['JaVaScRiPt:alert(1)'],
        'javascript entity' => ['&#106;avascript:alert(1)'],
        'javascript hex entity with tab' => ['java&#x09;script:alert(1)'],
        'javascript percent' => ['%6Aavascript:alert(1)'],
        'javascript with newline' => ["java\nscript:alert(1)"],
        'javascript with tab' => ["java\tscript:alert(1)"],
        'control char + space + upper' => ["\x01 JAVASCRIPT:alert(1)"],
        'leading space' => [' javascript:alert(1)'],
        'nbsp' => ["\u{00A0}javascript:alert(1)"],
        'zero width' => ["\u{200B}javascript:alert(1)"],
        'data' => ['data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg=='],
        'data upper' => ['DATA:text/html,x'],
        'vbscript' => ['vbscript:msgbox(1)'],
        'file' => ['file:///etc/passwd'],
        'ftp' => ['ftp://x.de'],
        'tel' => ['tel:110'],
        'protocol relative' => ['//evil.example/x'],
        'backslash protocol relative' => ['/\\evil.example/x'],
        'backslashes' => ['\\\\evil.example'],
        'https without slashes' => ['https:evil.example'],
        'anchor' => ['#oben'],
        'relative without slash' => ['lexicon/3'],
        'named colon entity' => ['javascript&colon;alert(1)'],
        'empty' => [''],
    ] as [$href]) {
        $doc = kbv_doc(kbv_p(kbv_t('Klick', kbv_link($href)), kbv_t(' Rest', ['type' => 'bold'])));

        expect(kbv_rejects($doc))->toContain(trans('knowledgebase.content.link'))
            ->and(V::normalize($doc))->toBe(kbv_doc(kbv_p(kbv_t('Klick'), kbv_t(' Rest', ['type' => 'bold']))));
    }
});

it('normalize strips control characters and whitespace from an otherwise valid href (legacy rule)', function () {
    expect(V::normalize(kbv_doc(kbv_p(kbv_t('x', kbv_link(" https://x.de/\x01a b\n"))))))->toBe(kbv_doc(kbv_p(kbv_t('x', kbv_link('https://x.de/ab')))))
        ->and(V::normalize(kbv_doc(kbv_p(kbv_t('x', kbv_link("\x01 JAVASCRIPT:alert(1)"))))))->toBe(kbv_doc(kbv_p(kbv_t('x'))));
    kbv_rejects(kbv_doc(kbv_p(kbv_t('x', kbv_link(' https://x.de')))));
});

it('rejects a link without href, with a foreign target or an unknown attribute', function (array $attrs) {
    kbv_rejects(kbv_doc(kbv_p(kbv_t('x', ['type' => 'link', 'attrs' => $attrs]))));
})->with([
    'no href' => [['target' => '_blank']],
    'href not a string' => [['href' => ['https://x.de']]],
    'target _top' => [['href' => 'https://x.de', 'target' => '_top']],
    'onclick' => [['href' => 'https://x.de', 'onclick' => 'alert(1)']],
    'class' => [['href' => 'https://x.de', 'class' => 'x']],
    'href too long' => [['href' => 'https://x.de/'.str_repeat('a', 2048)]],
]);

it('accepts images only from the kb upload path', function () {
    expect(V::validate(kbv_doc(['type' => 'image', 'attrs' => ['src' => '/storage/kb-images/ffffffffffffffffffffffffffffffff.gif']])))->toHaveKey('content.0.attrs.src');
});

it('rejects foreign image sources, and normalize drops the image', function () {
    foreach ([
        'foreign server' => ['https://x.de/a.png'],
        'protocol relative' => ['//x.de/storage/kb-images/0123456789abcdef0123456789abcdef.png'],
        'data' => ['data:image/png;base64,AAAA'],
        'javascript' => ['javascript:alert(1)'],
        'no image path' => ['/logout'],
        'svg' => ['/storage/kb-images/0123456789abcdef0123456789abcdef.svg'],
        'other area' => ['/storage/profile-pictures/0123456789abcdef0123456789abcdef.png'],
        'traversal' => ['/storage/kb-images/../profile-pictures/0123456789abcdef0123456789abcdef.png'],
        'legacy short name' => ['/storage/kb-images/abc.png'],
        'upper hex' => ['/storage/kb-images/0123456789ABCDEF0123456789abcdef.png'],
        'trailing newline' => ["/storage/kb-images/0123456789abcdef0123456789abcdef.png\n"],
        'query' => ['/storage/kb-images/0123456789abcdef0123456789abcdef.png?x=1'],
        'with base path' => ['/ignis/storage/kb-images/0123456789abcdef0123456789abcdef.png'],
        'not a string' => [['x']],
        'null' => [null],
    ] as [$src]) {
        $doc = kbv_doc(['type' => 'image', 'attrs' => ['src' => $src, 'alt' => 'a']], kbv_p(kbv_t('t')));

        kbv_rejects($doc);
        expect(V::normalize($doc))->toBe(kbv_doc(kbv_p(kbv_t('t'))));
    }
});

it('rejects image attributes beyond src and alt, and alt over 255 characters', function () {
    kbv_rejects(kbv_doc(['type' => 'image', 'attrs' => ['src' => KBV_IMG, 'onerror' => 'alert(1)']]));
    kbv_rejects(kbv_doc(['type' => 'image', 'attrs' => ['src' => KBV_IMG, 'style' => 'width:1px']]));
    kbv_rejects(kbv_doc(['type' => 'image', 'attrs' => ['src' => KBV_IMG, 'alt' => str_repeat('ä', 256)]]));
    expect(V::validate(kbv_doc(['type' => 'image', 'attrs' => ['src' => KBV_IMG, 'alt' => str_repeat('ä', 255)]]))['content'][0]['attrs']['alt'])->toBe(str_repeat('ä', 255))
        ->and(V::normalize(kbv_doc(['type' => 'image', 'attrs' => ['src' => KBV_IMG, 'alt' => str_repeat('ä', 300), 'onerror' => 'x']]))['content'][0])
        ->toBe(['type' => 'image', 'attrs' => ['src' => KBV_IMG, 'alt' => str_repeat('ä', 255)]]);
});

it('keeps <script> and other markup in text as plain text', function () {
    $doc = kbv_doc(kbv_p(kbv_t('<script>alert(1)</script><img src=x onerror=alert(2)>')));

    expect(V::validate($doc))->toBe($doc);
});

it('rejects unknown nodes, marks, attributes and keys; normalize drops them', function () {
    foreach ([
        'iframe with content' => [kbv_doc(['type' => 'iframe', 'attrs' => ['src' => 'https://evil.example'], 'content' => [kbv_t('weg')]], kbv_p(kbv_t('bleibt'))), kbv_doc(kbv_p(kbv_t('bleibt')))],
        'code block' => [kbv_doc(['type' => 'codeBlock', 'content' => [kbv_t('auch weg')]]), V::empty()],
        'image block of the legacy editor' => [kbv_doc(['type' => 'imageBlock', 'attrs' => ['src' => KBV_IMG]]), V::empty()],
        'highlight mark' => [kbv_doc(kbv_p(kbv_t('bleibt', ['type' => 'highlight'], ['type' => 'bold']))), kbv_doc(kbv_p(kbv_t('bleibt', ['type' => 'bold'])))],
        'code mark' => [kbv_doc(kbv_p(kbv_t('x', ['type' => 'code']))), kbv_doc(kbv_p(kbv_t('x')))],
        'textStyle mark' => [kbv_doc(kbv_p(kbv_t('x', ['type' => 'textStyle', 'attrs' => ['color' => 'red']]))), kbv_doc(kbv_p(kbv_t('x')))],
        'bold with attrs' => [kbv_doc(kbv_p(kbv_t('x', ['type' => 'bold', 'attrs' => ['style' => 'x']]))), kbv_doc(kbv_p(kbv_t('x')))],
        'duplicate mark' => [kbv_doc(kbv_p(kbv_t('x', ['type' => 'bold'], ['type' => 'bold']))), kbv_doc(kbv_p(kbv_t('x', ['type' => 'bold'])))],
        'paragraph onclick and style' => [kbv_doc(['type' => 'paragraph', 'attrs' => ['onclick' => 'alert(3)', 'style' => 'color:red'], 'content' => [kbv_t('Rest')]]), kbv_doc(kbv_p(kbv_t('Rest')))],
        'list onclick (legacy)' => [kbv_doc(['type' => 'bulletList', 'attrs' => ['onclick' => 'alert(4)'], 'content' => [['type' => 'listItem', 'content' => [kbv_p(kbv_t('Schritt'))]]]]), kbv_doc(['type' => 'bulletList', 'content' => [['type' => 'listItem', 'content' => [kbv_p(kbv_t('Schritt'))]]]])],
        'textAlign' => [kbv_doc(['type' => 'paragraph', 'attrs' => ['textAlign' => 'center']]), kbv_doc(kbv_p())],
        'extra key on node' => [kbv_doc(['type' => 'paragraph', 'html' => '<script>x</script>']), kbv_doc(kbv_p())],
        'marks on a paragraph' => [kbv_doc(['type' => 'paragraph', 'marks' => [['type' => 'bold']]]), kbv_doc(kbv_p())],
        'heading level 6' => [kbv_doc(['type' => 'heading', 'attrs' => ['level' => 6], 'content' => [kbv_t('zu tief')]]), kbv_doc(['type' => 'heading', 'attrs' => ['level' => 3], 'content' => [kbv_t('zu tief')]])],
        'heading without level' => [kbv_doc(['type' => 'heading', 'content' => [kbv_t('h')]]), kbv_doc(['type' => 'heading', 'attrs' => ['level' => 1], 'content' => [kbv_t('h')]])],
        'heading level as numeric string' => [kbv_doc(['type' => 'heading', 'attrs' => ['level' => '2']]), kbv_doc(['type' => 'heading', 'attrs' => ['level' => 2]])],
        'heading level as other string' => [kbv_doc(['type' => 'heading', 'attrs' => ['level' => 'h2']]), kbv_doc(['type' => 'heading', 'attrs' => ['level' => 1]])],
        'link target _self' => [kbv_doc(kbv_p(kbv_t('x', kbv_link('/a', ['target' => '_self'])))), kbv_doc(kbv_p(kbv_t('x', kbv_link('/a'))))],
        'ordered list start negative' => [kbv_doc(['type' => 'orderedList', 'attrs' => ['start' => -1]]), kbv_doc(['type' => 'orderedList'])],
        'ordered list type disc' => [kbv_doc(['type' => 'orderedList', 'attrs' => ['type' => 'disc']]), kbv_doc(['type' => 'orderedList'])],
        'numeric alt' => [kbv_doc(['type' => 'image', 'attrs' => ['src' => KBV_IMG, 'alt' => 123]]), kbv_doc(['type' => 'image', 'attrs' => ['src' => KBV_IMG]])],
        'cell span 0 and text' => [kbv_doc(['type' => 'table', 'content' => [['type' => 'tableRow', 'content' => [['type' => 'tableCell', 'attrs' => ['rowspan' => 0, 'colspan' => '2;x'], 'content' => [kbv_p(kbv_t('c'))]]]]]]), kbv_doc(['type' => 'table', 'content' => [['type' => 'tableRow', 'content' => [['type' => 'tableCell', 'content' => [kbv_p(kbv_t('c'))]]]]]])],
        'cell span 1000' => [kbv_doc(['type' => 'table', 'content' => [['type' => 'tableRow', 'content' => [['type' => 'tableHeader', 'attrs' => ['colspan' => 1000], 'content' => [kbv_p()]]]]]]), kbv_doc(['type' => 'table', 'content' => [['type' => 'tableRow', 'content' => [['type' => 'tableHeader', 'content' => [kbv_p()]]]]]])],
        'cell colwidth' => [kbv_doc(['type' => 'table', 'content' => [['type' => 'tableRow', 'content' => [['type' => 'tableCell', 'attrs' => ['colwidth' => [100]], 'content' => [kbv_p()]]]]]]), kbv_doc(['type' => 'table', 'content' => [['type' => 'tableRow', 'content' => [['type' => 'tableCell', 'content' => [kbv_p()]]]]]])],
        'text directly in doc' => [kbv_doc(kbv_t('lose'), kbv_p(kbv_t('ok'))), kbv_doc(kbv_p(kbv_t('ok')))],
        'paragraph in paragraph' => [kbv_doc(kbv_p(kbv_p(kbv_t('x')), kbv_t('y'))), kbv_doc(kbv_p(kbv_t('y')))],
        'list item outside list' => [kbv_doc(['type' => 'listItem', 'content' => [kbv_p()]]), V::empty()],
        'nested doc' => [kbv_doc(kbv_doc(kbv_p())), V::empty()],
        'empty text' => [kbv_doc(kbv_p(['type' => 'text', 'text' => ''])), kbv_doc(kbv_p())],
        'text not a string' => [kbv_doc(kbv_p(['type' => 'text', 'text' => ['<b>']])), kbv_doc(kbv_p())],
        'node without type' => [kbv_doc(['content' => []], kbv_p()), kbv_doc(kbv_p())],
        'node as string' => [['type' => 'doc', 'content' => ['<p>x</p>', kbv_p()]], kbv_doc(kbv_p())],
        'type as array' => [kbv_doc(['type' => ['paragraph']]), V::empty()],
        'content as object' => [kbv_doc(['type' => 'paragraph', 'content' => ['a' => kbv_t('x')]]), kbv_doc(kbv_p())],
        'marks as object' => [kbv_doc(kbv_p(['type' => 'text', 'text' => 'x', 'marks' => ['type' => 'bold']])), kbv_doc(kbv_p(kbv_t('x')))],
        'attrs on doc' => [['type' => 'doc', 'attrs' => ['x' => 1], 'content' => []], V::empty()],
    ] as [$doc, $normalized]) {
        kbv_rejects($doc);
        expect(V::normalize($doc))->toBe($normalized)
            ->and(V::validate(V::normalize($doc)))->toBe($normalized);
    }
});

it('rejects documents that are no doc object', function () {
    foreach ([
        'raw html' => ['<p>rohes HTML</p>'],
        'json string of a paragraph' => [json_encode(kbv_p())],
        'list' => [[kbv_p()]],
        'null' => [null],
        'number' => [42],
        'broken json' => ['{"type":"doc","content":['],
        'type missing' => [['content' => []]],
        'invalid utf-8' => [['type' => 'doc', 'content' => [kbv_p(kbv_t("\xC3\x28"))]]],
    ] as [$json]) {
        expect(kbv_rejects($json))->toBe(trans('knowledgebase.content.invalid'));
        expect(fn () => V::normalize($json))->toThrow(InvalidArgumentException::class);
    }
});

it('limits the depth to 12 levels', function () {
    $nest = function (int $quotes, array $inner) {
        for ($i = 0; $i < $quotes; $i++) {
            $inner = ['type' => 'blockquote', 'content' => [$inner]];
        }

        return kbv_doc($inner);
    };
    expect(V::validate($nest(10, kbv_p())))->toBe($nest(10, kbv_p()));
    expect(kbv_rejects($nest(11, kbv_p())))->toContain(trans('knowledgebase.content.too_deep', ['max' => 12]));
    expect(V::normalize($nest(11, kbv_p())))->toBe($nest(10, ['type' => 'blockquote']));
});

it('refuses very deep JSON before walking it', function () {
    $deep = kbv_p();
    for ($i = 0; $i < 600; $i++) {
        $deep = ['type' => 'blockquote', 'content' => [$deep]];
    }
    kbv_rejects(kbv_doc($deep));
    kbv_rejects(json_encode(kbv_doc($deep), 0, 2000));
    expect(fn () => V::normalize(kbv_doc($deep)))->toThrow(InvalidArgumentException::class);
});

it('limits the number of nodes to 20000', function () {
    $ok = kbv_doc(...array_fill(0, 19999, kbv_p()));
    expect(count(V::validate($ok)['content']))->toBe(19999);

    $tooMany = kbv_doc(...array_fill(0, 20000, kbv_p()));
    expect(kbv_rejects($tooMany))->toContain(trans('knowledgebase.content.too_many', ['max' => 20000]))
        ->and(count(V::normalize($tooMany)['content']))->toBe(19999);
});

it('limits the size to 2 MB, as array and as string', function () {
    $big = kbv_doc(kbv_p(kbv_t(str_repeat('a', 2 * 1024 * 1024))));
    expect(kbv_rejects($big))->toBe(trans('knowledgebase.content.too_large', ['max' => '2 MB']))
        ->and(kbv_rejects(json_encode($big)))->toBe(trans('knowledgebase.content.too_large', ['max' => '2 MB']));
    expect(fn () => V::normalize($big))->toThrow(InvalidArgumentException::class);

    $fits = kbv_doc(kbv_p(kbv_t(str_repeat('a', 2 * 1024 * 1024 - 100))));
    expect(V::validate($fits))->toBe($fits);
});

it('refuses a self-referencing (cyclic) array', function () {
    $node = ['type' => 'blockquote', 'content' => []];
    $node['content'][0] = &$node;
    $doc = kbv_doc();
    $doc['content'][0] = &$node;

    kbv_rejects($doc);
    expect(fn () => V::normalize($doc))->toThrow(InvalidArgumentException::class);
});

it('names user-controlled names only shortened in the reasons', function () {
    $msg = kbv_rejects(kbv_doc(['type' => str_repeat('x', 500)]));
    expect(mb_strlen($msg))->toBeLessThan(200);
});

it('accepts the shared fixture the editor preset round-trips (tests/js/kbPreset.test.ts) unchanged', function () {
    $fixture = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/knowledgebase/allowed-content.json'), true);

    expect(V::validate($fixture))->toBe($fixture);
});

it('drops null-valued mark attributes like node attributes, but refuses real ones', function () {
    expect(V::validate(kbv_doc(kbv_p(kbv_t('x', ['type' => 'bold', 'attrs' => ['x' => null]])))))->toBe(kbv_doc(kbv_p(kbv_t('x', ['type' => 'bold']))));
    kbv_rejects(kbv_doc(kbv_p(kbv_t('x', ['type' => 'bold', 'attrs' => ['x' => 1]]))));
});
