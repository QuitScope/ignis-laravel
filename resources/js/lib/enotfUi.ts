/**
 * eNOTF protocol UI: pure logic of the tile widgets and the page frame. The server schema stays the source of
 * truth (FieldSchema, enotfCatalogs.json, enotfConditions.ts); this file only groups and operates.
 */
import { trans } from 'laravel-vue-i18n';
import { inject, onBeforeUnmount, ref, type Ref, watch } from 'vue';
import { toWall } from '@/lib/calendar';
import catalogData from '@/lib/enotfCatalogs.json';
import { activeRules, filled, legacy, REA_DETAILS, transportziel as tzOf } from '@/lib/enotfConditions';
import layout from '@/lib/enotfLayout.json';

export type Code = number | string;
export type Fill = 'green' | 'yellow' | 'red' | null;
type Values = Record<string, unknown>;

export interface EnotfForm {
    values: Values;
    set(key: string, value: unknown): void;
    flush(): Promise<void>;
    readonly: boolean;
    invalid: string[];
    conflicts: string[];
    clock: Clock;
    timezone: string;
    enr: string;
    vitalMax: Record<string, number>;
}

export const fieldMark = (form: Pick<EnotfForm, 'invalid' | 'conflicts'>, ...keys: string[]): 'invalid' | 'conflict' | null =>
    keys.some((k) => form.invalid.includes(k)) ? 'invalid' : keys.some((k) => form.conflicts.includes(k)) ? 'conflict' : null;

const sameValue = (a: unknown, b: unknown) => JSON.stringify(a ?? null) === JSON.stringify(b ?? null);

export function useFocusDraft<D extends Record<string, unknown>>(key: () => string | null, draft: D, snapshot: () => D, prefill?: (start: D) => Partial<D>) {
    const form = useEnotfForm();
    let start = {} as D;
    const storageKey = (k: string) => `enotf-focus:${form.enr}:${k}`;
    const drop = (k: string | null | undefined) => {
        try {
            if (k) window.sessionStorage.removeItem(storageKey(k));
        } catch {
        }
    };
    const open = (k: string) => {
        start = snapshot();
        let kept: Partial<D> = {};
        try {
            const raw = JSON.parse(window.sessionStorage.getItem(storageKey(k)) ?? 'null') as unknown;
            if (raw && typeof raw === 'object' && !Array.isArray(raw)) kept = Object.fromEntries(Object.entries(raw).filter(([f]) => f in start)) as Partial<D>;
        } catch {
        }
        Object.assign(draft, start, prefill?.(start) ?? {}, kept);
    };
    watch(key, (k, old) => {
        drop(old);
        if (k) open(k);
    }, { immediate: true });
    watch(draft, () => {
        const k = key();
        if (!k) return;
        const edits = Object.fromEntries(Object.entries(draft).filter(([f, v]) => !sameValue(v, start[f])));
        try {
            if (Object.keys(edits).length) window.sessionStorage.setItem(storageKey(k), JSON.stringify(edits));
            else drop(k);
        } catch {
        }
    }, { deep: true });
    onBeforeUnmount(() => drop(key()));
    return {
        start: () => start,
        changed: (f: keyof D) => !sameValue(draft[f], start[f]),
        reset: () => {
            const k = key();
            drop(k);
            if (k) open(k);
        },
    };
}

export const PAGES = layout.pages;

export const catalog = (name: string): [Code, string][] => (catalogData as unknown as Record<string, [Code, string][]>)[name] ?? [];

export function tileState(requires: string[], values: Values, transportziel?: number | null): Fill {
    const d = legacy(values);
    const tz = transportziel === undefined ? tzOf(d) : transportziel;
    const rules = activeRules(tz);
    const active = new Set(Object.values(rules).flatMap((r) => r[2]));
    const cols = requires.filter((c) => active.has(c));
    if (requires.includes('rea_details') && rules.rea_details && Number.parseInt(String(d.rea_status ?? ''), 10) === 2) cols.push(...REA_DETAILS.filter((c) => !cols.includes(c)));
    if (cols.length === 0) return null;
    const n = cols.filter((c) => filled(c, d[c])).length;
    return n === cols.length ? 'green' : n > 0 ? 'yellow' : 'red';
}

export const pageStatus = (values: Values): Record<string, Fill> => Object.fromEntries(PAGES.map((p) => [p.slug, tileState(p.keys, values)]));

export const pageOf: Record<string, string> = Object.fromEntries(PAGES.flatMap((p) => p.keys.map((k) => [k, p.slug])));

