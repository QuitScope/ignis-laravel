import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it, vi } from 'vitest';
import {
    absentNames,
    addDays,
    addMinutes,
    blankRecurrence,
    buildRule,
    colorClass,
    COLOR_VARS,
    COLORS,
    filterItems,
    formatRange,
    isoWeekday,
    mapFeedItem,
    parseRule,
    rangeParams,
    timeError,
    toInputValue,
    toWall,
    wallNow,
    withStartWeekday,
    type FeedItem,
} from '@/lib/calendar';
import { fieldErrors } from '@/lib/calendarApi';

const item = (over: Partial<FeedItem> = {}): FeedItem => ({
    id: 7, occurrence_start: '2026-11-10T08:00:00Z', occurrence_end: '2026-11-10T10:00:00Z', all_day: false, title: 'Übung', color: 'green', category: 'training',
    location: null, attendee_count: null, my_response: null, can_edit: true, recurring: false, source_locked: false, visibility: 'all', ...over,
});

describe('zone helpers', () => {
    it('converts instants to the organisation wall clock, also across DST', () => {
        expect(toWall('2026-11-10T08:00:00Z', 'Europe/Berlin')).toBe('2026-11-10T09:00:00');
        expect(toWall('2026-07-10T08:00:00Z', 'Europe/Berlin')).toBe('2026-07-10T10:00:00');
        expect(toWall('2026-11-10T23:30:00Z', 'Europe/Berlin')).toBe('2026-11-11T00:30:00');
        expect(toWall('2026-11-10T00:00:00Z', 'UTC')).toBe('2026-11-10T00:00:00');
    });

    it('handles DST transitions', () => {
        expect(toWall('2026-10-25T00:30:00Z', 'Europe/Berlin')).toBe('2026-10-25T02:30:00');
        expect(toWall('2026-10-25T01:30:00Z', 'Europe/Berlin')).toBe('2026-10-25T02:30:00');
        expect(toWall('2026-03-29T00:59:00Z', 'Europe/Berlin')).toBe('2026-03-29T01:59:00');
        expect(toWall('2026-03-29T01:00:00Z', 'Europe/Berlin')).toBe('2026-03-29T03:00:00');
    });

    it('wallNow follows a fixed clock in the zone', () => {
        vi.useFakeTimers({ toFake: ['Date'] });
        vi.setSystemTime(new Date('2026-11-10T23:30:00Z'));
        expect(wallNow('Europe/Berlin').toISOString()).toBe('2026-11-11T00:30:00.000Z');
        vi.useRealTimers();
    });

    it('does simple date arithmetic without the browser zone', () => {
        expect(addDays('2026-12-31', 1)).toBe('2027-01-01');
        expect(addDays('2026-03-01', -1)).toBe('2026-02-28');
        expect(addMinutes('2026-11-10T23:30', 60)).toBe('2026-11-11T00:30');
        expect(isoWeekday('2026-11-09')).toBe(1);
        expect(isoWeekday('2026-11-15')).toBe(7);
    });

    it('builds the feed window from the visible range as bare dates', () => {
        expect(rangeParams(new Date('2026-10-26T00:00:00Z'), new Date('2026-12-07T00:00:00Z'))).toEqual({ from: '2026-10-26', to: '2026-12-07' });
    });

    it('formats ranges in the organisation zone, 24h', () => {
        expect(formatRange('2026-11-10T08:00:00Z', '2026-11-10T10:00:00Z', false, 'Europe/Berlin', 'de')).toBe('10.11.2026, 09:00 – 11:00');
        expect(formatRange('2026-11-10', '2026-11-10', true, 'Europe/Berlin', 'de')).toBe('10.11.2026');
        expect(formatRange('2026-11-10', '2026-11-12', true, 'Europe/Berlin', 'de')).toBe('10.11.2026 – 12.11.2026');
        expect(formatRange('2026-11-10T22:30:00Z', '2026-11-11T07:00:00Z', false, 'Europe/Berlin', 'de')).toContain('11.11.2026');
    });

    it('gives input values: date for all-day, local datetime otherwise', () => {
        expect(toInputValue('2026-11-10', true, 'Europe/Berlin')).toBe('2026-11-10');
        expect(toInputValue('2026-11-10T08:00:00Z', false, 'Europe/Berlin')).toBe('2026-11-10T09:00');
    });
});

