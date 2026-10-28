import { Node } from '@tiptap/core';
import { trans } from 'laravel-vue-i18n';

declare module '@tiptap/core' {
    interface Commands<ReturnType> {
        pageBreak: { setPageBreak: () => ReturnType };
    }
}

/** `pageBreak`: block atom without attributes; the PDF starts a new page there. */
export const PageBreak = Node.create({
    name: 'pageBreak',
    group: 'block',
    atom: true,
    selectable: true,

    parseHTML() {
        return [{ tag: 'div[data-efe-page-break]' }];
    },

    renderHTML() {
        return ['div', { class: 'efe-page-break', 'data-efe-page-break': '', contenteditable: 'false' }, ['span', trans('documents.editor.page_break')]];
    },

    addCommands() {
        return {
            setPageBreak: () => ({ commands }) => commands.insertContent({ type: this.name }),
        };
    },
});
