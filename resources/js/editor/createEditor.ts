import { Editor } from '@tiptap/vue-3';
import type { JSONContent } from '@tiptap/core';
import StarterKit from '@tiptap/starter-kit';
import TextAlign from '@tiptap/extension-text-align';
import { Table, TableCell, TableHeader, TableRow } from '@tiptap/extension-table';
import { DocSection, Document } from './extensions/DocSection';
export { newId } from './extensions/DocSection';
import { DocField } from './extensions/DocField';
import { DocVariable } from './extensions/DocVariable';
import { PageBreak } from './extensions/PageBreak';
import { SectionLockGuard, type BlockReason as LockReason } from './extensions/SectionLockGuard';
import { DocumentLimits } from './extensions/DocumentLimits';
import { trans } from 'laravel-vue-i18n';

export type EditorMode = 'document' | 'template';
export type Variable = { key: string; label: string };
export type Gender = 'male' | 'female' | null;
export type BlockReason = LockReason | 'table-size';

export interface CreateEditorOptions {
    mode: EditorMode;
    content: JSONContent;
    variables: Variable[];
    values?: Record<string, string>;
    gender?: Gender;
    editable?: boolean;
    onBlocked?: (reason: BlockReason) => void;
    onUpdate?: () => void;
}

/**
 * The editor with exactly the schema of App\Documents\DocumentValidator: the nodes and marks of
 * the `document` preset, no code, code block, link or image, headings 1-3, textAlign on heading and paragraph,
 * tables without resizing and without the table kit's `align` cell attribute.
 *
 * ProseMirror drops what its schema does not know while parsing. So the loaded document is compared with what
 * the editor made of it (canonical()), and on any difference `conforms` is false: the caller must not let anyone
 * edit or save it, else the first autosave would silently lose that content.
 */
export function createEditor(o: CreateEditorOptions): { editor: Editor; conforms: boolean; difference: string | null } {
    const editor = new Editor({
        content: o.content,
        editable: false,
        editorProps: { attributes: { 'aria-label': trans('documents.editor.surface_label'), role: 'textbox', 'aria-multiline': 'true' } },
        extensions: [
            Document,
            StarterKit.configure({
                document: false,
                code: false,
                codeBlock: false,
                link: false,
                trailingNode: false,
                heading: { levels: [1, 2, 3] },
            }),
            TextAlign.configure({ types: ['heading', 'paragraph'] }),
            Table.configure({ resizable: false }),
            TableRow,
            TableCell.extend({ addAttributes() { const { align: _, ...attrs } = (this.parent?.() ?? {}) as Record<string, unknown>; return attrs; } }),
            TableHeader.extend({ addAttributes() { const { align: _, ...attrs } = (this.parent?.() ?? {}) as Record<string, unknown>; return attrs; } }),
            PageBreak,
            DocSection.configure({ mode: o.mode }),
            DocField.configure({ mode: o.mode, gender: o.gender ?? null }),
            DocVariable.configure({ mode: o.mode, variables: o.variables, values: o.values ?? {} }),
            DocumentLimits.configure({ onBlocked: (r) => o.onBlocked?.(r) }),
            ...(o.mode === 'document' ? [SectionLockGuard.configure({ onBlocked: o.onBlocked ?? (() => {}) })] : []),
        ],
        onUpdate: () => o.onUpdate?.(),
    });

    let difference = firstDifference(canonical(o.content), canonical(editor.getJSON()), 'doc');
    try {
        editor.state.doc.check();
    } catch (e) {
        difference ??= `doc (${e instanceof Error ? e.message : 'invalid'})`;
    }
    if (difference === null && o.editable !== false) editor.setEditable(true, false);

    return { editor, conforms: difference === null, difference };
}

/**
 * The editor's JSON as it is sent: attributes the editor leaves unset (null) are dropped, an emptied attrs
 * object goes too. Values the server sent (a `required: false`, an empty `title`) stay as they came, so
 * SectionGuard compares like with like; the dialogs themselves never set false or empty, only leave unset.
 */