export function toggleExclusive(list: Code[] | null | undefined, code: Code, exclusive: Code[] = []): Code[] | null {
    const cur = list ?? [];
    const next = cur.includes(code) ? cur.filter((c) => c !== code) : exclusive.includes(code) ? [code] : [...cur.filter((c) => !exclusive.includes(c)), code];
    return next.length ? next : null;
}

export interface Clock {
    now(): Date;
    sync(serverNow: string | null | undefined): void;
}
export function createClock(serverNow: string): Clock {
    const measure = (iso: string | null | undefined) => Date.parse(iso ?? '') - Date.now();
    let offset = measure(serverNow);
    if (Number.isNaN(offset)) offset = 0;
    return {
        now: () => new Date(Date.now() + offset),
        sync(iso) {
            const next = measure(iso);
            if (!Number.isNaN(next) && Math.abs(next - offset) > 2000) offset = next;
        },
    };
}

export function parseWizard(search: string): { topic: string | null; step: number | null } {
    const p = new URLSearchParams(search);
    const q = p.get('q');
    return { topic: p.get('t') || null, step: q !== null && /^\d+$/.test(q) ? Number(q) : null };
}

export function useWizard() {
    const start = parseWizard(window.location.search);
    const topic = ref(start.topic);
    const step = ref(start.step);
    const open = (t: string | null, q?: number | null) => {
        topic.value = t;
        step.value = t ? (q ?? null) : null;
        const url = new URL(window.location.href);
        url.search = '';
        if (t) url.searchParams.set('t', t);
        if (t && q) url.searchParams.set('q', String(q));
        try {
            window.history.replaceState(window.history.state, '', url.pathname + url.search);
        } catch {
        }
    };
    return { topic, step, open };
}
export type Wizard = ReturnType<typeof useWizard>;

export const useEnotfForm = (): EnotfForm => inject<EnotfForm>('enotfForm') as EnotfForm;
export const useEnotfWizard = (): Wizard => inject<Wizard>('enotfWizard') as Wizard;

const pad = (n: number | string) => String(n).padStart(2, '0');

export function parseDate(input: string, today: Date = new Date()): string | null {
    const v = input.trim();
    let m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(v);
    let [d, mo, y] = m ? [m[3], m[2], m[1]] : ['', '', ''];
    if (!m) {
        m = /^(\d{1,2})\.(\d{1,2})\.(\d{2}|\d{4})$/.exec(v) ?? /^(\d{2})(\d{2})(\d{4}|\d{2})?$/.exec(v);
        if (!m) return null;
        [d, mo, y] = [m[1], m[2], m[3] ?? String(today.getFullYear())];
    }
    if (y.length === 2) y = (Number(y) <= today.getFullYear() % 100 ? '20' : '19') + y;
    const date = new Date(Date.UTC(Number(y), Number(mo) - 1, Number(d)));
    const iso = `${y}-${pad(mo)}-${pad(d)}`;
    return Number(y) >= 1900 && date.toISOString().slice(0, 10) === iso ? iso : null;
}

export const formatDate = (iso: string | null | undefined): string => (iso && /^\d{4}-\d{2}-\d{2}/.test(iso) ? `${iso.slice(8, 10)}.${iso.slice(5, 7)}.${iso.slice(0, 4)}` : (iso ?? ''));

export function parseTime(input: string): string | null {
    const m = /^(\d{1,2})(?::?(\d{2}))?$/.exec(input.trim());
    if (!m || Number(m[1]) > 23 || Number(m[2] ?? 0) > 59) return null;
    return `${pad(m[1])}:${m[2] ?? '00'}`;
}

export const nowWall = (clock: Clock, tz: string): string => toWall(clock.now(), tz).slice(0, 16);

export const wallOf = (v: unknown, tz: string): string | null =>
    typeof v !== 'string' || v === '' ? null : /(Z|[+-]\d{2}:?\d{2})$/.test(v) ? toWall(v, tz).slice(0, 16) : v.replace(' ', 'T').slice(0, 16);

export function gcsSum(a: unknown, v: unknown, m: unknown): number | null {
    const parts = [a, v, m].map((x) => (x === null || x === undefined || x === '' ? NaN : Number(x)));
    return parts.some(Number.isNaN) ? null : 4 - parts[0] + (5 - parts[1]) + (6 - parts[2]);
}

