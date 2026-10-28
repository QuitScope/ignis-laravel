import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { applyTheme, resolveTheme, shouldRollback } from '@/lib/theme';
import { applyPageSkin, pageSkin } from '@/lib/pageSkin';

type Listener = () => void;
let dark = false;
let listeners: Set<Listener>;

beforeEach(() => {
    listeners = new Set();
    vi.stubGlobal('matchMedia', () => ({
        get matches() {
            return dark;
        },
        addEventListener: (_: string, l: Listener) => listeners.add(l),
        removeEventListener: (_: string, l: Listener) => listeners.delete(l),
    }));
});
afterEach(() => {
    applyTheme('dark'); // drops a leftover listener
    vi.unstubAllGlobals();
    dark = false;
});

it('resolves the three modes, anything else is dark', () => {
    const table: [unknown, boolean, string][] = [
        ['dark', false, 'dark'], ['dark', true, 'dark'], ['light', true, 'light'], ['light', false, 'light'],
        ['system', true, 'dark'], ['system', false, 'light'],
        ['Dark', false, 'dark'], ['', false, 'dark'], [null, false, 'dark'], [undefined, true, 'dark'], [['light'], false, 'dark'],
    ];
    for (const [theme, prefersDark, want] of table) expect(resolveTheme(theme, prefersDark)).toBe(want);
});

it('sets data-theme and keeps the chosen mode', () => {
    applyTheme('light');
    expect(document.documentElement.dataset.theme).toBe('light');
    expect(document.documentElement.dataset.themeMode).toBe('light');
    expect(listeners.size).toBe(0);
});

it('follows the browser only in system mode and listens exactly once', () => {
    dark = true;
    applyTheme('system');
    applyTheme('system'); // idempotent
    expect(document.documentElement.dataset.theme).toBe('dark');
    expect(listeners.size).toBe(1);

    dark = false;
    listeners.forEach((l) => l());
    expect(document.documentElement.dataset.theme).toBe('light');
    expect(document.documentElement.dataset.themeMode).toBe('system');

    applyTheme('dark');
    expect(listeners.size).toBe(0);
    expect(document.documentElement.dataset.theme).toBe('dark');
});

it('does nothing without a document or matchMedia', () => {
    vi.stubGlobal('matchMedia', undefined);
    expect(() => applyTheme('system')).not.toThrow();
    expect(document.documentElement.dataset.theme).toBe('dark');
    vi.stubGlobal('document', undefined);
    expect(() => applyTheme('light')).not.toThrow();
});

it('rolls back after a finished visit but not after a cancelled or interrupted one', () => {
    expect(shouldRollback({})).toBe(true);
    expect(shouldRollback({ cancelled: false, interrupted: false })).toBe(true);
    expect(shouldRollback(undefined)).toBe(true);
    expect(shouldRollback({ cancelled: true })).toBe(false);
    expect(shouldRollback({ interrupted: true })).toBe(false);
});

it('keeps the old token set on tablet pages and pins the sign-in page to dark', () => {
    expect(pageSkin('Enotf/Overview')).toEqual({ skin: 'classic', dark: false });
    expect(pageSkin('Install/Index').skin).toBe('classic');
    expect(pageSkin('Enotf/Admin/Index')).toEqual({ skin: 'core', dark: false });
    expect(pageSkin('FireTab/Qm/Show').skin).toBe('core');
    expect(pageSkin('Auth/Login')).toEqual({ skin: 'core', dark: true });

    applyTheme('light');
    applyPageSkin('Auth/Login');
    expect(document.documentElement.dataset).toMatchObject({ skin: 'core', theme: 'dark', themeMode: 'light' });
    applyTheme('light'); // the navigate handler repaints after the skin
    expect(document.documentElement.dataset.theme).toBe('dark');
    applyPageSkin('FireTab/List');
    expect(document.documentElement.dataset).toMatchObject({ skin: 'classic', theme: 'light' });
});
