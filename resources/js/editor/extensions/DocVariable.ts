import { mergeAttributes, Node } from '@tiptap/core';
import Suggestion from '@tiptap/suggestion';
import { trans } from 'laravel-vue-i18n';
import type { EditorMode, Variable } from '../createEditor';

/**
 * The `{{` popup: a list of matching catalogue entries under the cursor, arrows and Enter pick, Escape closes.
 * Plain DOM with textContent only (labels come from the server).
 */
function suggestionPopup() {
    let el: HTMLDivElement | null = null;
    let items: Variable[] = [];
    let index = 0;
    let pick: (v: Variable) => void = () => {};

    const draw = (rect: DOMRect | null | undefined) => {
        if (!el) return;
        el.replaceChildren();
        if (!items.length) {
            const empty = document.createElement('div');
            empty.className = 'efe-suggest__empty';
            empty.textContent = trans('documents.editor.variable.none');
            el.append(empty);
        }
        items.forEach((v, i) => {
            const b = document.createElement('button');
            b.type = 'button';
            b.id = `efe-suggest-${i}`;
            b.className = 'efe-suggest__item';
            b.setAttribute('role', 'option');
            b.setAttribute('aria-selected', String(i === index));
            const label = document.createElement('span');
            label.textContent = v.label;
            const key = document.createElement('code');
            key.textContent = v.key;
            b.append(label, key);
            b.addEventListener('mousedown', (e) => {
                e.preventDefault();
                pick(v);
            });
            el!.append(b);
        });
        if (rect) {
            el.style.left = `${Math.min(rect.left, window.innerWidth - 320)}px`;
            el.style.top = `${rect.bottom + 4}px`;
        }
    };

    return {
        onStart: (props: { items: Variable[]; command: (v: Variable) => void; clientRect?: (() => DOMRect | null) | null }) => {
            el = document.createElement('div');
            el.className = 'efe-suggest';
            el.setAttribute('role', 'listbox');
            el.setAttribute('aria-label', trans('documents.editor.variable.insert'));
            document.body.append(el);
            items = props.items;
            index = 0;
            pick = props.command;
            draw(props.clientRect?.());
        },
        onUpdate: (props: { items: Variable[]; command: (v: Variable) => void; clientRect?: (() => DOMRect | null) | null }) => {
            items = props.items;
            index = 0;
            pick = props.command;
            draw(props.clientRect?.());
        },
        onKeyDown: ({ event }: { event: KeyboardEvent }) => {
            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                index = (index + (event.key === 'ArrowDown' ? 1 : items.length - 1)) % Math.max(items.length, 1);
                draw(null);
                return true;
            }
            if (event.key === 'Enter' && items[index]) {
                pick(items[index]);
                return true;
            }
            return false;
        },
        onExit: () => {
            el?.remove();
            el = null;
        },
    };
}

/**
 * `docVariable`: inline atom with `name`. Shown as a chip: in the template `{{name}}`, in a document the resolved
 * value, else "<label> fehlt" or, for a name the catalogue does not know, `{{name}}`. `{{` opens the suggestion.
 */
export const DocVariable = Node.create<{ mode: EditorMode; variables: Variable[]; values: Record<string, string> }>({
    name: 'docVariable',
    group: 'inline',
    inline: true,
    atom: true,
    selectable: true,

    addOptions() {
        return { mode: 'document', variables: [], values: {} };
    },

    addAttributes() {
        return {
            name: {
                default: null,
                parseHTML: (el: HTMLElement) => el.getAttribute('data-efe-variable'),
                renderHTML: (a: Record<string, unknown>) => ({ 'data-efe-variable': a.name }),
            },
        };
    },

    parseHTML() {
        return [{ tag: 'span[data-efe-variable]' }];
    },

    renderHTML({ HTMLAttributes, node }) {
        return ['span', mergeAttributes({ class: 'efe-variable' }, HTMLAttributes), `{{${node.attrs.name}}}`];
    },

    renderText({ node }) {
        return `{{${node.attrs.name}}}`;
    },

    addNodeView() {
        const { mode, variables, values } = this.options;
        const labels = new Map(variables.map((v) => [v.key, v.label]));

        return ({ node }) => {
            const dom = document.createElement('span');
            const name: string = node.attrs.name;
            const label = labels.get(name);
            let state = 'template';
            let text = `{{${name}}}`;
            if (mode === 'document') {
                if (Object.hasOwn(values, name)) [state, text] = ['resolved', values[name]];
                else if (label) [state, text] = ['missing', trans('documents.editor.variable.missing', { label })];
                else state = 'unknown';
            }
            dom.className = `efe-variable efe-variable--${state}`;
            dom.textContent = text;
            dom.title = label ? `${label} ({{${name}}})` : trans('documents.editor.variable.unknown', { name });

            return { dom };
        };
    },

    addProseMirrorPlugins() {
        const variables = this.options.variables;

        return [Suggestion<Variable, Variable>({
            editor: this.editor,
            char: '{{',
            allowedPrefixes: null,
            items: ({ query }) => {
                const q = query.toLowerCase();
                return variables.filter((v) => v.key.toLowerCase().includes(q) || v.label.toLowerCase().includes(q)).slice(0, 12);
            },
            command: ({ editor, range, props }) => {
                editor.chain().focus().insertContentAt(range, { type: 'docVariable', attrs: { name: props.key } }).run();
            },
            render: suggestionPopup,
        })];
    },
});
