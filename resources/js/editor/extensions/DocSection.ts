import { mergeAttributes, Node } from '@tiptap/core';
import { Fragment, Slice, type Node as PMNode } from '@tiptap/pm/model';
import { Plugin } from '@tiptap/pm/state';
import { trans } from 'laravel-vue-i18n';
import type { EditorMode } from '../createEditor';

declare module '@tiptap/core' {
    interface Commands<ReturnType> {
        docSection: {
            insertSection: (mode: 'free' | 'locked') => ReturnType;
            toggleSectionMode: () => ReturnType;
            addSectionInstance: (pos: number) => ReturnType;
            removeSectionInstance: (pos: number) => ReturnType;
        };
    }
}

export function newId(prefix = 'id'): string {
    if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') return crypto.randomUUID();
    return `${prefix}-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 10)}`;
}

export const Document = Node.create({ name: 'doc', topNode: true, content: 'docSection+' });

export function sectionAt(state: { selection: { $from: { depth: number; node: (d: number) => PMNode; before: (d: number) => number } } }) {
    const $from = state.selection.$from;
    for (let d = $from.depth; d > 0; d--) {
        if ($from.node(d).type.name === 'docSection') return { node: $from.node(d), pos: $from.before(d) };
    }

    return null;
}

export function withoutValues(node: PMNode): PMNode {
    if (node.type.name === 'docField') return node.type.create({ ...node.attrs, value: null }, null, node.marks);
    if (!node.childCount) return node;
    const children: PMNode[] = [];
    node.forEach((c) => children.push(withoutValues(c)));

    return node.copy(Fragment.from(children));
}

function nullable(name: string) {
    return {
        default: null,
        parseHTML: (el: HTMLElement) => el.getAttribute(`data-efe-${name}`) ?? null,
        renderHTML: (a: Record<string, unknown>) => (a[name] ? { [`data-efe-${name}`]: a[name] } : {}),
    };
}

/**
 * `docSection`: block container (`block+`, isolating), attrs as in the validator. Unset attributes stay null
 * and are not serialised. Title and hint only show in the editor, never in the PDF.
 */
