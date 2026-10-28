// Shared by the bell, its popover and the inbox page (contract: NotificationKinds::present()).

export type InboxEntry = {
    id: string;
    type: string;
    title: string;
    message: string | null;
    link: string | null;
    created_at: string | null;
    known: boolean;
    label: string;
    icon: string;
    href: string | null;
    is_read: boolean;
};

export const POLL_MS = 30_000;
const MAX_BACKOFF_MS = 5 * 60_000;

export function titleWithCount(title: string, count: number): string {
    const bare = title.replace(/^\(\d+\+?\)\s+/, '');

    return count > 0 ? `(${count > 99 ? '99+' : count}) ${bare}` : bare;
}

export function nextPollDelay(status: number, current: number): number | null {
    if (status === 401 || status === 419) return null;
    if (status >= 200 && status < 300) return POLL_MS;

    return Math.min(Math.max(current, POLL_MS) * 2, MAX_BACKOFF_MS);
}

export function advancePoll(stopped: boolean, status: number, delay: number): { stopped: boolean; delay: number } {
    const next = nextPollDelay(status, delay);

    return { stopped: stopped || next === null, delay: next ?? delay };
}

const localDay = (d: Date) => `${d.getFullYear()}-${d.getMonth() + 1}-${d.getDate()}`;

export type DayGroup = { key: string; day: 'today' | 'yesterday' | 'date'; date: Date | null; entries: InboxEntry[] };

export function dayGroups(entries: InboxEntry[], now: Date = new Date()): DayGroup[] {
    const yesterday = new Date(now);
    yesterday.setDate(now.getDate() - 1);
    const groups: DayGroup[] = [];
    for (const entry of entries) {
        const at = entry.created_at ? new Date(entry.created_at) : null;
        const key = at ? localDay(at) : '';
        const day = key === localDay(now) ? 'today' : key === localDay(yesterday) ? 'yesterday' : 'date';
        const last = groups[groups.length - 1];
        if (last && last.key === key) last.entries.push(entry);
        else groups.push({ key, day, date: at, entries: [entry] });
    }

    return groups;
}
