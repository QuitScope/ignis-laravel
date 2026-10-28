import { afterEach, expect, it, vi } from 'vitest';
import { can, navGroups, quickActions } from '@/lib/navigation';
import {
    debounce,
    flatten,
    fold,
    isChoose,
    isMac,
    isPaletteShortcut,
    matchActions,
    modalOpen,
    moveActive,
    safeHref,
    searchUrl,
    sections,
    shouldSearch,
    type PaletteAction,
} from '@/lib/searchPalette';

afterEach(() => vi.useRealTimers());

it('debounces to the last call and can cancel it', () => {
    vi.useFakeTimers();
    const fn = vi.fn();
    const d = debounce(fn, 200);
    d.call('a');
    vi.advanceTimersByTime(150);
    d.call('b');
    vi.advanceTimersByTime(199);
    expect(fn).not.toHaveBeenCalled();
    vi.advanceTimersByTime(1);
    expect(fn).toHaveBeenCalledExactlyOnceWith('b');

    d.call('c');
    d.cancel();
    vi.advanceTimersByTime(500);
    expect(fn).toHaveBeenCalledTimes(1);
});

it('accepts only internal paths as targets', () => {
    for (const ok of ['/', '/personnel/3', '/personnel/document-view?docid=AAAA-BBBB-CCCC', '/vehicles/defects/1#x']) {
        expect(safeHref(ok)).toBe(ok);
    }
    for (const bad of ['//evil.example', '/\\evil.example', 'https://evil.example', 'javascript:alert(1)', 'personnel/3', '', '/a\nb', '/a\\b', null, 3, { href: '/' }]) {
        expect(safeHref(bad)).toBeNull();
    }
});

it('moves the active option with wrap-around and ignores other keys', () => {
    expect(moveActive(0, 3, 'ArrowDown')).toBe(1);
    expect(moveActive(2, 3, 'ArrowDown')).toBe(0);
    expect(moveActive(0, 3, 'ArrowUp')).toBe(2);
    expect(moveActive(1, 3, 'Home')).toBeNull();
    expect(moveActive(0, 3, 'End')).toBeNull();
    expect(moveActive(0, 3, 'Enter')).toBeNull();
    expect(moveActive(0, 0, 'ArrowDown')).toBeNull();
});

it('takes Ctrl+K elsewhere and only ⌘K on a Mac', () => {
    const e = { key: 'k', ctrlKey: false, metaKey: false, altKey: false, shiftKey: false };
    expect(isPaletteShortcut({ ...e, ctrlKey: true }, false)).toBe(true);
    expect(isPaletteShortcut({ ...e, metaKey: true }, false)).toBe(false);
    expect(isPaletteShortcut({ ...e, metaKey: true, key: 'K' }, true)).toBe(true);
    expect(isPaletteShortcut({ ...e, ctrlKey: true }, true)).toBe(false);
    expect(isPaletteShortcut(e, false)).toBe(false);
    expect(isPaletteShortcut({ ...e, ctrlKey: true, altKey: true }, false)).toBe(false);
    expect(isPaletteShortcut({ ...e, ctrlKey: true, key: 'j' }, false)).toBe(false);
    expect(isMac('MacIntel')).toBe(true);
    expect(isMac('Win32')).toBe(false);
});

it('asks the server from two characters, with the query encoded', () => {
    expect(shouldSearch(' a ')).toBe(false);
    expect(shouldSearch('ab')).toBe(true);
    expect(searchUrl('a&b c', 'all')).toBe('/api/system/global-search?q=a%26b+c&scope=all');
});

it('matches actions by every folded word, all for an empty query', () => {
    const actions: PaletteAction[] = [
        { label: 'Fahrzeuge', sub: 'Gehe zu', href: '/vehicles', keywords: 'gehe zu Fahrzeuge' },
        { label: 'Neues Fahrzeug anlegen', sub: 'Neu', href: '/vehicles/create', keywords: 'neu anlegen erstellen' },
        { label: 'Mängel', sub: 'Gehe zu', href: '/vehicles/defects', keywords: 'gehe zu' },
    ];
    expect(matchActions(actions, '')).toHaveLength(3);
    expect(matchActions(actions, 'fahrz neu').map((a) => a.href)).toEqual(['/vehicles/create']);
    expect(matchActions(actions, 'maengel').map((a) => a.href)).toEqual(['/vehicles/defects']);
    expect(fold('Müller Café ß')).toBe('mueller cafe ss');
});

it('builds sections with stable ids, flags approx and drops unsafe targets', () => {
    const list = sections([{ label: 'Fahrzeuge', sub: 'Gehe zu', href: '/vehicles', keywords: '' }], 'Aktionen', [
        { key: 'personnel', label: 'Mitarbeiter', items: [
            { label: 'Hans Müller', sub: 'Dienstnr. 1', href: '/personnel/1', approx: true },
            { label: 'Böse', sub: '', href: 'https://evil.example' },
        ] },
        { key: 'vehicles', label: 'Fahrzeuge', items: [{ label: 'X', sub: '', href: '//evil' }] },
    ]);

    expect(list.map((s) => s.key)).toEqual(['actions', 'r-personnel']);
    expect(flatten(list)).toEqual([
        { id: 'palette-actions-0', group: 'actions', label: 'Fahrzeuge', sub: 'Gehe zu', href: '/vehicles', approx: false },
        { id: 'palette-r-personnel-0', group: 'r-personnel', label: 'Hans Müller', sub: 'Dienstnr. 1', href: '/personnel/1', approx: true },
    ]);
});

it('derives navigation and quick actions from the same rights', () => {
    const user = (permissions: string[], is_super_admin = false) => ({ permissions, is_super_admin });

    expect(navGroups(null)).toEqual([]);
    expect(navGroups(user([])).map((g) => g.key)).toEqual(['start']);
    expect(navGroups(user(['vehicles.manage'])).find((g) => g.key === 'admin')?.items.map((i) => i.href)).toEqual(['/settings']);
    expect(navGroups(user(['admin'])).map((g) => g.key)).toEqual(['start', 'personnel', 'vehicles', 'admin']);
    expect(navGroups(user(['dashboard.manage'])).some((g) => g.key === 'vehicles')).toBe(false);
    expect(navGroups(user([])).find((g) => g.key === 'start')?.items.map((i) => i.href)).toEqual(['/']);
    expect(navGroups(user([], true))).toEqual(navGroups(user(['admin'])));

    expect(quickActions(user(['vehicles.view'])).map((a) => a.href)).toEqual(['/vehicles/defects/create']);
    expect(quickActions(user(['personnel.edit', 'personnel.documents.manage'])).map((a) => a.href)).toEqual([
        '/personnel/create',
        '/settings/documents/editor-templates/create',
    ]);
    expect(can(user(['x']), 'y')).toBe(false);
});

it('ignores Enter while an input method composes', () => {
    expect(isChoose({ key: 'Enter', isComposing: false })).toBe(true);
    expect(isChoose({ key: 'Enter', isComposing: true })).toBe(false);
    expect(isChoose({ key: 'Tab', isComposing: false })).toBe(false);
});

it('sees another open modal dialog', () => {
    const root = document.createElement('div');
    expect(modalOpen(root)).toBe(false);
    root.innerHTML = '<div role="dialog"></div>';
    expect(modalOpen(root)).toBe(false);
    root.innerHTML = '<div role="dialog" aria-modal="true"></div>';
    expect(modalOpen(root)).toBe(true);
    root.innerHTML = '<dialog open></dialog>';
    expect(modalOpen(root)).toBe(true);
});