export function serialize(json: JSONContent): JSONContent {
    const out: JSONContent = { ...json };
    if (json.attrs) {
        const attrs = Object.fromEntries(Object.entries(json.attrs).filter(([, v]) => v !== null && v !== undefined));
        if (Object.keys(attrs).length) out.attrs = attrs;
        else delete out.attrs;
    }
    if (json.content) out.content = json.content.map(serialize);

    return out;
}

const DEFAULTS: Record<string, Record<string, unknown>> = {
    orderedList: { start: 1 },
    tableCell: { colspan: 1, rowspan: 1 },
    tableHeader: { colspan: 1, rowspan: 1 },
};

export function canonical(json: JSONContent): JSONContent {
    const out = serialize(json);
    const defaults = DEFAULTS[out.type ?? ''];
    if (out.attrs && defaults) {
        out.attrs = Object.fromEntries(Object.entries(out.attrs).filter(([k, v]) => defaults[k] !== v));
        if (!Object.keys(out.attrs).length) delete out.attrs;
    }
    if (out.marks) out.marks = [...out.marks].sort((a, b) => String(a.type).localeCompare(String(b.type)));
    if (out.content) out.content = out.content.length ? out.content.map(canonical) : undefined;
    if (out.content === undefined) delete out.content;

    return out;
}

export function firstDifference(a: unknown, b: unknown, path: string): string | null {
    if (a === b) return null;
    if (typeof a !== 'object' || typeof b !== 'object' || a === null || b === null || Array.isArray(a) !== Array.isArray(b)) return path;
    const ra = a as Record<string, unknown>;
    const rb = b as Record<string, unknown>;
    const keys = new Set([...Object.keys(ra), ...Object.keys(rb)]);
    for (const k of keys) {
        const d = firstDifference(ra[k], rb[k], Array.isArray(a) ? `${path}[${k}]` : `${path}.${k}`);
        if (d !== null) return d;
    }

    return null;
}

export type OpenItem =
    | { kind: 'field'; label: string; section: string; pos: number }
    | { kind: 'variable'; state: 'missing' | 'unknown'; name: string; label: string; pos: number };

export function collectOpenItems(editor: Editor, variables: Variable[], values: Record<string, string>): OpenItem[] {
    const labels = new Map(variables.map((v) => [v.key, v.label]));
    const items: OpenItem[] = [];
    const seen = new Set<string>();
    editor.state.doc.forEach((section, offset) => {
        section.descendants((node, p) => {
            const pos = offset + 1 + p;
            if (node.type.name === 'docField' && node.attrs.required === true && !String(node.attrs.value ?? '').trim()) {
                items.push({ kind: 'field', label: node.attrs.label || node.attrs.fieldId, section: section.attrs.title ?? '', pos });
            } else if (node.type.name === 'docVariable' && !Object.hasOwn(values, node.attrs.name) && !seen.has(node.attrs.name)) {
                seen.add(node.attrs.name);
                const label = labels.get(node.attrs.name);
                items.push({ kind: 'variable', state: label ? 'missing' : 'unknown', name: node.attrs.name, label: label ?? node.attrs.name, pos });
            }
        });
    });

    return items;
}

export function revealItem(editor: Editor, pos: number): void {
    const dom = editor.view.nodeDOM(pos);
    if (!(dom instanceof HTMLElement)) return;
    dom.scrollIntoView({ block: 'center', behavior: 'smooth' });
    const control = dom.querySelector<HTMLElement>('input, select');
    if (control) control.focus({ preventScroll: true });
    else editor.chain().focus().setNodeSelection(pos).run();
}

export interface EditorApi {
    getJSON(): JSONContent;
    openItems(): OpenItem[];
    reveal(item: OpenItem): void;
    lock(): void;
}