describe('feed mapping', () => {
    it('shows timed items in wall time', () => {
        const e = mapFeedItem(item(), 'Europe/Berlin');
        expect(e).toMatchObject({ id: '7-2026-11-10T08:00:00Z', start: '2026-11-10T09:00:00', end: '2026-11-10T11:00:00', allDay: false, editable: false });
        expect(e.classNames).toEqual(['cal-c-green']);
    });

    it('makes the inclusive all-day end exclusive (+1 day), also over month and year edges', () => {
        expect(mapFeedItem(item({ all_day: true, occurrence_start: '2026-11-10', occurrence_end: '2026-11-10' }), 'Europe/Berlin')).toMatchObject({ start: '2026-11-10', end: '2026-11-11', allDay: true });
        expect(mapFeedItem(item({ all_day: true, occurrence_start: '2026-12-30', occurrence_end: '2026-12-31' }), 'Europe/Berlin').end).toBe('2027-01-01');
    });

    it('marks locked absences read-only with their own class', () => {
        const e = mapFeedItem(item({ source_locked: true, all_day: true, occurrence_start: '2026-11-10', occurrence_end: '2026-11-10', category: 'absence' }), 'Europe/Berlin');
        expect(e.classNames).toContain('cal-locked');
        expect(e.editable).toBe(false);
    });
});

describe('colours', () => {
    it('maps every colour key to a css variable class and falls back to accent', () => {
        for (const c of COLORS) {
            expect(COLOR_VARS[c]).toMatch(/^--[a-z0-9-]+$/);
            expect(colorClass(c)).toBe(`cal-c-${c}`);
        }
        expect(colorClass('pink')).toBe('cal-c-accent');
    });
});

describe('filters and absence bar', () => {
    const items = [item({ id: 1, category: 'training' }), item({ id: 2, category: 'duty', my_response: 'accepted' }), item({ id: 3, category: 'meeting', my_response: 'pending' })];

    it('filters by category and "mine"', () => {
        expect(filterItems(items, { categories: ['training', 'duty'], mine: false }).map((i) => i.id)).toEqual([1, 2]);
        expect(filterItems(items, { categories: ['training', 'duty', 'meeting'], mine: true }).map((i) => i.id)).toEqual([2, 3]);
        expect(filterItems(items, { categories: [], mine: false })).toEqual([]);
    });

    it('lists today\'s absent names from the titles, once, sorted', () => {
        const abs = (title: string, s: string, e: string, extra: Partial<FeedItem> = {}) => item({ source_locked: true, title, all_day: true, category: 'absence', occurrence_start: s, occurrence_end: e, ...extra });
        const feed = [
            abs('Abwesend: Zoe Z', '2026-11-09', '2026-11-12'),
            abs('Abwesend: Anna A', '2026-11-10', '2026-11-10'),
            abs('Abwesend: Anna A', '2026-11-10', '2026-11-10'),
            abs('Abwesend: Bob B', '2026-11-11', '2026-11-12'),
            abs('Abwesend: Ende E', '2026-11-01', '2026-11-09'),
            item({ title: 'Übung', category: 'training' }),
            abs('Urlaub: Mallorca', '2026-11-10', '2026-11-10', { source_locked: false }),
        ];
        expect(absentNames(feed, '2026-11-10')).toEqual(['Anna A', 'Zoe Z']);
        expect(absentNames(feed, '2026-11-20')).toEqual([]);
    });
});

