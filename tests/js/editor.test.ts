import { describe, expect, it } from 'vitest';
import type { JSONContent } from '@tiptap/core';
import { collectOpenItems, createEditor, serialize } from '@/editor/createEditor';
import { sanitizeAttrs } from '@/editor/extensions/DocumentLimits';
import { KEEPALIVE_LIMIT, sendFinalSave } from '@/lib/draftSave';
import templates from '../Fixtures/documents/legacy-system-templates.json';

const variables = [{ key: 'mitarbeiter.name', label: 'Name' }];
const fixtures = Object.entries(templates as Record<string, { sections: JSONContent[] }>);
const section = (content: JSONContent[], mode = 'free', sectionId = 's1'): JSONContent => ({ type: 'docSection', attrs: { mode, sectionId }, content });
const doc = (...sections: JSONContent[]): JSONContent => ({ type: 'doc', content: sections });
const para = (...content: JSONContent[]): JSONContent => (content.length ? { type: 'paragraph', content } : { type: 'paragraph' });

describe('editor schema = DocumentValidator allowlist', () => {
    it.each(fixtures)('round-trips the legacy system template %s unchanged', (_name, t) => {
        for (const mode of ['template', 'document'] as const) {
            const content = doc(...t.sections);
            const { editor, conforms, difference } = createEditor({ mode, content, variables });
            expect(difference).toBeNull();
            expect(conforms).toBe(true);
            expect(serialize(editor.getJSON())).toEqual(content);
            editor.destroy();
        }
    });

    it('round-trips every other allowed node, mark and attribute', () => {
        const content = doc(section([
            { type: 'heading', attrs: { level: 3, textAlign: 'right' }, content: [{ type: 'text', text: 'H', marks: [{ type: 'bold' }, { type: 'italic' }, { type: 'strike' }, { type: 'underline' }] }] },
            { type: 'bulletList', content: [{ type: 'listItem', content: [para({ type: 'text', text: 'a' }, { type: 'hardBreak' }, { type: 'text', text: 'b' })] }] },
            { type: 'orderedList', attrs: { start: 3, type: 'a' }, content: [{ type: 'listItem', content: [para({ type: 'text', text: 'c' })] }] },
            { type: 'blockquote', content: [para({ type: 'text', text: 'q' })] },
            { type: 'table', content: [{ type: 'tableRow', content: [
                { type: 'tableHeader', attrs: { colspan: 2, rowspan: 1, colwidth: [100, 50] }, content: [para()] },
                { type: 'tableCell', attrs: { colspan: 1, rowspan: 1 }, content: [para()] },
            ] }] },
            { type: 'horizontalRule' },
            { type: 'pageBreak' },
            para({ type: 'docField', attrs: { fieldId: 'f', label: 'L', required: false, maxLength: 20, options: [{ label: 'A', male: 'Am', female: 'Af' }], value: 'Af' } }),
        ], 'locked'), { type: 'docSection', attrs: { mode: 'free', sectionId: 's2', templateSectionId: 's2', repeatable: true, title: '', hint: 'Hinweis' }, content: [para()] });

        const { editor, conforms } = createEditor({ mode: 'template', content, variables });
        expect(conforms).toBe(true);
        expect(serialize(editor.getJSON())).toEqual(content);
    });

    it.each([
        ['an unknown node', doc(section([{ type: 'codeBlock', content: [{ type: 'text', text: 'x' }] }]))],
        ['an unknown mark', doc(section([para({ type: 'text', text: 'x', marks: [{ type: 'link', attrs: { href: 'javascript:alert(1)' } }] })]))],
        ['an unknown attribute', doc(section([{ type: 'paragraph', attrs: { style: 'color:red' } }]))],
        ['a table cell alignment', doc(section([{ type: 'table', content: [{ type: 'tableRow', content: [{ type: 'tableCell', attrs: { align: 'center' }, content: [para()] }] }] }]))],
        ['an image', doc(section([para({ type: 'image', attrs: { src: '/etc/passwd' } })]))],
        ['content outside sections', doc(para({ type: 'text', text: 'x' }))],
    ])('refuses to edit a document with %s instead of dropping it', (_what, content) => {
        const { editor, conforms, difference } = createEditor({ mode: 'document', content, variables });
        expect(conforms).toBe(false);
        expect(difference).not.toBeNull();
        expect(editor.isEditable).toBe(false);
    });
});

