import { mergeAttributes, Node, type Editor } from '@tiptap/core';
import { trans } from 'laravel-vue-i18n';
import type { EditorMode, Gender } from '../createEditor';

export type FieldOption = { label: string; male?: string; female?: string };

const form = (o: FieldOption, gender: Gender) => (gender && o[gender]) || o.label;

export function focusField(editor: Editor, from: number, dir: 1 | -1): boolean {
    const positions: number[] = [];
    editor.state.doc.descendants((n, pos) => {
        if (n.type.name === 'docField') positions.push(pos);
    });
    const pos = dir === 1 ? positions.find((p) => p > from) : positions.reverse().find((p) => p < from);
    if (pos === undefined) return false;
    const dom = editor.view.nodeDOM(pos);
    const control = dom instanceof HTMLElement ? dom.querySelector<HTMLElement>('input, select') : null;
    if (!control) return false;
    control.focus();
    control.scrollIntoView({ block: 'nearest' });

    return true;
}

function data(name: string, json = false) {
    return {
        default: null,
        parseHTML: (el: HTMLElement) => {
            const raw = el.getAttribute(`data-efe-${name}`);
            if (raw === null || !json) return raw;
            try {
                return JSON.parse(raw);
            } catch {
                return null;
            }
        },
        renderHTML: (a: Record<string, unknown>) => (a[name] === null || a[name] === undefined ? {} : { [`data-efe-${name}`]: json ? JSON.stringify(a[name]) : a[name] }),
    };
}

/**
 * `docField`: inline atom with fieldId, label, required, maxLength, options, value (validator allowlist). The node
 * view is an input (a select with options) that writes the value back as a node attribute; Tab and Shift+Tab move
 * between fields, Ctrl+Z/Y stay the editor's undo. In the template a double click asks to edit the field
 * (event `efe-field-edit` with the position, handled by the page).
 */
export const DocField = Node.create<{ mode: EditorMode; gender: Gender }>({
    name: 'docField',
    group: 'inline',
    inline: true,
    atom: true,
    selectable: true,

    addOptions() {
        return { mode: 'document', gender: null };
    },

    addAttributes() {
        return {
            fieldId: data('field'),
            label: data('label'),
            required: data('required', true),
            maxLength: data('maxlength', true),
            options: data('options', true),
            value: data('value'),
        };
    },

    parseHTML() {
        return [{ tag: 'span[data-efe-field]' }];
    },

    renderHTML({ HTMLAttributes, node }) {
        return ['span', mergeAttributes({ class: 'efe-field' }, HTMLAttributes), node.attrs.value ?? ''];
    },

    renderText({ node }) {
        return node.attrs.value ?? '';
    },

    addKeyboardShortcuts() {
        const jump = (dir: 1 | -1) => () => this.options.mode === 'document' && !this.editor.isActive('listItem')
            && focusField(this.editor, this.editor.state.selection.from, dir);

        return { Tab: jump(1), 'Shift-Tab': jump(-1) };
    },

    addNodeView() {
        const { mode, gender } = this.options;

        return ({ node, getPos, editor }) => {
            const dom = document.createElement('span');
            dom.className = 'efe-field';
            let current = node;
            let control: HTMLInputElement | HTMLSelectElement;
            let optionsKey = '';

            const commit = (value: string) => {
                const pos = getPos();
                if (typeof pos !== 'number' || !editor.isEditable || value === (current.attrs.value ?? '')) return;
                editor.view.dispatch(editor.state.tr.setNodeAttribute(pos, 'value', value));
            };

            const onKey = (e: KeyboardEvent) => {
                const pos = getPos();
                if (!editor.isEditable && !['Tab', 'Escape'].includes(e.key)) {
                    e.preventDefault();
                    return;
                }
                const mod = e.ctrlKey || e.metaKey;
                if (e.key === 'Tab' && typeof pos === 'number' && focusField(editor, pos, e.shiftKey ? -1 : 1)) e.preventDefault();
                else if (mod && e.key.toLowerCase() === 'z') {
                    e.preventDefault();
                    if (e.shiftKey) editor.commands.redo();
                    else editor.commands.undo();
                } else if (mod && e.key.toLowerCase() === 'y') {
                    e.preventDefault();
                    editor.commands.redo();
                } else if ((e.key === 'Escape' || e.key === 'Enter') && typeof pos === 'number') {
                    e.preventDefault();
                    editor.commands.focus(pos + current.nodeSize);
                }
            };

            const build = () => {
                const options: FieldOption[] | null = current.attrs.options;
                optionsKey = JSON.stringify(options);
                if (options?.length) {
                    const select = document.createElement('select');
                    select.append(new Option(`– ${current.attrs.label || trans('documents.editor.field.choose')} –`, ''));
                    for (const o of options) select.append(new Option(form(o, gender), form(o, gender)));
                    select.addEventListener('change', () => commit(select.value));
                    select.addEventListener('mousedown', (e) => !editor.isEditable && e.preventDefault());
                    control = select;
                } else {
                    const input = document.createElement('input');
                    input.type = 'text';
                    input.addEventListener('input', () => commit(input.value));
                    input.addEventListener('focus', () => (input.readOnly = !editor.isEditable));
                    control = input;
                }
                control.className = 'efe-field__control';
                control.addEventListener('keydown', (e) => onKey(e as KeyboardEvent));
                dom.replaceChildren(control);
            };

            const sync = () => {
                const { label, required, maxLength, value, options } = current.attrs;
                const v = value ?? '';
                if (control instanceof HTMLSelectElement) {
                    const i = (options as FieldOption[]).findIndex((o) => [o.label, o.male, o.female].includes(v));
                    control.selectedIndex = i + 1;
                } else {
                    if (control.value !== v) control.value = v;
                    control.placeholder = label ?? '';
                    control.size = Math.max(8, Math.min(60, Math.max(v.length, (label ?? '').length) + 1));
                    if (maxLength) control.maxLength = maxLength;
                    else control.removeAttribute('maxlength');
                }
                control.setAttribute('aria-label', (label || trans('documents.editor.field.unnamed')) + (required ? ` (${trans('documents.editor.field.required')})` : ''));
                if (required) control.setAttribute('aria-required', 'true');
                else control.removeAttribute('aria-required');
                dom.classList.toggle('efe-field--required', required === true);
                dom.classList.toggle('efe-field--missing', required === true && !v.trim());
            };

            build();
            sync();
            if (mode === 'template') {
                dom.addEventListener('dblclick', (e) => {
                    const pos = getPos();
                    if (typeof pos !== 'number') return;
                    e.preventDefault();
                    dom.dispatchEvent(new CustomEvent('efe-field-edit', { bubbles: true, detail: { pos } }));
                });
            }

            return {
                dom,
                update: (n) => {
                    if (n.type !== current.type) return false;
                    current = n;
                    if (JSON.stringify(n.attrs.options) !== optionsKey) build();
                    sync();
                    return true;
                },
                stopEvent: (e) => e.target === control,
                ignoreMutation: () => true,
            };
        };
    },
});
