/**
 * Atemschutzüberwachung client: server clock offset, offline queue (localStorage), serial flush and 3 s polling.
 * Framework-free; the Board listens through `onChange`. Events and voids are queued, Trupp edits go straight through `request`.
 */
import type { AsuEventInput } from '@/lib/asuState';

export interface AsuTruppRow {
    id: number;
    name: string;
    role: string;
    task: string | null;
    expected_minutes: number;
    members: { id: number; position: string; name: string }[];
}
export interface AsuEventRow extends AsuEventInput {
    trupp_id: number;
    client_uuid?: string;
    pending?: boolean;
}
export interface AsuEventBody {
    trupp_id: number;
    type: string;
    member_id?: number | null;
    bar?: number | null;
    note?: string | null;
    client_uuid: string;
    occurred_at: number;
}
export type QueueItem = { kind: 'event'; body: AsuEventBody } | { kind: 'void'; eventId: number; uuid: string };

export interface AsuClientOptions {
    url: string;
    incidentId: number;
    storage?: Pick<Storage, 'getItem' | 'setItem' | 'removeItem'> | null;
    fetchImpl?: typeof fetch;
    clock?: () => number;
    onToast?: (status: number) => void;
    onChange?: () => void;
    pollMs?: number | (() => number);
    readOnly?: boolean;
}

export function uuid(c: Crypto | undefined = globalThis.crypto): string {
    if (c && typeof c.randomUUID === 'function') return c.randomUUID();
    const b = new Uint8Array(16);
    if (c && typeof c.getRandomValues === 'function') c.getRandomValues(b);
    else for (let i = 0; i < 16; i++) b[i] = Math.floor(Math.random() * 256);
    b[6] = (b[6] & 0x0f) | 0x40;
    b[8] = (b[8] & 0x3f) | 0x80;
    const h = Array.from(b, (x) => x.toString(16).padStart(2, '0')).join('');
    return `${h.slice(0, 8)}-${h.slice(8, 12)}-${h.slice(12, 16)}-${h.slice(16, 20)}-${h.slice(20)}`;
}

export const clockOffset = (serverNow: number, sentAt: number, receivedAt: number): number => serverNow - (sentAt + receivedAt) / 2;

export const xsrf = (): string => {
    try {
        const m = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
        return m ? decodeURIComponent(m[1]) : '';
    } catch {
        return '';
    }
};

