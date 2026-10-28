import { ExternalLink, type LucideIcon } from '@lucide/vue';
import { shallowRef } from 'vue';

const icons = shallowRef<Record<string, LucideIcon> | null>(null);

export async function loadTileIcons(): Promise<void> {
    icons.value ??= (await import('./tileIconMap')).default;
}

const prefixes = new Set(['fa', 'fas', 'far', 'fab', 'fal', 'fad', 'fa-solid', 'fa-regular', 'fa-brands', 'fa-light', 'fa-duotone', 'fa-thin']);

export function tileIcon(classes: string | null | undefined): LucideIcon {
    for (const token of (classes ?? '').trim().split(/\s+/).reverse()) {
        if (prefixes.has(token)) continue;
        const name = token.replace(/^fa-/, '');
        if (icons.value && Object.hasOwn(icons.value, name)) return icons.value[name];
    }
    return ExternalLink;
}

/**
 * How a stored target is rendered. The server only stores relative paths, http(s) and mailto (App\Dashboard\LinkTarget);
 * this repeats the check so a row from elsewhere can never become a script URL.
 */
export function linkProps(url: string): { href: string; target?: '_blank'; rel?: string } {
    if (/^https?:\/\/[^\s\\]+$/.test(url)) return { href: url, target: '_blank', rel: 'noopener noreferrer' };
    if (/^\/(?![/\\])[^\s\\]*$/.test(url) || /^mailto:[^\s\\]+$/.test(url)) return { href: url };
    return { href: '#' };
}