export function severityWrites(field: string, current: unknown, code: number): Record<string, number | null> {
    const next = String(current ?? '') === String(code) ? null : code; // tapping the chosen one again clears
    return next === null || next === 1 ? { [field]: next, [`${field}1`]: null } : { [field]: next };
}

/** Injury summary of a region, "leicht / offen" (legacy overview). */
export function injuryText(severity: unknown, type: unknown): string {
    const s = catalog('v_muster_schwere').find(([c]) => String(c) === String(severity))?.[1];
    const t = catalog('v_muster_art').find(([c]) => String(c) === String(type))?.[1];
    return [s, t].filter(Boolean).join(' / ').toLowerCase();
}

export function ageOn(birth: unknown, today: string): number | null {
    if (typeof birth !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(birth) || birth > today) return null;
    const age = Number(today.slice(0, 4)) - Number(birth.slice(0, 4));
    return today.slice(5) < birth.slice(5) ? age - 1 : age;
}

export type Address = { strasse?: string | null; hnr?: string | null; ort?: string | null; ortsteil?: string | null };

export function addressLine(a: unknown): string {
    if (!a || typeof a !== 'object') return '';
    const v = a as Address;
    const join = (parts: (string | null | undefined)[], sep: string) => parts.filter((p) => p && p.trim()).join(sep);
    return join([join([v.strasse, v.hnr], ' '), join([v.ort, v.ortsteil], '-')], ', ');
}

export interface FieldDef {
    key: string;
    type: 'text' | 'int' | 'decimal' | 'bool' | 'date' | 'time' | 'datetime' | 'enum' | 'json';
    max: number | null;
    catalog: string | null;
    shape: 'ints' | 'object' | 'objects' | null;
    keys: string[];
    key_catalogs: Record<string, string> | [];
}

export const emptyValue = (v: unknown) => v === null || v === undefined || v === '' || (Array.isArray(v) && v.length === 0);
const codeText = (name: string | null | undefined, v: unknown): string => (name ? catalog(name).find(([c]) => String(c) === String(v))?.[1] : undefined) ?? String(v);
const objText = (f: FieldDef, o: Record<string, unknown>) =>
    Object.entries(o)
        .filter(([, v]) => !emptyValue(v))
        .map(([k, v]) => codeText(Array.isArray(f.key_catalogs) ? null : f.key_catalogs[k], v))
        .join(', ');

export function fieldText(f: FieldDef, v: unknown): string {
    if (f.type === 'bool') return trans(v ? 'enotf.print.yes' : 'enotf.print.no');
    if (f.type === 'enum') return codeText(f.catalog, v);
    if (f.type === 'datetime' && typeof v === 'string') return v.replace('T', ' ');
    if (f.type === 'json' && Array.isArray(v)) return v.map((x) => (x && typeof x === 'object' ? objText(f, x as Record<string, unknown>) : codeText(f.catalog, x))).join('; ');
    if (f.type === 'json' && v && typeof v === 'object') return objText(f, v as Record<string, unknown>);
    return String(v);
}

export const placeLine = (poi: unknown, address: unknown): string =>
    [typeof poi === 'string' ? poi.trim() : '', addressLine(address)].filter(Boolean).join(', ');

export function insertAt(value: string, cursor: number, text: string, mode: number): { value: string; cursor: number } {
    const before = value.slice(0, cursor);
    const prefix = mode >= 1 && before.length > 0 ? (mode >= 3 ? '\n\n' : '\n') : '';
    const insert = prefix + text + (mode >= 2 ? '\n' : '');
    return { value: before + insert + value.slice(cursor), cursor: cursor + insert.length };
}

type DiagCat = { label: string; cols?: number[][]; subs?: { label: string; codes: number[] }[] };
export const DIAG_TREE = layout.diagnose_tree as DiagCat[];
type DiagStep = { cat: number; sub: string | null; cols: number[][] };
export const DIAG_STEPS: DiagStep[] = DIAG_TREE.flatMap((c, cat): DiagStep[] =>
    c.subs ? c.subs.map((s) => ({ cat, sub: s.label, cols: [s.codes] })) : [{ cat, sub: null, cols: c.cols ?? [] }],
);
export const diagSteps = (cat: number): number[] => DIAG_STEPS.flatMap((s, i) => (s.cat === cat ? [i + 1] : []));

export function diagLabel(code: Code, short = false): string {
    const label = catalog('diagnose').find(([c]) => String(c) === String(code))?.[1] ?? String(code);
    return short && Number(code) >= 101 && Number(code) <= 193 && label.startsWith('Trauma ') ? label.slice(label.lastIndexOf(' ') + 1) : label;
}

