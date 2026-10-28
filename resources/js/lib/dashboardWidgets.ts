import type { Component } from 'vue';

// Start page widgets. The server lists the keys per area (Home layout prop) and the data per key; this registry maps a
// key to its component. Modules register theirs with registerWidget(); an unknown key is skipped silently.

export type WidgetAreas = { top?: string[]; kpi?: string[]; main?: string[]; side?: string[] };

const components = new Map<string, Component>();

export const registerWidget = (key: string, component: Component) => void components.set(key, component);

export const widgetComponent = (key: string): Component | undefined => components.get(key);

export function visibleKeys(keys: string[] | undefined, widgets: Record<string, unknown>): string[] {
    return (keys ?? []).filter((key) => components.has(key) && widgets[key] != null);
}

export function dateLabel(iso: string, locale: string): string {
    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) return '';

    return new Intl.DateTimeFormat(locale, { weekday: 'long', day: 'numeric', month: 'long' }).format(date);
}

export const SETUP_DISMISSED_KEY = 'intra_setup_dismissed';

type Store = Pick<Storage, 'getItem' | 'setItem'>;

const store = (): Store | null => {
    try {
        return window.localStorage;
    } catch {
        return null;
    }
};

export function isSetupDismissed(storage: Store | null = store()): boolean {
    try {
        return storage?.getItem(SETUP_DISMISSED_KEY) === '1';
    } catch {
        return false;
    }
}

export function dismissSetup(storage: Store | null = store()): void {
    try {
        storage?.setItem(SETUP_DISMISSED_KEY, '1');
    } catch {
    }
}

const round = (n: number) => Math.round(n * 100) / 100;

export function sparkPath(values: number[], w: number, h: number): { line: string; area: string; end: [number, number] } {
    const max = Math.max(1, ...values);
    const y = (v: number) => round(h - (Math.max(0, v) / max) * h);
    const pts: [number, number][] =
        values.length < 2 ? [[0, y(values[0] ?? 0)], [w, y(values[0] ?? 0)]] : values.map((v, i) => [round((i / (values.length - 1)) * w), y(v)]);
    const line = pts.map(([px, py], i) => `${i ? 'L' : 'M'}${px} ${py}`).join('');
    const end = pts[pts.length - 1];

    return { line, area: `${line}L${end[0]} ${h}L${pts[0][0]} ${h}Z`, end };
}

export function since(iso: string, now: Date, locale: string): string {
    const at = new Date(iso).getTime();
    if (Number.isNaN(at)) return '';
    const minutes = Math.max(0, Math.floor((now.getTime() - at) / 60000));
    const en = locale.startsWith('en');
    if (minutes < 60) return `${minutes} min`;
    const hours = Math.floor(minutes / 60);
    if (hours < 48) return en ? `${hours} h` : `${hours} Std.`;

    return `${Math.floor(hours / 24)} ${en ? 'days' : 'Tagen'}`;
}

export type Tone = 'ok' | 'info' | 'warn' | 'danger';

export function fmsTone(status: string | null): Tone | undefined {
    return ({ 0: 'danger', 1: 'ok', 2: 'ok', 3: 'info', 4: 'info', 7: 'info', 8: 'info', 5: 'warn' } as Record<string, Tone>)[status ?? ''];
}
