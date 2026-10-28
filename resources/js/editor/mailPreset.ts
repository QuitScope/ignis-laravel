import { Editor } from '@tiptap/vue-3';
import { mergeAttributes, Node, type Extensions, type JSONContent } from '@tiptap/core';
import StarterKit from '@tiptap/starter-kit';
import Link from '@tiptap/extension-link';
import { OrderedList } from '@tiptap/extension-list';
import { trans } from 'laravel-vue-i18n';
import { DocVariable } from './extensions/DocVariable';
import { serializeKb } from './kbPreset';
import type { EditorMode, Variable } from './createEditor';

const URL_WHITESPACE = /[\x00-\x20\x7F-\xA0\u{1680}\u{180E}\u{2000}-\u{2029}\u{205F}\u{3000}\u{FEFF}]/u;

/**
 * Mirror of MailContentValidator::validHref(): http(s)://host or mailto:x only, no whitespace, control characters or
 * backslashes anywhere. The server check is binding; this one keeps the editor from producing what it refuses.
 */
export function isMailHref(href: unknown): href is string {
    return typeof href === 'string' && new TextEncoder().encode(href).length <= 2048
        && !URL_WHITESPACE.test(href) && !href.includes('\\')
        && /^(?:https?:\/\/[^/?#]|mailto:.)/i.test(href);
}

/**
 * Shape of a rank badge path (same-site path, optional legacy `?v=N`). Whether it really is a badge of this instance
 * only the server knows (MailContentValidator::validImageSrc); badges come from the server-filled signature anyway.
 */
export function isBadgeSrc(src: unknown): src is string {
    return typeof src === 'string' && src.length <= 255 && !src.includes('..')
        && /^\/(?![/\\])[A-Za-z0-9._/-]+(?:\?v=\d{1,12})?$/.test(src);
}

export const MailImage = Node.create({
    name: 'image',
    group: 'inline',
    inline: true,
    atom: true,

    addAttributes() {
        return {
            src: { default: null, parseHTML: (el) => (isBadgeSrc(el.getAttribute('src')) ? el.getAttribute('src') : null) },
            alt: { default: '', parseHTML: () => '' },
        };
    },

    parseHTML() {
        return [{ tag: 'img[src]', getAttrs: (el) => (isBadgeSrc((el as HTMLElement).getAttribute('src')) ? null : false) }];
    },

    renderHTML({ HTMLAttributes }) {
        return ['img', mergeAttributes({ src: isBadgeSrc(HTMLAttributes.src) ? HTMLAttributes.src : null, alt: '' }, { class: 'mail-badge' })];
    },
});

export const MailLink = Link.extend({
    addAttributes() {
        return { href: { default: null } };
    },
}).configure({
    openOnClick: false,
    autolink: true,
    linkOnPaste: true,
    defaultProtocol: 'https',
    protocols: [],
    HTMLAttributes: { target: null, rel: null, class: null },
    isAllowedUri: (url) => isMailHref(url),
});

const MailOrderedList = OrderedList.extend({
    addAttributes() {
        return {
            start: {
                default: 1,
                parseHTML: (el) => {
                    const n = parseInt(el.getAttribute('start') ?? '', 10);
                    return Number.isFinite(n) ? Math.min(99999, Math.max(0, n)) : 1;
                },
            },
        };
    },
});

export interface MailEditorOptions {
    content: JSONContent | null;
    editable?: boolean;
    variables?: { mode: EditorMode; list: Variable[]; values?: Record<string, string> };
    label?: string;
    onUpdate?: () => void;
}

/**
 * The `mail` preset: exactly the schema of MailContentValidator. Paragraph, hard break, bold/italic/strike, link,
 * quote, bullet/ordered list, badge image; `docVariable` only in the signature editor. No headings, code, rules,
 * underline, colours or raw HTML: ProseMirror parses pasted HTML through this schema and drops the rest.
 */
export function mailExtensions(variables?: MailEditorOptions['variables']): Extensions {
    return [
        StarterKit.configure({
            code: false,
            codeBlock: false,
            heading: false,
            horizontalRule: false,
            underline: false,
            link: false,
            orderedList: false,
            trailingNode: false,
        }),
        MailLink,
        MailOrderedList,
        MailImage,
        ...(variables ? [DocVariable.configure({ mode: variables.mode, variables: variables.list, values: variables.values ?? {} })] : []),
    ];
}

/**
 * The editor's JSON as it is sent (serializeKb: null attributes dropped) without marks on the badge image:
 * ProseMirror's addMark puts bold/link on every inline node of a selection, atoms included (a node spec cannot
 * forbid marks on the node itself). MailContentValidator drops them as well.
 */
export function serializeMail(json: JSONContent): JSONContent {
    const out = serializeKb(json);
    const strip = (n: JSONContent): JSONContent => {
        const { marks, ...rest } = n;
        const node = n.type === 'image' ? rest : marks ? { ...rest, marks } : rest;
        return n.content ? { ...node, content: n.content.map(strip) } : node;
    };

    return strip(out);
}

export function createMailEditor(o: MailEditorOptions): Editor {
    return new Editor({
        content: o.content ?? { type: 'doc', content: [] },
        editable: o.editable !== false,
        editorProps: { attributes: { 'aria-label': o.label ?? trans('mail.editor.label'), role: 'textbox', 'aria-multiline': 'true' } },
        extensions: mailExtensions(o.variables),
        onUpdate: () => o.onUpdate?.(),
    });
}