export function diagSearch(term: string): { code: number; label: string; q: number }[] {
    const t = term.trim().toLowerCase();
    if (!t) return [];
    return DIAG_STEPS.flatMap((s, i) => s.cols.flat().map((code) => ({ code, label: diagLabel(code), q: i + 1 })))
        .filter((h) => h.label.toLowerCase().includes(t))
        .slice(0, 20);
}

export type Zugang = { art: string; groesse: string | null; ort: string | null; seite: string | null };
const zKey = (z: Zugang) => `${z.art}|${z.ort ?? ''}|${z.seite ?? ''}`;

export function zugangToggle(list: unknown, entry: Zugang): Zugang[] {
    const cur = Array.isArray(list) ? (list as Zugang[]) : [];
    const same = cur.find((z) => zKey(z) === zKey(entry));
    const rest = cur.filter((z) => zKey(z) !== zKey(entry));
    return same?.groesse === entry.groesse ? rest : [...rest, entry];
}

export function zugangText(list: unknown, art: string, none: string, noneOfArt: string): string {
    if (!Array.isArray(list)) return '';
    if (list.length === 0) return none;
    const label = (cat: string, code: string | null) => catalog(cat).find(([c]) => c === code)?.[1] ?? code ?? '';
    const rows = (list as Zugang[]).filter((z) => z.art === art).sort((a, b) => zKey(a).localeCompare(zKey(b)));
    if (!rows.length) return noneOfArt;
    return rows.map((z) => [label('zugang_art', z.art), label('zugang_groesse', z.groesse).replace(/^(\d+)mm$/, '$1 mm'), z.ort, z.seite].filter(Boolean).join(' ')).join('\n');
}

export function medTime(time: string | null, nowWallValue: string): string | null {
    if (!time) return null;
    const day = nowWallValue.slice(0, 10);
    const later = Date.parse(`${day}T${time}:00Z`) - Date.parse(`${nowWallValue.slice(0, 16)}:00Z`) > 5 * 60000;
    const d = later ? new Date(Date.parse(`${day}T00:00:00Z`) - 86400000).toISOString().slice(0, 10) : day;
    return `${d} ${time}`;
}

export function parseDose(text: string): { dose: string; unit: string } | null {
    const m = /^(\d+(?:[.,]\d+)?)\s*(mcg|μg|µg|ug|mg|g|ml|ie)$/i.exec(text.trim());
    if (!m) return null;
    const u = m[2].toLowerCase();
    return { dose: m[1], unit: ['mcg', 'μg', 'µg', 'ug'].includes(u) ? 'mcg' : u === 'ie' ? 'IE' : u };
}

export function usePanel(anchor: Ref<HTMLElement | null>, panel: Ref<HTMLElement | null>) {
    const open = ref(false);
    const style = ref<Record<string, string>>({});
    const outside = (e: Event) => {
        const t = e.target as Node;
        if (!anchor.value?.contains(t) && !panel.value?.contains(t)) close();
    };
    const close = () => {
        open.value = false;
        document.removeEventListener('pointerdown', outside, true);
    };
    const show = () => {
        const r = anchor.value?.getBoundingClientRect();
        if (!r) return;
        const below = window.innerHeight - r.bottom >= Math.min(320, r.top);
        style.value = { left: `${r.left}px`, width: `${Math.max(r.width, 220)}px`, ...(below ? { top: `${r.bottom}px` } : { bottom: `${window.innerHeight - r.top}px` }) };
        open.value = true;
        document.addEventListener('pointerdown', outside, true);
    };
    onBeforeUnmount(close);
    return { open, style, show, close };
}

export function reaPanel(status: unknown): 'details' | 'nicht' | null {
    const s = Number(status);
    return s === 2 ? 'details' : s >= 3 && s <= 6 ? 'nicht' : null;
}

export const naNachfShown = (protBy: unknown): boolean => Number(protBy ?? 0) !== 1;

export const printTitleKey = (protBy: unknown): string => `enotf.print.title.${naNachfShown(protBy) ? 'transport' : 'na'}`;

export function releaseBlock(o: { readonly: boolean; canRelease: boolean; open: number | null }): 'readonly' | 'forbidden' | 'open' | 'unchecked' | null {
    if (o.readonly) return 'readonly';
    if (!o.canRelease) return 'forbidden';
    if (o.open === null) return 'unchecked';
    return o.open > 0 ? 'open' : null;
}
