import { expect, it } from 'vitest';
import { safeHttpsLink, shownAnnouncements, type Announcement } from '@/lib/announcements';

const a = (id: string): Announcement => ({ id, type: 'info', title: id, message: null, link: null });

it('lets only plain absolute https links through', () => {
    expect(safeHttpsLink('https://emergencyforge.de/blog/x?a=1')).toBe('https://emergencyforge.de/blog/x?a=1');
    for (const bad of ['javascript:alert(1)', 'http://emergencyforge.de', 'data:text/html,x', '//evil.example', '/blog', 'https://u:p@evil.example/', 'not a url', '', null, undefined]) {
        expect(safeHttpsLink(bad as string)).toBeNull();
    }
});

it('ignores a shared prop that is not a list and hides dismissed ids', () => {
    expect(shownAnnouncements({ hub: false, website: true }, new Set())).toEqual([]);
    for (const odd of [undefined, null, 'x', 5, {}]) expect(shownAnnouncements(odd, new Set())).toEqual([]);
    expect(shownAnnouncements([a('1'), a('2')], new Set(['1']))).toEqual([a('2')]);
});