describe('section lock guard (document mode)', () => {
    const content = doc(
        section([para({ type: 'text', text: 'Gesperrt ' }, { type: 'docField', attrs: { fieldId: 'f', label: 'F', required: true, value: '' } })], 'locked', 'L'),
        section([para({ type: 'text', text: 'Frei' })], 'free', 'F'),
    );

    it('blocks text changes in locked sections but lets fields and free sections change', () => {
        const blocked: string[] = [];
        const { editor } = createEditor({ mode: 'document', content, variables, onBlocked: (r) => blocked.push(r) });

        editor.commands.insertContentAt(3, 'X');
        expect(blocked).toEqual(['locked-content']);

        const fieldPos = 2 + 'Gesperrt '.length;
        expect(editor.state.doc.nodeAt(fieldPos)?.type.name).toBe('docField');
        editor.view.dispatch(editor.state.tr.setNodeAttribute(fieldPos, 'value', 'Wert'));
        editor.commands.insertContentAt(editor.state.doc.content.size - 2, '!');
        expect(blocked).toEqual(['locked-content']);

        const json = serialize(editor.getJSON());
        expect(json.content![0].content![0].content![1].attrs!.value).toBe('Wert');
        expect(json.content![1].content![0].content![0].text).toBe('Frei!');

        editor.commands.deleteRange({ from: 0, to: editor.state.doc.child(0).nodeSize });
        expect(blocked).toEqual(['locked-content', 'section-structure']);
    });

    it('adds and removes instances of a repeatable section only', () => {
        const repeatable = doc({ type: 'docSection', attrs: { mode: 'free', sectionId: 'R', templateSectionId: 'R', repeatable: true }, content: [para({ type: 'text', text: 'x' })] });
        const { editor } = createEditor({ mode: 'document', content: repeatable, variables });

        expect(editor.commands.addSectionInstance(0)).toBe(true);
        expect(editor.state.doc.childCount).toBe(2);
        expect(editor.state.doc.child(1).attrs.templateSectionId).toBe('R');
        expect(editor.commands.removeSectionInstance(0)).toBe(true);
        expect(editor.commands.removeSectionInstance(0)).toBe(false);
        expect(editor.state.doc.childCount).toBe(1);
    });
});