export const DocSection = Node.create<{ mode: EditorMode }>({
    name: 'docSection',
    content: 'block+',
    defining: true,
    isolating: true,

    addOptions() {
        return { mode: 'document' };
    },

    addAttributes() {
        return {
            mode: {
                default: 'free',
                parseHTML: (el: HTMLElement) => (el.getAttribute('data-efe-mode') === 'locked' ? 'locked' : 'free'),
                renderHTML: (a: Record<string, unknown>) => ({ 'data-efe-mode': a.mode }),
            },
            sectionId: nullable('section'),
            templateSectionId: nullable('template-section'),
            repeatable: {
                default: null,
                parseHTML: (el: HTMLElement) => (el.getAttribute('data-efe-repeatable') === 'true' ? true : null),
                renderHTML: (a: Record<string, unknown>) => (a.repeatable ? { 'data-efe-repeatable': 'true' } : {}),
            },
            title: nullable('title'),
            hint: nullable('hint'),
        };
    },

    parseHTML() {
        return [{ tag: 'section[data-efe-section]' }];
    },

    renderHTML({ HTMLAttributes }) {
        return ['section', mergeAttributes({ class: 'efe-section' }, HTMLAttributes), 0];
    },

    addCommands() {
        const section = (mode: 'free' | 'locked') => ({ type: 'docSection', attrs: { mode, sectionId: newId('section') }, content: [{ type: 'paragraph' }] });

        return {
            insertSection: (mode) => ({ state, chain }) => {
                const at = sectionAt(state);
                const pos = at ? at.pos + at.node.nodeSize : state.doc.content.size;
                return chain().insertContentAt(pos, section(mode)).focus(pos + 2).run();
            },
            toggleSectionMode: () => ({ state, tr, dispatch }) => {
                const at = sectionAt(state);
                if (!at) return false;
                if (dispatch) tr.setNodeAttribute(at.pos, 'mode', at.node.attrs.mode === 'locked' ? 'free' : 'locked');
                return true;
            },
            addSectionInstance: (pos) => ({ state, tr, dispatch }) => {
                const node = state.doc.nodeAt(pos);
                if (!node || node.attrs.repeatable !== true || !node.attrs.templateSectionId) return false;
                const copy = node.type.create({ ...node.attrs, sectionId: newId('section') }, withoutValues(node).content);
                if (dispatch) tr.insert(pos + node.nodeSize, copy).scrollIntoView();
                return true;
            },
            removeSectionInstance: (pos) => ({ state, tr, dispatch }) => {
                const node = state.doc.nodeAt(pos);
                const group = node?.attrs.templateSectionId;
                if (!node || node.attrs.repeatable !== true || !group) return false;
                let count = 0;
                state.doc.forEach((n) => (count += n.attrs.templateSectionId === group ? 1 : 0));
                if (count < 2) return false;
                if (dispatch) tr.delete(pos, pos + node.nodeSize);
                return true;
            },
        };
    },

    addProseMirrorPlugins() {
        if (this.options.mode !== 'template') return [];

        return [new Plugin({
            props: {
                transformPasted: (slice, view) => {
                    if (view.dragging?.move) return slice;
                    const taken = new Set<string>();
                    view.state.doc.descendants((n) => {
                        if (n.type.name === 'docSection') taken.add(n.attrs.sectionId);
                        if (n.type.name === 'docField') taken.add(n.attrs.fieldId);
                    });
                    const renew = (fragment: Fragment): Fragment => {
                        const nodes: PMNode[] = [];
                        fragment.forEach((n) => {
                            let attrs = n.attrs;
                            if (n.type.name === 'docSection' && taken.has(n.attrs.sectionId)) attrs = { ...attrs, sectionId: newId('section'), templateSectionId: null, repeatable: null };
                            if (n.type.name === 'docField' && taken.has(n.attrs.fieldId)) attrs = { ...attrs, fieldId: newId('field') };
                            nodes.push(n.isText ? n : n.type.create(attrs, renew(n.content), n.marks));
                        });
                        return Fragment.from(nodes);
                    };
                    return new Slice(renew(slice.content), slice.openStart, slice.openEnd);
                },
            },
        })];
    },

    addNodeView() {
        const mode = this.options.mode;

        return ({ node, getPos, editor }) => {
            const dom = document.createElement('section');
            const head = document.createElement('div');
            const content = document.createElement('div');
            const foot = document.createElement('div');
            dom.className = 'efe-section';
            head.className = 'efe-section__head';
            content.className = 'efe-section__content';
            foot.className = 'efe-section__foot';
            head.contentEditable = foot.contentEditable = 'false';
            dom.append(head, content, foot);

            const button = (label: string, run: (pos: number) => void) => {
                const b = document.createElement('button');
                b.type = 'button';
                b.className = 'efe-section__button';
                b.textContent = label;
                b.addEventListener('click', () => {
                    const pos = getPos();
                    if (typeof pos === 'number' && editor.isEditable) run(pos);
                });
                return b;
            };

            let current = node;
            const render = () => {
                const { mode: m, title, hint, repeatable, templateSectionId } = current.attrs;
                dom.dataset.mode = m;
                head.replaceChildren();
                if (mode === 'template') {
                    const badge = document.createElement('span');
                    badge.className = 'efe-section__badge';
                    badge.textContent = trans(m === 'locked' ? 'documents.editor.section.locked' : 'documents.editor.section.free');
                    head.append(badge);
                }
                for (const [cls, text] of [['title', title], ['hint', hint]] as const) {
                    if (!text) continue;
                    const span = document.createElement('span');
                    span.className = `efe-section__${cls}`;
                    span.textContent = text;
                    head.append(span);
                }
                if (mode === 'template' && repeatable) {
                    const span = document.createElement('span');
                    span.className = 'efe-section__hint';
                    span.textContent = trans('documents.editor.section.repeatable');
                    head.append(span);
                }
                head.hidden = !head.childElementCount;

                foot.replaceChildren();
                if (mode === 'document' && repeatable === true && templateSectionId) {
                    foot.append(
                        button(trans('documents.editor.section.add_instance'), (pos) => editor.commands.addSectionInstance(pos)),
                        button(trans('documents.editor.section.remove_instance'), (pos) => editor.commands.removeSectionInstance(pos)),
                    );
                }
                foot.hidden = !foot.childElementCount;
            };
            render();

            return {
                dom,
                contentDOM: content,
                update: (n) => {
                    if (n.type !== current.type) return false;
                    current = n;
                    render();
                    return true;
                },
                stopEvent: (e) => head.contains(e.target as globalThis.Node) || foot.contains(e.target as globalThis.Node),
                ignoreMutation: (m) => m.type !== 'selection' && !content.contains(m.target),
            };
        };
    },
});