describe('series editor', () => {
    it('builds the rule the backend normalises and sends UNTIL as its own field', () => {
        expect(buildRule(blankRecurrence())).toEqual({ recurrence_rule: null, recurrence_until: null });
        expect(buildRule({ ...blankRecurrence(), freq: 'DAILY', interval: 2 })).toEqual({ recurrence_rule: 'FREQ=DAILY;INTERVAL=2', recurrence_until: null });
        expect(buildRule({ ...blankRecurrence(), freq: 'WEEKLY', weekdays: [5, 1, 1], end: 'count', count: 5 })).toEqual({ recurrence_rule: 'FREQ=WEEKLY;INTERVAL=1;BYDAY=MO,FR;COUNT=5', recurrence_until: null });
        expect(buildRule({ ...blankRecurrence(), freq: 'MONTHLY', weekdays: [1], end: 'until', until: '2027-01-31' })).toEqual({ recurrence_rule: 'FREQ=MONTHLY;INTERVAL=1', recurrence_until: '2027-01-31' });
    });

    it('clamps interval and count to the accepted ranges', () => {
        expect(buildRule({ ...blankRecurrence(), freq: 'DAILY', interval: 0 }).recurrence_rule).toBe('FREQ=DAILY;INTERVAL=1');
        expect(buildRule({ ...blankRecurrence(), freq: 'DAILY', interval: 5000, end: 'count', count: 99999 }).recurrence_rule).toBe('FREQ=DAILY;INTERVAL=999;COUNT=9999');
    });

    it('parses stored rules back into the editor state', () => {
        expect(parseRule(null, null)).toEqual(blankRecurrence());
        expect(parseRule('FREQ=WEEKLY;INTERVAL=2;BYDAY=MO,WE;COUNT=8', null)).toEqual({ freq: 'WEEKLY', interval: 2, weekdays: [1, 3], end: 'count', count: 8, until: '' });
        expect(parseRule('FREQ=MONTHLY;INTERVAL=1', '2027-01-31T22:59:59Z')).toMatchObject({ freq: 'MONTHLY', end: 'until', until: '2027-01-31' });
        expect(parseRule('FREQ=DAILY;INTERVAL=1', null).end).toBe('never');
    });

    it('round-trips', () => {
        const r = { ...blankRecurrence(), freq: 'WEEKLY' as const, interval: 3, weekdays: [2, 4], end: 'until' as const, until: '2027-03-01' };
        const built = buildRule(r);
        expect(parseRule(built.recurrence_rule, built.recurrence_until)).toMatchObject({ freq: 'WEEKLY', interval: 3, weekdays: [2, 4], end: 'until', until: '2027-03-01' });
    });

    it('adds the start weekday to a weekly selection', () => {
        const r = { ...blankRecurrence(), freq: 'WEEKLY' as const, weekdays: [3] };
        expect(withStartWeekday(r, '2026-11-09').weekdays).toEqual([1, 3]);
        expect(withStartWeekday(r, '2026-11-11').weekdays).toEqual([3]);
        expect(withStartWeekday({ ...r, freq: 'DAILY' }, '2026-11-09').weekdays).toEqual([3]);
    });

    it('checks end >= start and the 31 day series cap like the server', () => {
        const f = { allDay: false, start: '2026-11-10T10:00', end: '2026-11-10T09:00', recurring: false };
        expect(timeError(f)).toBe('calendar.error.end_before_start');
        expect(timeError({ ...f, end: '2026-11-10T10:00' })).toBeNull();
        expect(timeError({ ...f, end: '2026-12-11T10:01', recurring: true })).toBe('calendar.error.series_too_long');
        expect(timeError({ ...f, end: '2026-12-11T10:00', recurring: true })).toBeNull();
        expect(timeError({ allDay: true, start: '2026-11-01', end: '2026-12-02', recurring: true })).toBe('calendar.error.series_too_long');
        expect(timeError({ allDay: true, start: '2026-11-01', end: '2026-12-01', recurring: false })).toBeNull();
        expect(timeError({ allDay: true, start: '2026-11-01', end: '2026-12-01', recurring: true })).toBeNull();
    });
});

