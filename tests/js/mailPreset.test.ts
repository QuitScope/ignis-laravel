import { describe, expect, it } from 'vitest';
import type { JSONContent } from '@tiptap/core';
import { createMailEditor, isBadgeSrc, isMailHref, serializeMail } from '@/editor/mailPreset';
import { serializeKb } from '@/editor/kbPreset';

(globalThis as Record<string, unknown>).ClipboardEvent ??= class extends Event {};

const BADGE = '/assets/img/dienstgrade/bf/1.png';
const NODES = ['doc', 'paragraph', 'text', 'hardBreak', 'blockquote', 'bulletList', 'orderedList', 'listItem', 'image'];
const MARKS = ['bold', 'italic', 'strike', 'link'];

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

describe('mail editor preset = MailContentValidator allowlist', () => {
    it('keeps every allowed node and mark unchanged', () => {
        const doc: JSONContent = { type: 'doc', content: [
            { type: 'paragraph', content: [
                { type: 'image', attrs: { src: BADGE, alt: '' } },
                { type: 'text', text: 'a', marks: [{ type: 'bold' }, { type: 'italic' }, { type: 'strike' }] },
                { type: 'hardBreak' },
                { type: 'text', text: 'b', marks: [{ type: 'link', attrs: { href: 'mailto:a@ignis.ef' } }] },
            ] },
            { type: 'blockquote', content: [{ type: 'paragraph', content: [{ type: 'text', text: 'q' }] }] },
            { type: 'bulletList', content: [{ type: 'listItem', content: [{ type: 'paragraph', content: [{ type: 'text', text: 'x' }] }] }] },
            { type: 'orderedList', attrs: { start: 3 }, content: [{ type: 'listItem', content: [{ type: 'paragraph', content: [{ type: 'text', text: 'y' }] }] }] },
        ] };
        const editor = createMailEditor({ content: doc });
        expect(serializeKb(editor.getJSON())).toEqual(doc);
        editor.destroy();
    });

    it('drops raw HTML, foreign images and bad links when HTML is pasted or set', () => {
        const html = '<p onclick="alert(1)" style="color:red">a<script>alert(2)</script><u>u</u><code>c</code><mark>d</mark>'
            + '<a href="javascript:alert(3)">f</a><a href="/relativ">r</a><a href="data:text/html,x">d</a><a href="https://ok.example" class="x" target="_top" rel="opener">g</a></p>'
            + '<h1>Titel</h1><hr><iframe src="https://evil.example"></iframe><svg onload="alert(4)"></svg><pre><code>code</code></pre>'
            + `<p><img src="https://evil.example/a.png"><img src="data:image/png;base64,AAAA"><img src="${BADGE}" onerror="alert(5)" alt="x"></p>`
            + '<ol start="-5" type="A"><li>x</li></ol><table><tr><td>z</td></tr></table>';
        const editor = createMailEditor({ content: null });
        editor.view.pasteHTML(html);
        const pasted = serializeKb(editor.getJSON());
        editor.commands.setContent(html);
        const set = serializeKb(editor.getJSON());

        for (const json of [pasted, set]) {
            const inv = inventory(json);
            expect([...inv.nodes].filter((n) => !NODES.includes(n))).toEqual([]);
            expect([...inv.marks].filter((m) => !MARKS.includes(m))).toEqual([]);
            expect(inv.hrefs).toEqual(['https://ok.example']);
            expect(inv.srcs).toEqual([BADGE]);
            const text = JSON.stringify(json);
            for (const bad of ['onclick', 'onerror', 'onload', 'style', 'script', 'iframe', 'javascript:', 'data:', 'class', 'target', 'rel', '"type":"A"']) {
                expect(text).not.toContain(bad);
            }
            expect(json.content?.find((n) => n.type === 'orderedList')?.attrs).toEqual({ start: 0 });
        }
        editor.destroy();
    });

    it('checks links and badge paths like the server', () => {
        expect(['https://a.example/x', 'http://a', 'mailto:a@ignis.ef'].every(isMailHref)).toBe(true);
        expect(['javascript:alert(1)', ' https://a', 'https://', '/pfad', '//a.example', 'mailto:', 'https://a\\b', 'data:x'].some(isMailHref)).toBe(false);
        expect([BADGE, `${BADGE}?v=3`].every(isBadgeSrc)).toBe(true);
        expect(['//evil.example/a.png', 'https://a/b.png', '/a/../b.png', `${BADGE}?x=1`, 'data:image/png;base64,AA'].some(isBadgeSrc)).toBe(false);
    });

    it('never puts bold or a link on a badge when the line is formatted', () => {
        const editor = createMailEditor({ content: { type: 'doc', content: [{ type: 'paragraph', content: [
            { type: 'image', attrs: { src: BADGE, alt: '' } }, { type: 'text', text: 'Brandmeister' },
        ] }] } });
        editor.chain().selectAll().toggleBold().setLink({ href: 'https://example.org' }).run();
        expect(editor.getJSON().content?.[0].content?.[0].marks).toBeDefined();
        const para = serializeMail(editor.getJSON()).content?.[0].content ?? [];
        expect(para[0]).toEqual({ type: 'image', attrs: { src: BADGE, alt: '' } });
        expect(para[1].marks?.map((m) => m.type).sort()).toEqual(['bold', 'link']);
        expect(para[1].marks?.find((m) => m.type === 'link')?.attrs).toEqual({ href: 'https://example.org' });
        editor.destroy();
    });

    it('offers placeholders only in the signature editor', () => {
        const doc: JSONContent = { type: 'doc', content: [{ type: 'paragraph', content: [{ type: 'docVariable', attrs: { name: 'absender.name' } }] }] };
        const mail = createMailEditor({ content: doc });
        expect(JSON.stringify(mail.getJSON())).not.toContain('docVariable');
        mail.destroy();
        const signature = createMailEditor({ content: doc, variables: { mode: 'template', list: [{ key: 'absender.name', label: 'Name' }] } });
        expect(serializeKb(signature.getJSON())).toEqual(doc);
        signature.destroy();
    });
});
