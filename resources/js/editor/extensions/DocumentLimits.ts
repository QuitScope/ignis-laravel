import { Extension } from '@tiptap/core';
import type { Node as PMNode } from '@tiptap/pm/model';
import { Plugin, type Transaction } from '@tiptap/pm/state';
import { newId } from './DocSection';

/** The bounds of App\Documents\DocumentValidator, mirrored so the editor never produces what the server rejects. */
export const LIMITS = { rows: 200, cellsPerRow: 30, grid: 2000, colspan: 20, rowspan: 50, start: 99999, label: 200, hint: 1000, maxLength: 10000 };
const ID = /^[A-Za-z0-9_-]{1,64}$/;
export const VARIABLE = /^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)*$/;

const int = (v: unknown, min: number, max: number, fallback: number | null) =>
    typeof v === 'number' && Number.isInteger(v) && v >= min && v <= max ? v : (typeof v === 'number' && Number.isFinite(v) ? Math.min(max, Math.max(min, Math.trunc(v))) : fallback);
const text = (v: unknown, max: number) => (typeof v === 'string' ? v.slice(0, max) : null);
const option = (o: unknown) => {
    if (!o || typeof o !== 'object') return null;
    const r = o as Record<string, unknown>;
    const label = text(r.label, LIMITS.label);
    if (!label) return null;
    const out: Record<string, string> = { label };
    for (const k of ['male', 'female'] as const) {
        const t = text(r[k], LIMITS.label);
        if (t) out[k] = t;
    }
    return out;
};

export function sanitizeAttrs(type: string, attrs: Record<string, unknown>): Record<string, unknown> | null | false {
    let next: Record<string, unknown> = attrs;
    const set = (k: string, v: unknown) => {
        if (next[k] !== v) next = { ...next, [k]: v };
    };
    switch (type) {
        case 'orderedList':
            set('start', int(attrs.start, 0, LIMITS.start, 1));
            set('type', ['1', 'a', 'A', 'i', 'I'].includes(attrs.type as string) ? attrs.type : null);
            break;
        case 'tableCell':
        case 'tableHeader': {
            set('colspan', int(attrs.colspan, 1, LIMITS.colspan, 1));
            set('rowspan', int(attrs.rowspan, 1, LIMITS.rowspan, 1));
            const w = attrs.colwidth;
            const ok = w === null || (Array.isArray(w) && w.length <= 100 && w.every((x) => Number.isInteger(x) && x >= 0 && x <= 5000));
            if (!ok) set('colwidth', null);
            break;
        }
        case 'docField': {
            if (typeof attrs.fieldId !== 'string' || !ID.test(attrs.fieldId)) set('fieldId', newId('field'));
            set('required', typeof attrs.required === 'boolean' ? attrs.required : null);
            set('maxLength', typeof attrs.maxLength === 'number' && attrs.maxLength >= 1 ? int(attrs.maxLength, 1, LIMITS.maxLength, null) : null);
            if (attrs.label !== null) set('label', text(attrs.label, LIMITS.label));
            if (attrs.options !== null) {
                const opts = Array.isArray(attrs.options) ? attrs.options.slice(0, 200).map(option).filter((o) => o !== null) : [];
                if (JSON.stringify(opts) !== JSON.stringify(attrs.options)) set('options', opts.length ? opts : null);
            }
            if (attrs.value !== null) {
                const max = typeof next.maxLength === 'number' ? next.maxLength : LIMITS.maxLength;
                set('value', text(attrs.value, max));
            }
            break;
        }
        case 'docVariable':
            if (typeof attrs.name !== 'string' || attrs.name.length > 64 || !VARIABLE.test(attrs.name)) return false;
            break;
        case 'docSection':
            set('mode', attrs.mode === 'locked' ? 'locked' : 'free');
            if (typeof attrs.sectionId !== 'string' || !ID.test(attrs.sectionId)) set('sectionId', newId('section'));
            if (attrs.templateSectionId !== null && (typeof attrs.templateSectionId !== 'string' || !ID.test(attrs.templateSectionId))) set('templateSectionId', null);
            set('repeatable', attrs.repeatable === true ? true : attrs.repeatable === false ? false : null);
            if (attrs.title !== null) set('title', text(attrs.title, LIMITS.label));
            if (attrs.hint !== null) set('hint', text(attrs.hint, LIMITS.hint));
            break;
    }

    return next === attrs ? null : next;
}

export function tableFits(table: PMNode): boolean {
    if (table.childCount > LIMITS.rows) return false;
    let area = 0;
    let fits = true;
    table.forEach((row) => {
        if (row.childCount > LIMITS.cellsPerRow) fits = false;
        row.forEach((cell) => (area += Math.min(cell.attrs.colspan ?? 1, 100) * Math.min(cell.attrs.rowspan ?? 1, 100)));
    });

    return fits && area <= LIMITS.grid;
}

function changed(before: PMNode, after: PMNode): { node: PMNode; pos: number }[] {
    const old = new Set<PMNode>();
    before.forEach((n) => old.add(n));
    const out: { node: PMNode; pos: number }[] = [];
    after.forEach((node, pos) => {
        if (!old.has(node)) out.push({ node, pos });
    });

    return out;
}

export const DocumentLimits = Extension.create<{ onBlocked: (reason: 'table-size') => void }>({
    name: 'documentLimits',

    addOptions() {
        return { onBlocked: () => {} };
    },

    addProseMirrorPlugins() {
        return [new Plugin({
            filterTransaction: (tr, state) => {
                if (!tr.docChanged) return true;
                for (const { node } of changed(state.doc, tr.doc)) {
                    let fits = true;
                    node.descendants((n) => {
                        if (n.type.name === 'table' && !tableFits(n)) fits = false;
                        return fits;
                    });
                    if (!fits) {
                        this.options.onBlocked('table-size');
                        return false;
                    }
                }
                return true;
            },
            appendTransaction: (trs, before, state) => {
                if (!trs.some((t) => t.docChanged)) return null;
                let tr: Transaction | null = null;
                const removals: { from: number; to: number }[] = [];
                const visit = (n: PMNode, pos: number) => {
                    const attrs = sanitizeAttrs(n.type.name, n.attrs);
                    if (attrs === false) removals.push({ from: pos, to: pos + n.nodeSize });
                    else if (attrs) (tr ??= state.tr).setNodeMarkup(pos, undefined, attrs, n.marks);
                };
                for (const { node, pos } of changed(before.doc, state.doc)) {
                    visit(node, pos);
                    node.descendants((n, p) => visit(n, pos + 1 + p));
                }
                for (const r of removals.reverse()) (tr ??= state.tr).delete(r.from, r.to);

                return tr;
            },
        })];
    },
});
