import { Extension } from '@tiptap/core';
import type { Node as PMNode } from '@tiptap/pm/model';
import { Plugin } from '@tiptap/pm/state';
import { withoutValues } from './DocSection';

export type BlockReason = 'locked-content' | 'section-structure';

const signature = (n: PMNode) => JSON.stringify(n.attrs);

function align(before: PMNode[], after: PMNode[]): [PMNode, PMNode][] | null {
    if (before.length === after.length) {
        return before.every((n, i) => signature(n) === signature(after[i])) ? before.map((n, i) => [n, after[i]]) : null;
    }
    if (Math.abs(before.length - after.length) !== 1) return null;

    const added = after.length > before.length;
    const [long, short] = added ? [after, before] : [before, after];
    let k = short.findIndex((n, i) => signature(n) !== signature(long[i]));
    if (k < 0) k = short.length;
    const extra = long[k];
    const group = extra.attrs.templateSectionId;
    if (!group || extra.attrs.repeatable !== true || !short.some((n) => n.attrs.templateSectionId === group)) return null;
    if (short.slice(k).some((n, i) => signature(n) !== signature(long[k + 1 + i]))) return null;

    return short.map((n, i) => {
        const other = long[i < k ? i : i + 1];
        return added ? [n, other] : [other, n];
    });
}

/**
 * Document mode: rejects every transaction that changes the text of a locked section (field values excepted)
 * or adds, removes, reorders or re-labels sections (except instances of a repeatable one). The browser side of
 * SectionGuard, so writers see at once what the server would undo; onBlocked names the reason.
 */
export const SectionLockGuard = Extension.create<{ onBlocked: (reason: BlockReason) => void }>({
    name: 'sectionLockGuard',

    addOptions() {
        return { onBlocked: () => {} };
    },

    addProseMirrorPlugins() {
        const block = (reason: BlockReason) => {
            this.options.onBlocked(reason);
            return false;
        };

        return [new Plugin({
            filterTransaction: (tr, state) => {
                if (!tr.docChanged) return true;
                const before: PMNode[] = [];
                const after: PMNode[] = [];
                state.doc.forEach((n) => before.push(n));
                tr.doc.forEach((n) => after.push(n));

                const pairs = align(before, after);
                if (pairs === null) return block('section-structure');
                for (const [a, b] of pairs) {
                    if (a !== b && a.attrs.mode === 'locked' && !withoutValues(a).eq(withoutValues(b))) return block('locked-content');
                }

                return true;
            },
        })];
    },
});
