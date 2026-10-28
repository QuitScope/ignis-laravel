import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it, vi } from 'vitest';

const root = join(__dirname, '..', '..', 'resources', 'js');
const files = (dir: string): string[] =>
    readdirSync(dir).flatMap((name) => {
        const path = join(dir, name);

        return statSync(path).isDirectory() ? files(path) : [path];
    });

vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { name: 'App' } }));
vi.mock('@/Layouts/GuestLayout.vue', () => ({ default: { name: 'Guest' } }));

describe('v-html', () => {
    it('is used only in KbProse.vue and the mail reading pane', () => {
        const hits = files(root)
            .filter((f) => /\.vue$/.test(f))
            .filter((f) => /v-html/.test(readFileSync(f, 'utf8')))
            .map((f) => f.slice(root.length + 1).replaceAll('\\', '/'));

        expect(hits).toEqual(['components/knowledgebase/KbProse.vue', 'components/mail/ReadingPane.vue']);
    });
});

describe('kbLayout', () => {
    it('picks the layout by noindex and passes no props on', async () => {
        const { kbLayout } = await import('@/components/knowledgebase/kbLayout');
        const render = vi.fn(() => null);
        const page = (noindex: boolean) => ({ props: { noindex, entry: { title: 'x' } } }) as never;
        kbLayout(render as never, page(true));
        kbLayout(render as never, page(false));
        const [guest, app] = render.mock.calls as unknown as [unknown, unknown, unknown][];

        expect(guest[0]).not.toBe(app[0]);
        expect(guest[1]).toBeNull();
        expect(app[1]).toBeNull();
    });
});
