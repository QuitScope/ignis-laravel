import { describe, expect, it } from 'vitest';
import type { JSONContent } from '@tiptap/core';
import { canonical } from '@/editor/createEditor';
import { createKbEditor, isAllowedHref, KB_IMAGE_SRC, serializeKb } from '@/editor/kbPreset';
import fixture from '../Fixtures/knowledgebase/allowed-content.json';

(globalThis as Record<string, unknown>).ClipboardEvent ??= class extends Event {};

const IMG = '/storage/kb-images/0123456789abcdef0123456789abcdef.png';

function inventory(json: JSONContent, out = { nodes: new Set<string>(), marks: new Set<string>(), hrefs: [] as string[], srcs: [] as string[] }) {
    out.nodes.add(json.type ?? '?');
    for (const m of json.marks ?? []) {
        out.marks.add(m.type);
        if (m.type === 'link') out.hrefs.push(String(m.attrs?.href));
    }
    if (json.type === 'image') out.srcs.push(String(json.attrs?.src));
    for (const c of json.content ?? []) inventory(c, out);

    return out;
}

const NODES = ['doc', 'paragraph', 'text', 'heading', 'bulletList', 'orderedList', 'listItem', 'blockquote', 'horizontalRule', 'hardBreak', 'table', 'tableRow', 'tableHeader', 'tableCell', 'image'];
const MARKS = ['bold', 'italic', 'underline', 'strike', 'link'];

describe('kb editor preset = KbContentValidator allowlist', () => {
    it('round-trips the shared fixture (also accepted unchanged by the PHP validator) without loss', () => {
        const editor = createKbEditor({ content: fixture as JSONContent });
        expect(canonical(serializeKb(editor.getJSON()))).toEqual(canonical(fixture as JSONContent));
        editor.destroy();
    });

    it('drops raw HTML: scripts, handlers, styles, iframes, unknown marks and nodes when HTML is pasted or set', () => {
        const html = '<p onclick="alert(1)" style="color:red">a<script>alert(2)</script><span style="color:red">b</span><code>c</code><mark>d</mark>'
            + '<sub>e</sub><a href="javascript:alert(3)">f</a><a href="https://ok.example" class="x" title="t" onclick="x">g</a></p>'
            + '<iframe src="https://evil.example"></iframe><svg onload="alert(4)"><circle/></svg><pre><code>code</code></pre>'
            + `<img src="https://evil.example/a.png"><img src="data:image/png;base64,AAAA"><img src="${IMG}" onerror="alert(5)" style="width:1px">`
            + '<h4>vier</h4><h6>sechs</h6><table><tr><td colspan="2" style="x" data-colwidth="100" align="center">z</td></tr></table>';
        const editor = createKbEditor({ content: null });
        editor.view.pasteHTML(html);
        const pasted = serializeKb(editor.getJSON());
        editor.commands.setContent(html);
        const set = serializeKb(editor.getJSON());

        for (const json of [pasted, set]) {
            const inv = inventory(json);
            expect([...inv.nodes].filter((n) => !NODES.includes(n))).toEqual([]);
            expect([...inv.marks].filter((m) => !MARKS.includes(m))).toEqual([]);
            expect(inv.hrefs).toEqual(['https://ok.example']);
            expect(inv.srcs).toEqual([IMG]);
            const text = JSON.stringify(json);
            for (const bad of ['onclick', 'onerror', 'onload', 'style', 'script', 'iframe', 'javascript:', 'data:', 'colwidth', 'align', 'class', 'title']) {
                expect(text).not.toContain(bad);
            }
            expect(json.content?.some((n) => n.type === 'heading' && (n.attrs?.level as number) > 3)).toBe(false);
        }
        editor.destroy();
    });

    it('cuts pasted attribute values to what the strict server validator accepts', () => {
        const editor = createKbEditor({ content: null });
        editor.view.pasteHTML(
            '<p><a href="/a" target="_top" rel="noopener ugc">a</a><a href="/b" target="_self" rel="external sponsored">b</a>'
            + '<a href="/c" target="_blank" rel="opener">c</a></p>'
            + `<img src="${IMG}" alt="123"><img src="${IMG}" alt="${'x'.repeat(300)}"><img src="${window.location.origin}${IMG}" alt="abs">`
            + '<img src="https://evil.example/storage/kb-images/0123456789abcdef0123456789abcdef.png">'
            + `<img src="${window.location.origin}${IMG}?x=1">`
            + '<ol start="-5" type="disc"><li>x</li></ol><ol start="123456" type="A"><li>y</li></ol><ol start="abc"><li>z</li></ol>',
        );
        const json = serializeKb(editor.getJSON());
        const links = (json.content?.[0].content ?? []).map((t) => t.marks?.[0].attrs);
        expect(links).toEqual([{ href: '/a' }, { href: '/b' }, { href: '/c', target: '_blank' }]);
        const images = json.content?.filter((n) => n.type === 'image').map((n) => n.attrs);
        expect(images).toEqual([{ src: IMG, alt: '123' }, { src: IMG, alt: 'x'.repeat(255) }, { src: IMG, alt: 'abs' }]);
        const lists = json.content?.filter((n) => n.type === 'orderedList').map((n) => n.attrs);
        expect(lists).toEqual([{ start: 0 }, { start: 99999, type: 'A' }, { start: 1 }]);
        editor.destroy();
    });

    it('loads only allowed content from JSON: unknown nodes and marks do not survive in the schema', () => {
        const editor = createKbEditor({ content: { type: 'doc', content: [
            { type: 'paragraph', content: [{ type: 'text', text: 'x', marks: [{ type: 'bold' }] }] },
        ] } });
        expect(editor.schema.marks.code).toBeUndefined();
        expect(editor.schema.marks.highlight).toBeUndefined();
        expect(editor.schema.nodes.codeBlock).toBeUndefined();
        expect(Object.keys(editor.schema.nodes).sort()).toEqual([...NODES].sort());
        expect(Object.keys(editor.schema.marks).sort()).toEqual([...MARKS].sort());
        expect(Object.keys(editor.schema.marks.link.spec.attrs ?? {}).sort()).toEqual(['href', 'rel', 'target']);
        expect(Object.keys(editor.schema.nodes.image.spec.attrs ?? {}).sort()).toEqual(['alt', 'src']);
        expect(Object.keys(editor.schema.nodes.tableCell.spec.attrs ?? {}).sort()).toEqual(['colspan', 'rowspan']);
        expect(editor.schema.nodes.heading.spec.parseDOM?.map((r) => r.tag)).toEqual(['h1', 'h2', 'h3']);
        editor.destroy();
    });

    it('refuses to set links the server would refuse', () => {
        const editor = createKbEditor({ content: { type: 'doc', content: [{ type: 'paragraph', content: [{ type: 'text', text: 'Klick' }] }] } });
        for (const href of ['javascript:alert(1)', 'data:text/html,x', '//evil.example', 'vbscript:x', ' https://x.de', 'ftp://x.de']) {
            editor.commands.setTextSelection({ from: 1, to: 6 });
            editor.commands.setLink({ href });
            expect(inventory(editor.getJSON()).hrefs).toEqual([]);
        }
        editor.commands.setTextSelection({ from: 1, to: 6 });
        editor.commands.setLink({ href: '/lexicon/3' });
        expect(inventory(editor.getJSON()).hrefs).toEqual(['/lexicon/3']);
        editor.destroy();
    });
});

