import { describe, expect, it, vi } from 'vitest';
import { createEnotfAutosave } from '@/lib/enotfAutosave';

const res = (status: number, body: unknown = {}) => ({ status, ok: status < 300, json: async () => body }) as Response;
const sent = (f: ReturnType<typeof vi.fn>, i: number) => JSON.parse((f.mock.calls[i][1] as RequestInit).body as string);

describe('enotfAutosave', () => {
    it("adopts other devices' changes but keeps unsent local edits and reports them as conflicts", async () => {
        let release: (r: Response) => void = () => {};
        const fetchImpl = vi.fn(() => new Promise<Response>((r) => (release = r)));
        const a = createEnotfAutosave({
            url: '/enotf/p/1',
            revision: 3,
            values: { pat_vorname: 'A', temp: null },
            fetchImpl: fetchImpl as unknown as typeof fetch,
            debounceMs: 10_000,
        });

        a.set('pat_vorname', 'Anna');
        const flushing = a.flush();
        expect(sent(fetchImpl, 0)).toEqual({
            revision: 3,
            fields: { pat_vorname: 'Anna' },
        });
        a.set('temp', 37);
        release(
            res(200, {
                revision: 6,
                applied: ['pat_vorname'],
                conflicts: [],
                changes_since: { temp: 36.5, diagnose: 'Sturz' },
            }),
        );
        await flushing;

        expect(a.state.revision).toBe(6);
        expect(a.state.values).toMatchObject({
            pat_vorname: 'Anna',
            temp: 37,
            diagnose: 'Sturz',
        });
        expect(a.state.status).toBe('conflict');
        expect(a.state.conflicts).toEqual(['temp']);
        expect(a.pending()).toBe(1);

        const next = a.flush();
        expect(sent(fetchImpl, 1)).toEqual({
            revision: 6,
            fields: { temp: 37 },
        });
        release(
            res(200, {
                revision: 7,
                applied: ['temp'],
                conflicts: [{ key: 'temp', value: 36.5, revision: 5 }],
                changes_since: {},
            }),
        );
        await next;
        expect(a.state.conflicts).toEqual(['temp']);
        a.stop();
    });

    it('keeps every edit on network errors, 5xx and 419 and sends them again', async () => {
        const answers: (() => Response)[] = [
            () => {
                throw new Error('offline');
            },
            () => res(503),
            () => res(419),
            () =>
                res(200, {
                    revision: 2,
                    applied: ['spo2', 'rrsys'],
                    conflicts: [],
                    changes_since: {},
                }),
        ];
        const fetchImpl = vi.fn(async () => answers.shift()!());
        const a = createEnotfAutosave({
            url: '/enotf/p/1',
            revision: 1,
            values: {},
            fetchImpl: fetchImpl as unknown as typeof fetch,
            debounceMs: 10_000,
        });
        a.set('spo2', 95);
        await a.flush();
        expect(a.state.status).toBe('offline');
        a.set('rrsys', 120);
        await a.flush();
        expect(a.state.status).toBe('offline');
        await a.flush();
        expect(a.state.status).toBe('auth');
        expect(a.pending()).toBe(2);
        await a.flush();
        expect(sent(fetchImpl, 3)).toEqual({
            revision: 1,
            fields: { spo2: 95, rrsys: 120 },
        });
        expect(a.state.status).toBe('saved');
        expect(a.pending()).toBe(0);
        a.stop();
    });

    it('drops only rejected fields on 422 and stops with the server state on 409', async () => {
        const fetchImpl = vi
            .fn()
            .mockResolvedValueOnce(
                res(422, {
                    errors: { 'fields.awfrei_1': ['Ungültiger Wert.'] },
                }),
            )
            .mockResolvedValueOnce(
                res(409, {
                    message: 'closed',
                    status: 'released',
                    revision: 9,
                    values: { temp: 36 },
                }),
            );
        const a = createEnotfAutosave({
            url: '/enotf/p/1',
            revision: 1,
            values: {},
            fetchImpl: fetchImpl as unknown as typeof fetch,
            debounceMs: 10_000,
        });
        a.set('awfrei_1', 7);
        a.set('temp', 38);
        await a.flush();
        expect(a.state.invalid).toEqual(['awfrei_1']);
        expect(a.pending()).toBe(1);
        await a.flush();
        expect(sent(fetchImpl, 1).fields).toEqual({ temp: 38 });
        expect(a.state).toMatchObject({
            status: 'closed',
            revision: 9,
            values: { temp: 38 },
            unsaved: ['temp'],
            closedAs: 'released',
        });
        a.set('temp', 1);
        await a.flush();
        expect(fetchImpl).toHaveBeenCalledTimes(2);
    });

    it('keeps every edit on 423 (PIN lock), parks it and sends it after the way back through the lock screen', async () => {
        const mem = new Map<string, string>();
        const storage = { getItem: (k: string) => mem.get(k) ?? null, setItem: (k: string, v: string) => void mem.set(k, v), removeItem: (k: string) => void mem.delete(k) };
        const locked = vi.fn(async () => res(423, { message: 'locked', locked: true, redirect: '/enotf/lockscreen' }));
        const a = createEnotfAutosave({ url: '/enotf/p/1', revision: 2, values: {}, fetchImpl: locked as unknown as typeof fetch, debounceMs: 10_000, storage });
        a.set('diagnose', 'Sturz');
        await a.flush();
        expect(a.state.status).toBe('locked');
        expect(a.pending()).toBe(1);
        expect(JSON.parse(mem.get('enotf-draft:/enotf/p/1') ?? '{}')).toEqual({ diagnose: 'Sturz' });

        vi.useFakeTimers();
        const ok = vi.fn(async () => res(200, { revision: 3, applied: ['diagnose'], conflicts: [], changes_since: {} }));
        const b = createEnotfAutosave({ url: '/enotf/p/1', revision: 2, values: {}, fetchImpl: ok as unknown as typeof fetch, storage });
        expect(b.state.values.diagnose).toBe('Sturz');
        await vi.runAllTimersAsync();
        vi.useRealTimers();
        expect(sent(ok, 0)).toEqual({ revision: 2, fields: { diagnose: 'Sturz' } });
        expect(b.state.status).toBe('saved');
        expect(mem.size).toBe(0);
    });

    it('drops the POI display when a catalog destination is set and adopts destination_poi_id from other devices', () => {
        const a = createEnotfAutosave({
            url: '/enotf/p/1',
            revision: 1,
            values: { destination_poi_id: 5, ziel_poi: 'Klinik', ziel_adresse: { ort: 'X' }, transportziel: null },
            fetchImpl: vi.fn() as unknown as typeof fetch,
            debounceMs: 10_000,
        });
        a.set('transportziel', 2);
        expect(a.state.values).toMatchObject({ destination_poi_id: null, ziel_poi: null, ziel_adresse: null, transportziel: 2 });
        expect(a.pending()).toBe(1);
        a.pull({ revision: 4, changes_since: { ziel_poi: 'Andere', destination_poi_id: 7 } });
        expect(a.state.values).toMatchObject({ destination_poi_id: 7, ziel_poi: 'Andere' });
        a.stop();
    });

    it('flush during a save waits for it and then sends the edits made meanwhile', async () => {
        const releases: ((r: Response) => void)[] = [];
        const fetchImpl = vi.fn(() => new Promise<Response>((r) => releases.push(r)));
        const a = createEnotfAutosave({ url: '/enotf/p/1', revision: 1, values: {}, fetchImpl: fetchImpl as unknown as typeof fetch, debounceMs: 10_000, storage: null });
        a.set('temp', 37);
        void a.flush();
        a.set('pfname', 'X');
        let done = false;
        const second = a.flush().then(() => (done = true));
        await Promise.resolve();
        expect(fetchImpl).toHaveBeenCalledTimes(1);
        releases[0](res(200, { revision: 2, applied: ['temp'], conflicts: [], changes_since: {} }));
        await vi.waitFor(() => expect(fetchImpl).toHaveBeenCalledTimes(2));
        expect(sent(fetchImpl, 1)).toEqual({ revision: 2, fields: { pfname: 'X' } });
        expect(done).toBe(false);
        releases[1](res(200, { revision: 3, applied: ['pfname'], conflicts: [], changes_since: {} }));
        await second;
        expect(a.pending()).toBe(0);
        a.stop();
    });

    it('merge dialog: keeps mine without a write, takes theirs as a normal edit', async () => {
        const fetchImpl = vi.fn(async () =>
            res(200, { revision: 5, applied: ['diagnose', 'temp'], conflicts: [{ key: 'diagnose', value: 'Sturz', revision: 4 }, { key: 'temp', value: 36.5, revision: 4 }], changes_since: {} }),
        );
        const a = createEnotfAutosave({ url: '/enotf/p/1', revision: 3, values: {}, fetchImpl: fetchImpl as unknown as typeof fetch, debounceMs: 10_000 });
        a.set('diagnose', 'Synkope');
        a.set('temp', 37);
        await a.flush();
        expect(a.state.conflicts).toEqual(['diagnose', 'temp']);
        expect(a.state.theirs).toEqual({ diagnose: 'Sturz', temp: 36.5 });

        a.resolve('diagnose', 'mine');
        expect(a.state.values.diagnose).toBe('Synkope');
        expect(a.pending()).toBe(0);
        a.resolve('temp', 'theirs');
        expect(a.state.values.temp).toBe(36.5);
        expect(a.state.conflicts).toEqual([]);
        expect(a.state.status).toBe('saved');
        await a.flush();
        expect(sent(fetchImpl, 1)).toEqual({ revision: 5, fields: { temp: 36.5 } });
        a.stop();
    });

    it('a pending conflict survives the next save and a newer value from the other device replaces it', async () => {
        const replies = [
            { revision: 5, applied: ['diagnose'], conflicts: [{ key: 'diagnose', value: 'Sturz', revision: 4 }], changes_since: {} },
            { revision: 6, applied: ['pfname'], conflicts: [], changes_since: {} },
        ];
        const fetchImpl = vi.fn(async () => res(200, replies.shift()));
        const a = createEnotfAutosave({ url: '/enotf/p/1', revision: 3, values: {}, fetchImpl: fetchImpl as unknown as typeof fetch, debounceMs: 10_000 });
        a.set('diagnose', 'Synkope');
        await a.flush();
        a.set('pfname', 'Max');
        await a.flush();
        expect(a.state.conflicts).toEqual(['diagnose']);
        expect(a.state.status).toBe('conflict');

        expect(a.pull({ revision: 7, changes_since: { diagnose: 'Sturz, Kopfplatzwunde' } })).toBe(true);
        expect(a.state.values.diagnose).toBe('Sturz, Kopfplatzwunde');
        expect(a.state.theirs).toEqual({});
        expect(a.state.conflicts).toEqual([]);
        a.resolve('diagnose', 'theirs');
        expect(a.state.values.diagnose).toBe('Sturz, Kopfplatzwunde');
        a.stop();
    });
});
