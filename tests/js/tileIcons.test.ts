import { ExternalLink, Truck } from '@lucide/vue';
import { existsSync, readFileSync } from 'node:fs';
import { beforeAll, expect, it } from 'vitest';
import { linkProps, loadTileIcons, tileIcon } from '@/lib/tileIcons';

beforeAll(async () => {
    expect(tileIcon('fa-solid fa-truck')).toBe(ExternalLink); // before the lazy map has loaded
    await loadTileIcons();
});

const legacyMapped = [
    'fa-brands fa-discord', 'fa-solid fa-boxes-stacked', 'fa-solid fa-clock-rotate-left', 'fa-solid fa-diagram-project', 'fa-solid fa-file-lines',
    'fa-solid fa-file-medical', 'fa-solid fa-fire', 'fa-solid fa-graduation-cap', 'fa-solid fa-house', 'fa-solid fa-id-badge', 'fa-solid fa-kit-medical',
    'fa-solid fa-layer-group', 'fa-solid fa-medal', 'fa-solid fa-puzzle-piece', 'fa-solid fa-screwdriver-wrench', 'fa-solid fa-sliders',
    'fa-solid fa-table-cells-large', 'fa-solid fa-ticket', 'fa-solid fa-triangle-exclamation', 'fa-solid fa-truck', 'fa-solid fa-user-shield', 'fa-solid fa-users',
];
const legacyExternal = ['fa-solid fa-up-right-from-square', 'external-link-alt', 'fa-solid fa-external-link-alt', 'fa-solid fa-arrow-up-right-from-square'];

it('maps every legacy tile icon to a Lucide component of its own', () => {
    for (const c of legacyMapped) expect(tileIcon(c), c).not.toBe(ExternalLink);
});

it('maps the legacy default icons to the external-link icon', () => {
    for (const c of legacyExternal) expect(tileIcon(c), c).toBe(ExternalLink);
});

it('accepts short and prefixed classes alike and ignores the style prefix', () => {
    expect(tileIcon('fa-truck')).toBe(Truck);
    expect(tileIcon('fa-solid fa-truck')).toBe(Truck);
    expect(tileIcon('fas fa-truck')).toBe(Truck);
    expect(tileIcon('truck')).toBe(Truck);
    expect(tileIcon('fa-regular fa-truck fa-2x')).toBe(Truck);
});

it('falls back to the external-link icon for unknown, empty and null values', () => {
    for (const c of ['fa-solid fa-does-not-exist', '', '   ', null, undefined, 'fa-solid', '__proto__', 'constructor']) expect(tileIcon(c as string)).toBe(ExternalLink);
});

it('opens http(s) links in a new tab without opener and keeps paths and mailto in the tab', () => {
    expect(linkProps('https://example.org/x')).toEqual({ href: 'https://example.org/x', target: '_blank', rel: 'noopener noreferrer' });
    expect(linkProps('http://example.org')).toMatchObject({ target: '_blank', rel: 'noopener noreferrer' });
    expect(linkProps('/vehicles?x=1')).toEqual({ href: '/vehicles?x=1' });
    expect(linkProps('mailto:a@example.org')).toEqual({ href: 'mailto:a@example.org' });
});

it('never renders a script, data or protocol-relative target', () => {
    for (const u of ['javascript:alert(1)', 'JaVaScRiPt:alert(1)', 'data:text/html,x', '//evil.example', '/\\evil.example', 'vbscript:x', '', 'example.org', 'https://a b'])
        expect(linkProps(u), u).toEqual({ href: '#' });
});

const faFile = `${process.env.LEGACY_PATH ?? '../ignis'}/assets/json/fa-free-icons.json`;

it.skipIf(!existsSync(faFile))('resolves every Font Awesome free icon name without throwing, mapping at least 300 and falling back for the rest', () => {
    const all: string[] = JSON.parse(readFileSync(faFile, 'utf8'));
    expect(all.length).toBeGreaterThan(600);
    let mapped = 0;
    for (const c of all) {
        const icon = tileIcon(c);
        expect(icon, c).toBeTruthy();
        if (icon !== ExternalLink) mapped++;
    }
    expect(mapped).toBeGreaterThanOrEqual(300);
});
