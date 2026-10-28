import { expect, it } from 'vitest';
import { advancePoll, dayGroups, nextPollDelay, POLL_MS, titleWithCount, type InboxEntry } from '@/lib/notifications';

const entry = (id: string, created_at: string | null): InboxEntry => ({
    id, type: 'system', title: id, message: null, link: null, created_at, known: true, label: 'System', icon: 'settings', href: null, is_read: false,
});

it('prefixes the title with the unread count and removes it again', () => {
    expect(titleWithCount('Ignis', 3)).toBe('(3) Ignis');
    expect(titleWithCount('(3) Ignis', 120)).toBe('(99+) Ignis');
    expect(titleWithCount('(99+) Ignis', 0)).toBe('Ignis');
});

it('stops polling when signed out and backs off on errors', () => {
    expect(nextPollDelay(200, POLL_MS)).toBe(POLL_MS);
    expect(nextPollDelay(401, POLL_MS)).toBeNull();
    expect(nextPollDelay(419, POLL_MS)).toBeNull();
    expect(nextPollDelay(500, POLL_MS)).toBe(2 * POLL_MS);
    expect(nextPollDelay(0, 4 * 60_000)).toBe(5 * 60_000);
    expect(nextPollDelay(200, 5 * 60_000)).toBe(POLL_MS);
});

it('keeps a stop set by unmount when a late answer arrives', () => {
    expect(advancePoll(true, 200, POLL_MS)).toEqual({ stopped: true, delay: POLL_MS });
    expect(advancePoll(false, 401, 2 * POLL_MS)).toEqual({ stopped: true, delay: 2 * POLL_MS });
    expect(advancePoll(false, 500, POLL_MS)).toEqual({ stopped: false, delay: 2 * POLL_MS });
});

it('groups by local day: today, yesterday, then dates', () => {
    const now = new Date(2026, 9, 19, 12, 0);
    const iso = (d: number, h: number) => new Date(2026, 9, d, h, 30).toISOString();
    const groups = dayGroups([entry('a', iso(19, 9)), entry('b', iso(19, 0)), entry('c', iso(18, 23)), entry('d', iso(10, 8))], now);

    expect(groups.map((g) => [g.day, g.entries.map((e) => e.id)])).toEqual([
        ['today', ['a', 'b']],
        ['yesterday', ['c']],
        ['date', ['d']],
    ]);
});