describe('attribute bounds (DocumentValidator limits)', () => {
    it.each([
        ['orderedList', { start: 123456, type: null }, { start: 99999, type: null }],
        ['orderedList', { start: Number.NaN, type: 'x' }, { start: 1, type: null }],
        ['tableCell', { colspan: 99, rowspan: 0, colwidth: [-1] }, { colspan: 20, rowspan: 1, colwidth: null }],
        ['tableHeader', { colspan: 2, rowspan: 51, colwidth: null }, { colspan: 2, rowspan: 50, colwidth: null }],
        ['docField', { fieldId: 'f', label: null, required: 'yes', maxLength: 0, options: null, value: null }, { fieldId: 'f', label: null, required: null, maxLength: null, options: null, value: null }],
        ['docField', { fieldId: 'f', label: null, required: true, maxLength: 3, options: [{ label: '' }, { label: 'A', x: 1 }], value: 'abcdef' }, { fieldId: 'f', label: null, required: true, maxLength: 3, options: [{ label: 'A' }], value: 'abc' }],
    ])('corrects %s %j', (type, attrs, expected) => {
        expect(sanitizeAttrs(type, attrs)).toEqual(expected);
    });

    it('leaves valid attributes alone and drops variables with an invalid name', () => {
        expect(sanitizeAttrs('orderedList', { start: 0, type: 'a' })).toBeNull();
        expect(sanitizeAttrs('docField', { fieldId: 'f', label: 'L', required: false, maxLength: 5, options: [{ label: 'A', male: 'B' }], value: 'x' })).toBeNull();
        expect(sanitizeAttrs('docVariable', { name: 'mitarbeiter.name' })).toBeNull();
        expect(sanitizeAttrs('docVariable', { name: 'Bad Name' })).toBe(false);
        expect(sanitizeAttrs('docVariable', { name: 'a'.repeat(65) })).toBe(false);
        expect(sanitizeAttrs('docField', { fieldId: 'not valid!', label: null, required: null, maxLength: null, options: null, value: null })).not.toBeNull();
    });

    it('bounds pasted content in the editor', () => {
        const { editor } = createEditor({ mode: 'template', content: doc(section([para({ type: 'text', text: 'x' })])), variables });
        editor.commands.insertContentAt(3, '<ol start="123456"><li><p>a</p></li></ol><table><tr><td colspan="99"><p>b</p></td></tr></table><p>c<span data-efe-variable="Bad Name">{{Bad Name}}</span><span data-efe-variable="ok.name">{{ok.name}}</span></p>');
        const json = JSON.stringify(serialize(editor.getJSON()));
        expect(json).toContain('"start":99999');
        expect(json).toContain('"colspan":20');
        expect(json).not.toContain('Bad Name');
        expect(json).toContain('"name":"ok.name"');
    });

    it('refuses to grow a table past 30 cells per row', () => {
        const cells = Array.from({ length: 30 }, () => ({ type: 'tableCell', content: [para()] }));
        const content = doc(section([{ type: 'table', content: [{ type: 'tableRow', content: cells }] }]));
        const blocked: string[] = [];
        const { editor } = createEditor({ mode: 'template', content, variables, onBlocked: (r) => blocked.push(r) });
        editor.commands.setTextSelection(5);
        editor.commands.addColumnAfter();
        expect(blocked).toEqual(['table-size']);
        expect(editor.state.doc.child(0).child(0).child(0).childCount).toBe(30);
    });
});

describe('variables and accessibility', () => {
    it('does not take prototype keys for resolved values', () => {
        const content = doc(section([para({ type: 'docVariable', attrs: { name: 'constructor' } })]));
        const { editor } = createEditor({ mode: 'document', content, variables, values: {} });
        expect(collectOpenItems(editor, variables, {})).toMatchObject([{ kind: 'variable', state: 'unknown', name: 'constructor' }]);
        expect(editor.view.dom.querySelector('.efe-variable--unknown')).not.toBeNull();
    });

    it('names the editing surface', () => {
        const { editor } = createEditor({ mode: 'document', content: doc(section([para()])), variables });
        expect(editor.view.dom.getAttribute('aria-label')).toBe('documents.editor.surface_label');
    });
});

describe('final save on leaving', () => {
    const payload = { content: '{}', title: 'T', lock_version: 3, autosave: true };

    it('sends small drafts as keepalive with the XSRF header and large ones as a normal request', () => {
        document.cookie = 'XSRF-TOKEN=abc%3D';
        const calls: RequestInit[] = [];
        const send = ((_u: string, init: RequestInit) => (calls.push(init), Promise.resolve(new Response()))) as typeof fetch;

        expect(sendFinalSave('/save', payload, send)).toBe(true);
        expect(sendFinalSave('/save', { ...payload, content: 'x'.repeat(KEEPALIVE_LIMIT) }, send)).toBe(false);
        expect(calls.map((c) => c.keepalive)).toEqual([true, false]);
        expect((calls[0].headers as Record<string, string>)['X-XSRF-TOKEN']).toBe('abc=');
        expect(JSON.parse(calls[0].body as string)).toEqual(payload);
    });

    it('never throws when the request cannot be made', () => {
        const send = (() => { throw new TypeError('gone'); }) as unknown as typeof fetch;
        expect(() => sendFinalSave('/save', payload, send)).not.toThrow();
    });
});
