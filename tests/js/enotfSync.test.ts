import { describe, expect, it, vi } from 'vitest';
import { createEnotfAutosave } from '@/lib/enotfAutosave';
import { createEnotfSync } from '@/lib/enotfSync';

const res = (status: number, body: unknown = {}) => ({ status, ok: status < 300, json: async () => body }) as Response;

function setup(answer: () => Response) {
    const fetchImpl = vi.fn(async () => answer());
    const save = createEnotfAutosave({
        url: '/enotf/p/1',
        revision: 3,
        values: { pat_vorname: 'A', temp: null, diagnose: null },
        fetchImpl: vi.fn() as unknown as typeof fetch,
        debounceMs: 60_000,
        storage: null,
    });
    const sync = createEnotfSync({ url: '/enotf/p/1', enr: '1', autosave: save, fetchImpl: fetchImpl as unknown as typeof fetch });
    return { save, sync, fetchImpl };
}

describe('enotfSync', () => {
    it("pulls another device's changes when the poll shows a newer revision and keeps unsent local edits", async () => {
        const { save, sync, fetchImpl } = setup(() => res(200, { revision: 5, status: 'open', released: false, changes_since: { temp: 36.5, diagnose: 'Sturz' } }));
        await sync.onStatus([{ enr: '1', revision: 3, status: 'open' }]);
        expect(fetchImpl).not.toHaveBeenCalled();

        save.set('temp', 38); // typed locally, not sent yet
        await sync.onStatus([{ enr: '1', revision: 5, status: 'open' }]);
        expect(fetchImpl.mock.calls[0][0]).toBe('/enotf/p/1/changes?since=3');
        expect(save.state.revision).toBe(5);
        expect(save.state.values).toMatchObject({ pat_vorname: 'A', temp: 38, diagnose: 'Sturz' });
        expect(save.state.conflicts).toEqual(['temp']);
        expect(save.pending()).toBe(1);
        save.stop();
    });

    it('switches to read only when the protocol was released elsewhere, the unsaved edits stay visible', async () => {
        const { save, sync } = setup(() => res(200, { revision: 6, status: 'released', released: true, changes_since: {} }));
        save.set('temp', 39);
        await sync.onStatus([]);
        expect(save.state).toMatchObject({ status: 'closed', closedAs: 'released', unsaved: ['temp'], values: { temp: 39 } });
        save.set('temp', 40); // no more edits
        expect(save.state.values.temp).toBe(39);
    });
});