describe('isAllowedHref (mirror of KbContentValidator::validHref)', () => {
    it.each(['https://example.org/a?b=1&c=2', 'http://x.de', 'HTTP://X.DE', 'mailto:rd@example.org', '/lexicon/3', '/'])('allows %s', (href) => {
        expect(isAllowedHref(href)).toBe(true);
    });

    it.each([
        'javascript:alert(1)', 'JaVaScRiPt:alert(1)', '&#106;avascript:alert(1)', 'java\nscript:alert(1)', 'java\tscript:x', '\u0001 JAVASCRIPT:x',
        ' https://x.de', 'https://x.de/a b', '\u{a0}https://x.de', '\u{200b}https://x.de', 'data:text/html,x', 'vbscript:x', 'file:///etc/passwd',
        'ftp://x.de', 'tel:110', '//evil.example', `https://x.de/${String.fromCodePoint(0xe4).repeat(1020)}`, '/\\evil.example', 'https:evil.example', '#oben', 'lexicon/3', '', `https://x.de/${'a'.repeat(2048)}`,
    ])('refuses %j', (href) => {
        expect(isAllowedHref(href)).toBe(false);
    });

    it('accepts only kb upload image sources', () => {
        expect(KB_IMAGE_SRC.test(IMG)).toBe(true);
        for (const src of ['https://x.de/a.png', '/storage/kb-images/abc.png', `${IMG}?x`, IMG.replace('.png', '.svg'), `//x${IMG}`]) {
            expect(KB_IMAGE_SRC.test(src)).toBe(false);
        }
    });
});

describe('serializeKb', () => {
    it('drops null attributes of nodes and marks', () => {
        expect(serializeKb({ type: 'doc', content: [{ type: 'paragraph', attrs: { x: null }, content: [{ type: 'text', text: 't', marks: [{ type: 'link', attrs: { href: '/a', target: null, rel: null } }, { type: 'bold' }] }] }] }))
            .toEqual({ type: 'doc', content: [{ type: 'paragraph', content: [{ type: 'text', text: 't', marks: [{ type: 'link', attrs: { href: '/a' } }, { type: 'bold' }] }] }] });
    });
});
