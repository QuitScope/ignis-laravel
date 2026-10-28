// Pure helpers of the calendar page (feed -> FullCalendar, colours, series editor, organisation-zone formatting).
//
// Time model: FullCalendar runs with timeZone 'UTC' and is fed *wall-clock* values of the organisation zone
// (config('ignis.timezone')), so what it shows never depends on the browser's zone. A "wall" string is
// `YYYY-MM-DDTHH:mm[:ss]` without offset; the backend reads such input as wall time in the same zone.

export type FeedItem = {
    id: number;
    occurrence_start: string;
    occurrence_end: string;
    all_day: boolean;
    title: string;
    color: string;
    category: string;
    location: string | null;
    attendee_count: number | null;
    my_response: string | null;
    can_edit: boolean;
    recurring: boolean;
    source_locked: boolean;
    visibility: string;
};

export const COLORS = ['accent', 'blue', 'green', 'red', 'purple', 'gray'] as const;
export const CATEGORIES = ['general', 'meeting', 'training', 'duty', 'absence'] as const;
export const WEEKDAYS = ['MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU'] as const;
/** Same cap as EventRequest (CalendarEvent::MAX_SERIES_DAYS). */
export const MAX_SERIES_DAYS = 31;
export const MAX_ATTENDEES = 500;

export const COLOR_VARS: Record<string, string> = {
    accent: '--accent',
    blue: '--info',
    green: '--ok',
    red: '--danger',
    purple: '--cal-purple',
    gray: '--text-3',
};

export const colorVar = (color: string): string => COLOR_VARS[color] ?? COLOR_VARS.accent;
export const colorClass = (color: string): string => `cal-c-${color in COLOR_VARS ? color : 'accent'}`;

const formatters = new Map<string, Intl.DateTimeFormat>();

