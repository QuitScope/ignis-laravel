import { describe, expect, it, vi } from 'vitest';
import { clockOffset, createAsuClient, uuid } from '@/lib/asuClient';

const mem = () => {
    const m = new Map<string, string>();
    return { getItem: (k: string) => m.get(k) ?? null, setItem: (k: string, v: string) => void m.set(k, v), removeItem: (k: string) => void m.delete(k) };
};
const res = (status: number, body: unknown = {}) => ({ status, ok: status < 300, json: async () => body }) as Response;
const ev = { trupp_id: 1, type: 'druck', bar: 200 };

describe('asuClient', () => {
    it('computes the offset from the request midpoint and builds uuids without randomUUID', () => {
        expect(clockOffset(10_500, 10_000, 10_200)).toBe(400);
        const v = uuid({ getRandomValues: (a: Uint8Array) => a.fill(7) } as unknown as Crypto);
        expect(v).toMatch(/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/);
        expect(uuid(undefined)).toMatch(/^[0-9a-f-]{36}$/);
    });

    it('stamps occurred_at with the corrected clock, persists the queue and keeps it on 5xx and network errors', async () => {
        const storage = mem();
        let mode: 'down' | 'err' | 'ok' = 'down';
        const fetchImpl = vi.fn(async () => {
            if (mode === 'down') throw new Error('offline');
            return mode === 'err' ? res(503) : res(200);
        });
        const c = createAsuClient({ url: '/x/asu', incidentId: 9, storage, fetchImpl: fetchImpl as unknown as typeof fetch, clock: () => 1000 });
        c.state.offset = 500;
        c.record(ev);
        await vi.waitFor(() => expect(fetchImpl).toHaveBeenCalledTimes(1));
        const saved = JSON.parse(storage.getItem('asu.queue./x/asu') as string);
        expect(saved).toHaveLength(1);
        expect(saved[0].body.occurred_at).toBe(1500);
        expect(saved[0].body.client_uuid).toMatch(/^[0-9a-f-]{36}$/);

        const c2 = createAsuClient({ url: '/x/asu', incidentId: 9, storage, fetchImpl: fetchImpl as unknown as typeof fetch });
        expect(c2.pending()).toBe(1);
        mode = 'err';
        await c2.flush();
        expect(c2.pending()).toBe(1);
        mode = 'ok';
        await new Promise((r) => setTimeout(r, 1100));
        await c2.flush();
        expect(c2.pending()).toBe(0);
        expect(storage.getItem('asu.queue./x/asu')).toBeNull();
    });

    it('sends serially in order, drops a 4xx with a toast and carries on', async () => {
        const storage = mem();
        const sent: number[] = [];
        let busy = 0;
        let maxBusy = 0;
        const fetchImpl = vi.fn(async (_u: string, init: RequestInit) => {
            busy++;
            maxBusy = Math.max(maxBusy, busy);
            await new Promise((r) => setTimeout(r, 5));
            busy--;
            const bar = JSON.parse(init.body as string).bar;
            sent.push(bar);
            return bar === 2 ? res(422) : res(200);
        });
        const onToast = vi.fn();
        const c = createAsuClient({ url: '/x/asu', incidentId: 1, storage, fetchImpl: fetchImpl as unknown as typeof fetch, onToast });
        for (const bar of [1, 2, 3]) c.record({ ...ev, bar });
        await vi.waitFor(() => expect(c.pending()).toBe(0));
        expect(sent).toEqual([1, 2, 3]);
        expect(maxBusy).toBe(1);
        expect(onToast).toHaveBeenCalledWith(422);
    });

    it.each([419, 401, 408, 429])('keeps queued messages on %i without a toast; 401/419 flag the expired session until a success', async (status) => {
        const storage = mem();
        let code = status;
        const fetchImpl = vi.fn(async () => res(code));
        const onToast = vi.fn();
        const c = createAsuClient({ url: '/x/asu', incidentId: 3, storage, fetchImpl: fetchImpl as unknown as typeof fetch, onToast });
        c.record(ev);
        await vi.waitFor(() => expect(fetchImpl).toHaveBeenCalledTimes(1));
        await vi.waitFor(() => expect(c.state.authExpired).toBe(status === 401 || status === 419));
        expect(c.pending()).toBe(1);
        expect(onToast).not.toHaveBeenCalled();

        code = 200;
        await new Promise((r) => setTimeout(r, 1100));
        await c.flush();
        expect(c.pending()).toBe(0);
        expect(c.state.authExpired).toBe(false);
        c.stop();
    });

    it('polls with since and etag, corrects the clock, applies voids to older events and shows pending ones', async () => {
        const body = (extra: object) => ({ now: 5000, writable: true, trupps_etag: 'e1', trupps: [{ id: 1, name: 'T', role: 'angriff', task: null, expected_minutes: 30, members: [] }], events: [], voided: [], ...extra });
        const urls: string[] = [];
        const answers = [
            body({ events: [{ id: 4, trupp_id: 1, type: 'druck', at: 100, client_uuid: 'a' }] }),
            body({ trupps: null, events: [], voided: [4] }),
        ];
        const fetchImpl = vi.fn(async (u: string) => {
            urls.push(u);
            return u.includes('/state') ? res(200, answers.shift()) : res(503);
        });
        let t = 1000;
        const c = createAsuClient({ url: '/x/asu', incidentId: 2, storage: mem(), fetchImpl: fetchImpl as unknown as typeof fetch, clock: () => (t += 100) });
        await c.poll();
        expect(urls[0]).toBe('/x/asu/state?since=0');
        expect(c.state.offset).toBe(5000 - 1150);
        await c.poll();
        expect(urls[1]).toBe('/x/asu/state?since=4&trupps_etag=e1');
        expect(c.state.trupps).toHaveLength(1);
        expect(c.view().find((e) => e.id === 4)?.voided).toBe(true);

        c.record(ev);
        await vi.waitFor(() => expect(c.pending()).toBe(1));
        expect(c.view().some((e) => e.pending && e.bar === 200)).toBe(true);
        c.stop();
    });

    it('two clients on one queue send every message at least once and never drop an unsent one', async () => {
        const storage = mem();
        const sent: number[] = [];
        const fetchImpl = vi.fn(async (_u: string, init: RequestInit) => {
            await new Promise((r) => setTimeout(r, 5));
            sent.push(JSON.parse(init.body as string).bar);
            return res(200);
        });
        const mk = () => createAsuClient({ url: '/x/asu', incidentId: 3, storage, fetchImpl: fetchImpl as unknown as typeof fetch });
        const a = mk();
        const b = mk();
        a.record({ ...ev, bar: 1 }); // a is now sending item 1 ...
        storage.setItem('asu.queue./x/asu', JSON.stringify([...JSON.parse(storage.getItem('asu.queue./x/asu') as string), { kind: 'event', body: { ...ev, bar: 2, client_uuid: uuid(), occurred_at: 1 } }]));
        void b.flush();
        await vi.waitFor(() => expect(a.pending() + b.pending()).toBe(0), { timeout: 2000 });
        expect(new Set(sent)).toEqual(new Set([1, 2]));
    });

    it('a read-only client polls but never sends and keeps the queue', async () => {
        const storage = mem();
        storage.setItem('asu.queue./crew/asu', JSON.stringify([{ kind: 'event', body: { ...ev, client_uuid: 'u1', occurred_at: 1 } }]));
        const urls: string[] = [];
        const fetchImpl = vi.fn(async (u: string) => {
            urls.push(u);
            return res(200, { now: 1, writable: false, trupps_etag: 'e', trupps: [], events: [], voided: [] });
        });
        const c = createAsuClient({ url: '/qm/asu', incidentId: 4, storage, fetchImpl: fetchImpl as unknown as typeof fetch, readOnly: true });
        await c.poll();
        await c.flush();
        expect(urls.every((u) => u.includes('/state'))).toBe(true);
        expect(c.pending()).toBe(0);
        const ro = createAsuClient({ url: '/crew/asu', incidentId: 4, storage, fetchImpl: fetchImpl as unknown as typeof fetch, readOnly: true });
        expect(ro.pending()).toBe(1);
        await ro.flush();
        expect(ro.pending()).toBe(1);
    });

    it('runs polls one at a time and once more for a poll asked during a running one', async () => {
        let active = 0;
        let max = 0;
        const fetchImpl = vi.fn(async () => {
            max = Math.max(max, ++active);
            await new Promise((r) => setTimeout(r, 5));
            active--;
            return res(200, { now: 0, writable: false, trupps_etag: 'e', trupps: [], events: [], voided: [] });
        });
        const c = createAsuClient({ url: '/x/asu', incidentId: 5, storage: mem(), fetchImpl: fetchImpl as unknown as typeof fetch, readOnly: true });
        await Promise.all([c.poll(), c.poll(), c.poll()]);
        expect(max).toBe(1);
        expect(fetchImpl).toHaveBeenCalledTimes(2);
    });
});