describe('api errors', () => {
    it('flattens indexed validation keys and keeps the first message', () => {
        expect(fieldErrors({ recurrence_rule: ['a', 'b'], 'attendee_ids.2': ['c'], 'attendee_ids.3': ['d'] })).toEqual({ recurrence_rule: 'a', attendee_ids: 'c' });
        expect(fieldErrors(undefined)).toEqual({});
    });
});

describe('v-html', () => {
    const root = join(__dirname, '..', '..', 'resources', 'js');
    const files = (dir: string): string[] => readdirSync(dir).flatMap((n) => (statSync(join(dir, n)).isDirectory() ? files(join(dir, n)) : [join(dir, n)]));

    it('is never used on the calendar page or its components (descriptions are plain text)', () => {
        const mine = [...files(join(root, 'Pages', 'Calendar')), ...files(join(root, 'components', 'calendar'))];
        expect(mine.length).toBeGreaterThan(3);
        expect(mine.filter((f) => /v-html|innerHTML/.test(readFileSync(f, 'utf8')))).toEqual([]);
    });
});

describe('translation keys', () => {
    const base = join(__dirname, '..', '..');
    const root = join(base, 'resources', 'js');
    const files = (dir: string): string[] => readdirSync(dir).flatMap((n) => (statSync(join(dir, n)).isDirectory() ? files(join(dir, n)) : [join(dir, n)]));
    const load = (lang: string): Record<string, string> => {
        const dict: Record<string, string> = JSON.parse(readFileSync(join(base, 'lang', `${lang}.json`), 'utf8'));
        for (const m of readdirSync(join(base, 'modules'))) {
            try {
                Object.assign(dict, JSON.parse(readFileSync(join(base, 'modules', m, 'lang', `${lang}.json`), 'utf8')));
            } catch {
            }
        }

        return dict;
    };

    it.each(['de', 'en'])('has every literal key of the calendar page, components and helpers (%s)', (lang) => {
        const dict = load(lang);
        const sources = [...files(join(root, 'Pages', 'Calendar')), ...files(join(root, 'components', 'calendar')), join(root, 'lib', 'calendar.ts'), join(root, 'lib', 'calendarApi.ts')];
        const missing: string[] = [];
        for (const f of sources) {
            for (const m of readFileSync(f, 'utf8').matchAll(/'(calendar\.[a-z_.]+)'/g)) if (!(m[1] in dict)) missing.push(`${f.slice(root.length + 1)}: ${m[1]}`);
        }
        expect([...new Set(missing)]).toEqual([]);
    });

    it('has the dynamically built groups complete (view, category, colour, visibility, weekday, response, unit)', () => {
        const dict = load('en');
        const need = [
            ...['month', 'week', 'list', 'today'].map((k) => `calendar.view.${k}`),
            ...['general', 'meeting', 'training', 'duty', 'absence'].map((k) => `calendar.category.${k}`),
            ...['accent', 'blue', 'green', 'red', 'purple', 'gray'].map((k) => `calendar.color.${k}`),
            ...['private', 'attendees', 'role', 'all'].flatMap((k) => [`calendar.visibility.${k}`, `calendar.visibility.${k}_hint`]),
            ...[1, 2, 3, 4, 5, 6, 7].map((k) => `calendar.weekday.${k}`),
            ...['accepted', 'declined', 'tentative', 'pending', 'none'].map((k) => `calendar.response.${k}`),
            ...['daily', 'weekly', 'monthly'].map((k) => `calendar.recurrence.unit.${k}`),
        ];
        expect(need.filter((k) => !(k in dict))).toEqual([]);
    });
});
