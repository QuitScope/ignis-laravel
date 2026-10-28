// Pure logic of the command palette (components/search/CommandPalette.vue); contract of
// GET /api/system/global-search.

export type SearchItem = { label: string; sub: string; href: string; approx?: boolean };
export type SearchGroup = { key: string; label: string; items: SearchItem[] };
export type SearchScope = { key: string; label: string };
export type SearchResponse = { q: string; results: SearchGroup[]; scopes: SearchScope[] };

export type PaletteAction = { label: string; sub: string; href: string; keywords: string };

export type PaletteOption = { id: string; group: string; label: string; sub: string; href: string; approx: boolean };
export type PaletteSection = { key: string; label: string; options: PaletteOption[] };

export const DEBOUNCE_MS = 200;
export const MIN_QUERY = 2;
const MAX_ACTIONS = 8;

export function debounce<A extends unknown[]>(fn: (...args: A) => void, ms = DEBOUNCE_MS): { call: (...args: A) => void; cancel: () => void } {
    let timer: ReturnType<typeof setTimeout> | undefined;

    return {
        call: (...args: A) => {
            clearTimeout(timer);
            timer = setTimeout(() => fn(...args), ms);
        },
        cancel: () => clearTimeout(timer),
    };
}

/** Only an internal path: one leading slash, no `//` or `/\` (other host), no control characters. Else null. */
export function safeHref(href: unknown): string | null {
    return typeof href === 'string' && /^\/(?![/\\])[^\u0000-\u001f\u007f\\]*$/.test(href) ? href : null;
}

export function fold(s: string): string {
    return s
        .toLowerCase()
        .replace(/ä/g, 'ae')
        .replace(/ö/g, 'oe')
        .replace(/ü/g, 'ue')
        .replace(/ß/g, 'ss')
        .normalize('NFD')
        .replace(/\p{Mn}/gu, '');
}

export function matchActions(actions: PaletteAction[], query: string): PaletteAction[] {
    const words = fold(query).split(/\s+/).filter(Boolean);
    if (words.length === 0) return actions;
    const hits = actions.filter((a) => {
        const text = fold(`${a.label} ${a.sub} ${a.keywords}`);

        return words.every((w) => text.includes(w));
    });

    return hits.slice(0, MAX_ACTIONS);
}

export function sections(actions: PaletteAction[], actionsLabel: string, groups: SearchGroup[]): PaletteSection[] {
    const out: PaletteSection[] = [];
    const add = (key: string, label: string, items: { label: string; sub: string; href: string; approx?: boolean }[]) => {
        const options = items.flatMap((it, i) => {
            const href = safeHref(it.href);

            return href === null ? [] : [{ id: `palette-${key}-${i}`, group: key, label: it.label, sub: it.sub, href, approx: it.approx === true }];
        });
        if (options.length > 0) out.push({ key, label, options });
    };
    add('actions', actionsLabel, actions);
    for (const g of groups) add(`r-${g.key}`, g.label, g.items);

    return out;
}

export function flatten(list: PaletteSection[]): PaletteOption[] {
    return list.flatMap((s) => s.options);
}

export function moveActive(index: number, count: number, key: string): number | null {
    if (count === 0) return null;
    if (key === 'ArrowDown') return (index + 1) % count;
    if (key === 'ArrowUp') return (index - 1 + count) % count;

    return null;
}

export function isPaletteShortcut(e: { key: string; ctrlKey: boolean; metaKey: boolean; altKey: boolean; shiftKey: boolean }, mac: boolean): boolean {
    const modifier = mac ? e.metaKey && !e.ctrlKey : e.ctrlKey && !e.metaKey;

    return modifier && !e.altKey && !e.shiftKey && e.key.toLowerCase() === 'k';
}

export function isChoose(e: { key: string; isComposing: boolean }): boolean {
    return e.key === 'Enter' && !e.isComposing;
}

export function modalOpen(root: ParentNode): boolean {
    return root.querySelector('[role="dialog"][aria-modal="true"], [role="alertdialog"], dialog[open]') !== null;
}

export function isMac(platform: string): boolean {
    return /mac|iphone|ipad/i.test(platform);
}

export function searchUrl(query: string, scope: string): string {
    return `/api/system/global-search?${new URLSearchParams({ q: query, scope })}`;
}

export function shouldSearch(query: string): boolean {
    return query.trim().length >= MIN_QUERY;
}