export function toWall(iso: string | Date, zone: string): string {
    let f = formatters.get(zone);
    if (!f) {
        f = new Intl.DateTimeFormat('en-CA', { timeZone: zone, hourCycle: 'h23', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit' });
        formatters.set(zone, f);
    }
    const p = Object.fromEntries(f.formatToParts(typeof iso === 'string' ? new Date(iso) : iso).map((x) => [x.type, x.value]));

    return `${p.year}-${p.month}-${p.day}T${p.hour}:${p.minute}:${p.second}`;
}

export const wallNow = (zone: string): Date => new Date(`${toWall(new Date(), zone)}Z`);

export const floatingDate = (d: Date): string => d.toISOString().slice(0, 10);
export const floatingWall = (d: Date): string => d.toISOString().slice(0, 16);

export function addDays(date: string, n: number): string {
    const d = new Date(`${date.slice(0, 10)}T00:00:00Z`);
    d.setUTCDate(d.getUTCDate() + n);

    return d.toISOString().slice(0, 10);
}

export function addMinutes(wall: string, n: number): string {
    return new Date(new Date(`${wall.slice(0, 16)}:00Z`).getTime() + n * 60000).toISOString().slice(0, 16);
}

export const isoWeekday = (date: string): number => new Date(`${date.slice(0, 10)}T00:00:00Z`).getUTCDay() || 7;

export const rangeParams = (start: Date, end: Date): { from: string; to: string } => ({ from: floatingDate(start), to: floatingDate(end) });

export function mapFeedItem(item: FeedItem, zone: string) {
    const start = item.all_day ? item.occurrence_start : toWall(item.occurrence_start, zone);
    const end = item.all_day ? addDays(item.occurrence_end, 1) : toWall(item.occurrence_end, zone);

    return {
        id: `${item.id}-${item.occurrence_start}`,
        title: item.title,
        start,
        end,
        allDay: item.all_day,
        editable: false,
        classNames: [colorClass(item.color), ...(item.source_locked ? ['cal-locked'] : [])],
        extendedProps: { item },
    };
}

export type Filters = { categories: string[]; mine: boolean };

export const filterItems = (items: FeedItem[], f: Filters): FeedItem[] =>
    items.filter((i) => f.categories.includes(i.category) && (!f.mine || i.my_response !== null));

export function absentNames(items: FeedItem[], today: string): string[] {
    const names = items
        .filter((i) => i.source_locked && i.category === 'absence' && i.all_day && i.occurrence_start <= today && today <= i.occurrence_end)
        .map((i) => i.title.replace(/^[^:]*:\s*/, '').trim() || i.title);

    return [...new Set(names)].sort((a, b) => a.localeCompare(b));
}

export function formatInstant(value: string, allDay: boolean, zone: string, locale: string): string {
    if (allDay) return new Intl.DateTimeFormat(locale, { dateStyle: 'medium', timeZone: 'UTC' }).format(new Date(`${value.slice(0, 10)}T00:00:00Z`));

    return new Intl.DateTimeFormat(locale, { dateStyle: 'medium', timeStyle: 'short', hour12: false, timeZone: zone }).format(new Date(value));
}

export function formatRange(start: string, end: string, allDay: boolean, zone: string, locale: string): string {
    const a = formatInstant(start, allDay, zone, locale);
    if (allDay) {
        const b = formatInstant(end, true, zone, locale);

        return a === b ? a : `${a} – ${b}`;
    }
    const sameDay = toWall(start, zone).slice(0, 10) === toWall(end, zone).slice(0, 10);
    const b = sameDay
        ? new Intl.DateTimeFormat(locale, { timeStyle: 'short', hour12: false, timeZone: zone }).format(new Date(end))
        : formatInstant(end, false, zone, locale);

    return `${a} – ${b}`;
}

export const toInputValue = (value: string, allDay: boolean, zone: string): string => (allDay ? value.slice(0, 10) : toWall(value, zone).slice(0, 16));

// ---- series editor -------------------------------------------------------------------------------------------

export type Recurrence = {
    freq: 'NONE' | 'DAILY' | 'WEEKLY' | 'MONTHLY';
    interval: number;
    weekdays: number[];
    end: 'never' | 'count' | 'until';
    count: number;
    until: string;
};

export const blankRecurrence = (): Recurrence => ({ freq: 'NONE', interval: 1, weekdays: [], end: 'never', count: 10, until: '' });

const clamp = (n: number, min: number, max: number): number => Math.min(max, Math.max(min, Math.trunc(Number.isFinite(n) ? n : min)));

export function buildRule(r: Recurrence): { recurrence_rule: string | null; recurrence_until: string | null } {
    if (r.freq === 'NONE') return { recurrence_rule: null, recurrence_until: null };
    const days = [...new Set(r.weekdays)]
        .filter((d) => d >= 1 && d <= 7)
        .sort((a, b) => a - b)
        .map((d) => WEEKDAYS[d - 1]);
    const rule = [`FREQ=${r.freq}`, `INTERVAL=${clamp(r.interval, 1, 999)}`];
    if (r.freq === 'WEEKLY' && days.length > 0) rule.push(`BYDAY=${days.join(',')}`);
    if (r.end === 'count') rule.push(`COUNT=${clamp(r.count, 1, 9999)}`);

    return { recurrence_rule: rule.join(';'), recurrence_until: r.end === 'until' && r.until !== '' ? r.until : null };
}

export function parseRule(rule: string | null, untilDate: string | null): Recurrence {
    const r = blankRecurrence();
    const m = rule === null ? null : /^FREQ=(DAILY|WEEKLY|MONTHLY)/i.exec(rule);
    if (rule === null || m === null) return r;
    const part = (key: string) => new RegExp(`(?:^|;)${key}=([^;]+)`, 'i').exec(rule)?.[1];
    r.freq = m[1].toUpperCase() as Recurrence['freq'];
    r.interval = clamp(Number(part('INTERVAL') ?? 1), 1, 999);
    r.weekdays = (part('BYDAY') ?? '')
        .split(',')
        .map((d) => WEEKDAYS.indexOf(d.toUpperCase() as (typeof WEEKDAYS)[number]) + 1)
        .filter((d) => d > 0);
    const count = part('COUNT');
    if (count !== undefined) {
        r.end = 'count';
        r.count = clamp(Number(count), 1, 9999);
    } else if (untilDate) {
        r.end = 'until';
        r.until = untilDate.slice(0, 10);
    }

    return r;
}

export function withStartWeekday(r: Recurrence, startDate: string): Recurrence {
    if (r.freq !== 'WEEKLY' || startDate === '') return r;
    const day = isoWeekday(startDate);

    return r.weekdays.includes(day) ? r : { ...r, weekdays: [...r.weekdays, day].sort((a, b) => a - b) };
}

export type TimeForm = { allDay: boolean; start: string; end: string; recurring: boolean };

export function timeError(f: TimeForm): string | null {
    if (f.start === '' || f.end === '') return null;
    if (f.end < f.start) return 'calendar.error.end_before_start';
    if (f.recurring) {
        const ms = f.allDay
            ? Date.parse(`${f.end}T00:00:00Z`) - Date.parse(`${f.start}T00:00:00Z`) + 86400000
            : Date.parse(`${f.end}:00Z`) - Date.parse(`${f.start}:00Z`);
        if (ms > MAX_SERIES_DAYS * 86400000) return 'calendar.error.series_too_long';
    }

    return null;
}
