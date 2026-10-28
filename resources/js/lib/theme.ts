export type ThemeMode = 'dark' | 'light' | 'system';
export const THEMES: readonly ThemeMode[] = ['dark', 'light', 'system'];

export function resolveTheme(theme: unknown, prefersDark: boolean): 'dark' | 'light' {
    if (theme === 'light') return 'light';
    if (theme === 'system') return prefersDark ? 'dark' : 'light';
    return 'dark';
}

let stop: (() => void) | null = null;
let paint: (() => void) | null = null;
let forcedDark = false;

export function forceDark(on: boolean): void {
    forcedDark = on;
    if (typeof document !== 'undefined') document.documentElement.toggleAttribute('data-force-dark', on);
    if (paint) paint();
    else if (on && typeof document !== 'undefined') document.documentElement.dataset.theme = 'dark';
}

export function applyTheme(theme: unknown): void {
    stop?.();
    stop = null;
    if (typeof document === 'undefined') return;

    const mq = typeof window !== 'undefined' && typeof window.matchMedia === 'function' ? window.matchMedia('(prefers-color-scheme: dark)') : null;
    const root = document.documentElement;
    const mode = (THEMES as readonly unknown[]).includes(theme) ? (theme as ThemeMode) : 'dark';
    const repaint = () => {
        root.dataset.themeMode = mode;
        root.dataset.theme = forcedDark ? 'dark' : resolveTheme(mode, mq?.matches ?? true);
    };
    paint = repaint;
    repaint();

    if (mode === 'system' && mq) {
        mq.addEventListener('change', repaint);
        stop = () => mq.removeEventListener('change', repaint);
    }
}

export function shouldRollback(visit: { cancelled?: boolean; interrupted?: boolean } | undefined): boolean {
    return !(visit?.cancelled || visit?.interrupted);
}
