import { Editor } from '@tiptap/vue-3';
import { mergeAttributes, Node, type Extensions, type JSONContent } from '@tiptap/core';
import StarterKit from '@tiptap/starter-kit';
import Link from '@tiptap/extension-link';
import { OrderedList } from '@tiptap/extension-list';
import { Table, TableCell, TableHeader, TableRow } from '@tiptap/extension-table';
import { trans } from 'laravel-vue-i18n';

/** Mirror of Modules\KnowledgeBase\Content\KbContentValidator::IMAGE_SRC. */
export const KB_IMAGE_SRC = /^\/storage\/kb-images\/[a-f0-9]{32}\.(jpg|png|webp|gif)$/;

const URL_WHITESPACE = /[\x00-\x20\x7F-\xA0\u{1680}\u{180E}\u{2000}-\u{2029}\u{205F}\u{3000}\u{FEFF}]/u;

/**
 * Mirror of KbContentValidator::validHref(): http(s)://, mailto: or a path with exactly one leading `/`; no
 * whitespace, control characters or backslashes anywhere. The server check is binding, this one keeps the editor
 * from producing what the server refuses.
 */
export function isAllowedHref(href: unknown): href is string {
    return typeof href === 'string' && href !== '' && new TextEncoder().encode(href).length <= 2048
        && !URL_WHITESPACE.test(href) && !href.includes('\\')
        && /^(?:https?:\/\/|mailto:|\/(?!\/))/i.test(href);
}

export function kbImageSrc(raw: string | null): string | null {
    if (raw === null) return null;
    if (KB_IMAGE_SRC.test(raw)) return raw;
    try {
        const url = new URL(raw);
        return url.origin === window.location.origin && !url.search && !url.hash && KB_IMAGE_SRC.test(url.pathname) ? url.pathname : null;
    } catch {
        return null;
    }
}

export const kbAlt = (raw: string | null): string => Array.from(raw ?? '').slice(0, 255).join('');

export const KbImage = Node.create({
    name: 'image',
    group: 'block',
    atom: true,
    draggable: true,

    addAttributes() {
        return {
            src: { default: null, parseHTML: (el) => kbImageSrc(el.getAttribute('src')) },
            alt: { default: null, parseHTML: (el) => kbAlt(el.getAttribute('alt')) },
        };
    },

    parseHTML() {
        return [{ tag: 'img[src]', getAttrs: (el) => (kbImageSrc((el as HTMLElement).getAttribute('src')) ? null : false) }];
    },

    renderHTML({ HTMLAttributes }) {
        const src = KB_IMAGE_SRC.test(String(HTMLAttributes.src ?? '')) ? HTMLAttributes.src : null;

        return ['img', mergeAttributes({ src, alt: HTMLAttributes.alt ?? '' }, { class: 'kb-image' })];
    },
});

export const KbLink = Link.extend({
    addAttributes() {
        return {
            href: { default: null },
            target: { default: null, parseHTML: (el) => (el.getAttribute('target') === '_blank' ? '_blank' : null) },
            rel: { default: null, parseHTML: () => null },
        };
    },
}).configure({
    openOnClick: false,
    autolink: true,
    linkOnPaste: true,
    defaultProtocol: 'https',
    protocols: [],
    HTMLAttributes: { target: null, rel: null, class: null },
    isAllowedUri: (url) => isAllowedHref(url),
});

const KbOrderedList = OrderedList.extend({
    addAttributes() {
        return {
            start: {
                default: 1,
                parseHTML: (el) => {
                    const n = parseInt(el.getAttribute('start') ?? '', 10);
                    return Number.isFinite(n) ? Math.min(99999, Math.max(0, n)) : 1;
                },
            },
            type: {
                default: null,
                parseHTML: (el) => {
                    const t = el.getAttribute('type');
                    return t !== null && ['1', 'a', 'A', 'i', 'I'].includes(t) ? t : null;
                },
            },
        };
    },
});

const stripCellAttrs = function (this: { parent?: () => Record<string, unknown> }) {
    const { align: _a, colwidth: _c, ...attrs } = (this.parent?.() ?? {}) as Record<string, unknown>;

    return attrs;
};

/**
 * The `kb` preset: exactly the schema of KbContentValidator. Headings 1-3, bold/italic/underline/strike, link, lists,
 * quote, table, rule, hard break, image. No code, code block, highlight, colours or raw HTML: ProseMirror parses
 * pasted HTML through this schema and drops everything it does not know.
 */
export function kbExtensions(): Extensions {
    return [
        StarterKit.configure({
            code: false,
            codeBlock: false,
            link: false,
            orderedList: false,
            trailingNode: false,
            heading: { levels: [1, 2, 3] },
        }),
        KbLink,
        KbOrderedList,
        Table.configure({ resizable: false }),
        TableRow,
        TableCell.extend({ addAttributes: stripCellAttrs }),
        TableHeader.extend({ addAttributes: stripCellAttrs }),
        KbImage,
    ];
}

export function createKbEditor(o: { content: JSONContent | null; editable?: boolean; onUpdate?: () => void }): Editor {
    return new Editor({
        content: o.content ?? { type: 'doc', content: [] },
        editable: o.editable !== false,
        editorProps: { attributes: { 'aria-label': trans('knowledgebase.editor.surface_label'), role: 'textbox', 'aria-multiline': 'true' } },
        extensions: kbExtensions(),
        onUpdate: () => o.onUpdate?.(),
    });
}

export function serializeKb(json: JSONContent): JSONContent {
    const clean = (attrs?: Record<string, unknown>) => {
        const out = Object.fromEntries(Object.entries(attrs ?? {}).filter(([, v]) => v !== null && v !== undefined));

        return Object.keys(out).length ? out : undefined;
    };
    const out: JSONContent = { ...json };
    const attrs = clean(json.attrs);
    if (attrs) out.attrs = attrs;
    else delete out.attrs;
    if (json.marks) out.marks = json.marks.map((m) => {
        const a = clean(m.attrs);

        return a ? { type: m.type, attrs: a } : { type: m.type };
    });
    if (json.content) out.content = json.content.map(serializeKb);

    return out;
}
