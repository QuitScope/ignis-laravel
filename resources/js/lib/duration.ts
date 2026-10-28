// Human-readable ages for the system overview, in the page locale (Intl, no library).

export function relativeAge(seconds: number, locale: string): string {
    const rtf = new Intl.RelativeTimeFormat(locale, { numeric: 'auto' });
    const s = Math.max(0, Math.round(seconds));
    if (s < 60) return rtf.format(-s, 'second');
    if (s < 3600) return rtf.format(-Math.floor(s / 60), 'minute');
    if (s < 86400) return rtf.format(-Math.floor(s / 3600), 'hour');

    return rtf.format(-Math.floor(s / 86400), 'day');
}

export function formatBytes(n: number, locale: string): string {
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let i = 0;
    while (n >= 1024 && i < units.length - 1) {
        n /= 1024;
        i++;
    }

    return `${new Intl.NumberFormat(locale, { maximumFractionDigits: i === 0 ? 0 : 1 }).format(n)} ${units[i]}`;
}
