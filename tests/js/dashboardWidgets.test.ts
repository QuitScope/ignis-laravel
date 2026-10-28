import { expect, it } from 'vitest';
import { dateLabel, dismissSetup, isSetupDismissed, registerWidget, SETUP_DISMISSED_KEY, sparkPath, visibleKeys } from '@/lib/dashboardWidgets';

const memory = () => {
    const data = new Map<string, string>();

    return { getItem: (k: string) => data.get(k) ?? null, setItem: (k: string, v: string) => void data.set(k, v) };
};

it('keeps keys that have a component and data, in the order the server sent them', () => {
    registerWidget('a', {});
    registerWidget('b', {});

    expect(visibleKeys(['b', 'unknown', 'a', 'c'], { a: { x: 1 }, b: { y: 2 }, unknown: {}, c: {} })).toEqual(['b', 'a']);
    expect(visibleKeys(['a', 'b'], { a: null, b: undefined })).toEqual([]);
    expect(visibleKeys(undefined, {})).toEqual([]);
});

it('lets a module add a widget by registering a component', () => {
    expect(visibleKeys(['later'], { later: { n: 1 } })).toEqual([]);
    registerWidget('later', {});
    expect(visibleKeys(['later'], { later: { n: 1 } })).toEqual(['later']);
});

it('remembers the dismissed checklist under the legacy key', () => {
    const store = memory();

    expect(isSetupDismissed(store)).toBe(false);
    dismissSetup(store);
    expect(store.getItem(SETUP_DISMISSED_KEY)).toBe('1');
    expect(SETUP_DISMISSED_KEY).toBe('intra_setup_dismissed');
    expect(isSetupDismissed(store)).toBe(true);
});

it('survives missing or throwing storage', () => {
    const broken = {
        getItem: () => {
            throw new Error('blocked');
        },
        setItem: () => {
            throw new Error('blocked');
        },
    };

    expect(isSetupDismissed(null)).toBe(false);
    expect(isSetupDismissed(broken)).toBe(false);
    expect(() => dismissSetup(broken)).not.toThrow();
    expect(() => dismissSetup(null)).not.toThrow();
});

it('formats the header date in the app language and survives garbage', () => {
    expect(dateLabel('2026-10-01T12:00:00+00:00', 'de')).toBe('Donnerstag, 1. Oktober');
    expect(dateLabel('2026-10-01T12:00:00+00:00', 'en')).toMatch(/Thursday/);
    expect(dateLabel('nope', 'de')).toBe('');
});

it('draws the sparkline from zero with evenly spaced points and at least 1 as the top', () => {
    expect(sparkPath([0, 2, 1], 100, 40)).toEqual({ line: 'M0 40L50 0L100 20', area: 'M0 40L50 0L100 20L100 40L0 40Z', end: [100, 20] });
    expect(sparkPath([0, 0], 100, 40).end).toEqual([100, 40]);
    expect(sparkPath([3], 100, 40)).toMatchObject({ line: 'M0 0L100 0', end: [100, 0] });
    expect(sparkPath([], 100, 40).end).toEqual([100, 40]);
});
