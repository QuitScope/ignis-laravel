// Announcements are foreign data: the server validates them, this is the second lock before a link
// reaches an href. Text goes through mustache only, never v-html.

export type Announcement = { id: string; type: 'critical' | 'warning' | 'update' | 'success' | 'info'; title: string; message: string | null; link: string | null };

/** Only absolute https links (no credentials) are rendered; anything else shows no link. */
export function safeHttpsLink(link: string | null | undefined): string | null {
    if (!link) return null;
    try {
        const url = new URL(link);

        return url.protocol === 'https:' && !url.username && !url.password ? url.href : null;
    } catch {
        return null;
    }
}

export const shownAnnouncements = (raw: unknown, hidden: Set<string>): Announcement[] => (Array.isArray(raw) ? (raw as Announcement[]).filter((a) => !hidden.has(a.id)) : []);

export const TONE: Record<Announcement['type'], string> = {
    critical: 'border-danger bg-(--danger-soft)',
    warning: 'border-warn bg-(--warn-soft)',
    update: 'border-accent bg-(--accent-soft)',
    success: 'border-ok bg-(--ok-soft)',
    info: 'border-info bg-(--info-soft)',
};