export function createAsuClient(o: AsuClientOptions) {
    const doFetch: typeof fetch = o.fetchImpl ?? ((...a) => fetch(...a));
    const device = o.clock ?? (() => Date.now());
    const key = `asu.queue.${o.url}`; // per endpoint: the QM url never drains the crew queue
    let storage: AsuClientOptions['storage'] = null;
    try {
        storage = o.storage === undefined ? globalThis.localStorage : o.storage;
    } catch {
        storage = null;
    }

    let memory: QueueItem[] = [];
    const load = (): QueueItem[] => {
        try {
            const raw = storage?.getItem(key);
            if (raw) {
                const v = JSON.parse(raw);
                if (Array.isArray(v)) return v as QueueItem[];
            }
        } catch {
        }
        return memory;
    };
    const save = (q: QueueItem[]) => {
        memory = q;
        try {
            if (q.length) storage?.setItem(key, JSON.stringify(q));
            else storage?.removeItem(key);
        } catch {
        }
    };

    const s = {
        offset: 0,
        synced: false,
        trupps: [] as AsuTruppRow[],
        events: new Map<number, AsuEventRow>(),
        voided: new Set<number>(),
        etag: '',
        lastId: 0,
        online: true,
        writable: false,
        authExpired: false,
    };
    let backoff = 1000;
    let flushing = false;
    let pollTimer: ReturnType<typeof setTimeout> | undefined;
    let flushTimer: ReturnType<typeof setTimeout> | undefined;
    let stopped = false;
    let loops = 0;
    const changed = () => o.onChange?.();

    const headers = (json: boolean): Record<string, string> => ({
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        ...(json ? { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf() } : {}),
    });

    const now = () => device() + s.offset;

    async function request(method: string, path: string, body?: unknown): Promise<Response> {
        return doFetch(`${o.url}${path}`, { method, credentials: 'same-origin', headers: headers(body !== undefined || method !== 'GET'), body: body === undefined ? undefined : JSON.stringify(body) });
    }

    let polling: Promise<void> | null = null;
    let again = false;
    function poll(): Promise<void> {
        if (polling) {
            again = true;
            return polling;
        }
        return (polling = (async () => {
            do {
                again = false;
                await pollOnce();
            } while (again && !stopped);
        })().finally(() => (polling = null)));
    }

    async function pollOnce(): Promise<void> {
        const t0 = device();
        try {
            const q = new URLSearchParams({ since: String(s.lastId) });
            if (s.etag) q.set('trupps_etag', s.etag);
            const res = await doFetch(`${o.url}/state?${q}`, { credentials: 'same-origin', headers: headers(false) });
            const t1 = device();
            if (res.status === 401 || res.status === 419) s.authExpired = true;
            if (!res.ok) throw new Error(String(res.status));
            const d = (await res.json()) as { now: number; writable: boolean; trupps_etag: string; trupps: AsuTruppRow[] | null; events: AsuEventRow[]; voided: number[] };
            s.offset = clockOffset(d.now, t0, t1);
            s.synced = true;
            s.online = true;
            s.authExpired = false;
            s.writable = d.writable;
            s.etag = d.trupps_etag;
            if (d.trupps) s.trupps = d.trupps;
            for (const e of d.events) {
                s.events.set(e.id, e);
                s.lastId = Math.max(s.lastId, e.id);
            }
            s.voided = new Set(d.voided);
            if (load().length && canSend()) void flush();
        } catch {
            s.online = false;
        }
        changed();
    }

    function view(): AsuEventRow[] {
        const rows: AsuEventRow[] = [];
        const known = new Set<string>();
        for (const e of s.events.values()) {
            if (e.client_uuid) known.add(e.client_uuid);
            rows.push({ ...e, voided: e.voided || s.voided.has(e.id) });
        }
        let n = 0;
        const q = load();
        for (const it of q) {
            if (it.kind === 'event' && !known.has(it.body.client_uuid)) {
                rows.push({ id: -(++n), trupp_id: it.body.trupp_id, type: it.body.type, at: it.body.occurred_at, member_id: it.body.member_id ?? null, bar: it.body.bar ?? null, note: it.body.note ?? null, client_uuid: it.body.client_uuid, pending: true });
            }
        }
        const voids = new Set(q.filter((i): i is Extract<QueueItem, { kind: 'void' }> => i.kind === 'void').map((i) => i.eventId));
        return rows.map((r) => (voids.has(r.id) ? { ...r, voided: true } : r));
    }

    const idOf = (i: QueueItem) => (i.kind === 'event' ? i.body.client_uuid : i.uuid);
    const drop = (it: QueueItem) => save(load().filter((x) => idOf(x) !== idOf(it)));
    const canSend = () => !o.readOnly && !(s.synced && !s.writable);

    async function flush(): Promise<void> {
        if (flushing || stopped || !canSend()) return;
        flushing = true;
        clearTimeout(flushTimer);
        try {
            for (;;) {
                const q = load();
                if (!q.length || stopped) break;
                const it = q[0];
                let status = 0;
                try {
                    const res = it.kind === 'event' ? await request('POST', '/events', it.body) : await request('POST', `/events/${it.eventId}/void`, {});
                    status = res.status;
                } catch {
                    status = 0;
                }
                if (status >= 200 && status < 300) {
                    drop(it);
                    if (it.kind === 'void') s.voided.add(it.eventId);
                    backoff = 1000;
                    s.online = true;
                    s.authExpired = false;
                } else if (status >= 400 && status < 500 && ![401, 408, 419, 429].includes(status)) {
                    drop(it);
                    o.onToast?.(status);
                } else {
                    if (status === 401 || status === 419) s.authExpired = true;
                    else s.online = false;
                    if (!stopped) flushTimer = setTimeout(() => void flush(), backoff);
                    backoff = Math.min(backoff * 2, 30000);
                    break;
                }
            }
        } finally {
            flushing = false;
            changed();
        }
    }

    function restart() {
        const id = ++loops;
        clearTimeout(pollTimer);
        const loop = async () => {
            if (stopped || id !== loops) return;
            if (document.visibilityState === 'visible') await poll();
            if (!stopped && id === loops) pollTimer = setTimeout(loop, typeof o.pollMs === 'function' ? o.pollMs() : (o.pollMs ?? 3000));
        };
        void loop();
    }

    function enqueue(item: QueueItem) {
        save([...load(), item]);
        changed();
        void flush();
    }

    return {
        state: s,
        now,
        poll,
        flush,
        view,
        request,
        pending: () => load().length,
        record(e: Omit<AsuEventBody, 'client_uuid' | 'occurred_at'>) {
            enqueue({ kind: 'event', body: { ...e, client_uuid: uuid(), occurred_at: Math.round(now()) } });
        },
        voidEvent(eventId: number) {
            enqueue({ kind: 'void', eventId, uuid: uuid() });
        },
        start() {
            stopped = false;
            restart();
            void flush();
        },
        restart,
        stop() {
            stopped = true;
            clearTimeout(pollTimer);
            clearTimeout(flushTimer);
        },
    };
}
