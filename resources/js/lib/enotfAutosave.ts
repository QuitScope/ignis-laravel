/**
 * eNOTF autosave: debounced PATCH of the dirty fields with the last known revision.
 * Other devices' changes (`changes_since`) are adopted unless the field has an unsent local edit (then the local value
 * stays and the field is listed as a conflict). Network errors, 5xx, 401/408/419/429 keep every edit and retry with
 * backoff (like asuClient); 422 drops only the rejected fields; 403/409 stop saving (protocol no longer writable).
 * 423 = PIN lock: every edit is kept and parked in sessionStorage; the page goes to the lock screen and,
 * back after unlocking, the new instance picks the parked edits up and sends them.
 * Framework-free; the page listens through `onChange`.
 */
import { xsrf } from '@/lib/asuClient';

export type AutosaveStatus = 'idle' | 'saving' | 'saved' | 'conflict' | 'offline' | 'auth' | 'invalid' | 'closed' | 'forbidden' | 'locked';

type DraftStore = Pick<Storage, 'getItem' | 'setItem' | 'removeItem'>;

function sessionStore(): DraftStore | null {
    try {
        return window.sessionStorage;
    } catch {
        return null;
    }
}

export interface PatchResponse {
    revision: number;
    applied: string[];
    conflicts: { key: string; value: unknown; revision: number }[];
    changes_since: Record<string, unknown>;
}

export interface AutosaveOptions {
    url: string;
    revision: number;
    values: Record<string, unknown>;
    fetchImpl?: typeof fetch;
    debounceMs?: number;
    onChange?: () => void;
    storage?: DraftStore | null;
}

export function createEnotfAutosave(o: AutosaveOptions) {
    const doFetch: typeof fetch = o.fetchImpl ?? ((...a) => fetch(...a));
    const s = {
        values: { ...o.values } as Record<string, unknown>,
        revision: o.revision,
        status: 'idle' as AutosaveStatus,
        conflicts: [] as string[],
        theirs: {} as Record<string, unknown>,
        invalid: [] as string[],
        message: '',
        closedAs: '',
        unsaved: [] as string[],
    };
    let dirty: Record<string, unknown> = {};
    const store = o.storage === undefined ? sessionStore() : o.storage;
    const draftKey = `enotf-draft:${o.url}`;
    const park = () => {
        try {
            store?.setItem(draftKey, JSON.stringify(dirty));
        } catch {
        }
    };
    const unpark = () => {
        try {
            store?.removeItem(draftKey);
        } catch {
        }
    };
    let busy = false;
    let stopped = false;
    let backoff = 1000;
    let timer: ReturnType<typeof setTimeout> | undefined;
    const changed = () => o.onChange?.();
    const schedule = (ms: number) => {
        clearTimeout(timer);
        if (!stopped) timer = setTimeout(() => void flush(), ms);
    };
    const restore = (batch: Record<string, unknown>) => {
        dirty = { ...batch, ...dirty };
    };
    const incoming = (key: string, value: unknown, keep: boolean) => {
        if (keep) s.theirs[key] = value;
        else {
            s.values[key] = value;
            delete s.theirs[key];
        }
        s.conflicts = Object.keys(s.theirs);
    };

    let inflight: Promise<void> | null = null;
    async function flush(): Promise<void> {
        clearTimeout(timer);
        while (inflight) await inflight;
        if (stopped || !Object.keys(dirty).length) return;
        inflight = send().finally(() => (inflight = null));
        await inflight;
    }

    async function send(): Promise<void> {
        busy = true;
        const batch = dirty;
        dirty = {};
        s.status = 'saving';
        changed();
        let res: Response | null = null;
        try {
            res = await doFetch(o.url, {
                method: 'PATCH',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': xsrf(),
                },
                body: JSON.stringify({ revision: s.revision, fields: batch }),
            });
        } catch {
            res = null;
        }
        const status = res?.status ?? 0;
        const body = (await res?.json().catch(() => null)) as Record<string, unknown> | null;
        busy = false;

        if (status >= 200 && status < 300 && body) {
            const d = body as unknown as PatchResponse;
            s.revision = d.revision;
            backoff = 1000;
            for (const [key, value] of Object.entries(d.changes_since ?? {})) incoming(key, value, key in dirty);
            for (const c of d.conflicts ?? []) incoming(c.key, c.value, true);
            s.status = s.conflicts.length ? 'conflict' : 'saved';
            s.invalid = s.invalid.filter((k) => !(k in batch));
        } else if (status === 422) {
            const errors = (body?.errors ?? {}) as Record<string, string[]>;
            const bad = Object.keys(errors).map((k) => k.replace(/^fields\./, ''));
            for (const k of bad) delete batch[k];
            s.invalid = [...new Set([...s.invalid, ...bad])];
            s.status = 'invalid';
            if (bad.length) restore(batch);
        } else if (status === 423) {
            restore(batch);
            park();
            s.status = 'locked';
            changed();
            return;
        } else if (status === 409 || status === 403) {
            stopped = true;
            s.status = status === 409 ? 'closed' : 'forbidden';
            s.message = typeof body?.message === 'string' ? body.message : '';
            restore(batch);
            s.unsaved = Object.keys(dirty);
            if (status === 409 && body?.values) {
                s.values = { ...(body.values as Record<string, unknown>), ...dirty };
                if (typeof body.revision === 'number') s.revision = body.revision;
                if (typeof body.status === 'string') s.closedAs = body.status;
            }
            changed();
            return;
        } else {
            restore(batch);
            s.status = status === 401 || status === 419 ? 'auth' : 'offline';
            schedule(backoff);
            backoff = Math.min(backoff * 2, 30000);
            changed();
            return;
        }
        changed();
        if (Object.keys(dirty).length) schedule(o.debounceMs ?? 800);
        else unpark();
    }

    try {
        const parked = JSON.parse(store?.getItem(draftKey) ?? 'null') as Record<string, unknown> | null;
        if (parked && typeof parked === 'object' && Object.keys(parked).length) {
            Object.assign(s.values, parked);
            dirty = { ...parked };
            schedule(0);
        }
    } catch {
    }

    const api = {
        state: s,
        set(key: string, value: unknown) {
            if (stopped) return;
            s.values[key] = value;
            dirty[key] = value;
            if (key === 'transportziel' && value !== null && value !== '' && s.values.destination_poi_id) {
                Object.assign(s.values, { destination_poi_id: null, ziel_poi: null, ziel_adresse: null });
            }
            s.conflicts = s.conflicts.filter((k) => k !== key);
            delete s.theirs[key];
            if (!busy) schedule(o.debounceMs ?? 800);
            changed();
        },
        adopt(d: PatchResponse, local: Record<string, unknown>) {
            s.revision = d.revision;
            for (const [key, value] of Object.entries(d.changes_since ?? {})) if (!(key in dirty)) incoming(key, value, false);
            for (const key of Object.keys(local)) delete s.theirs[key];
            Object.assign(s.values, local);
            for (const c of d.conflicts ?? []) incoming(c.key, c.value, true);
            s.conflicts = Object.keys(s.theirs);
            s.status = s.conflicts.length ? 'conflict' : 'saved';
            changed();
        },
        resolve(key: string, pick: 'mine' | 'theirs'): void {
            if (stopped || !s.conflicts.includes(key)) return;
            if (pick === 'theirs') api.set(key, s.theirs[key]);
            s.conflicts = s.conflicts.filter((k) => k !== key);
            delete s.theirs[key];
            if (!s.conflicts.length && s.status === 'conflict') s.status = 'saved';
            changed();
        },
        pull(d: { revision: number; changes_since: Record<string, unknown> }): boolean {
            if (busy || stopped || d.revision <= s.revision) return false;
            s.revision = d.revision;
            let kept = false;
            for (const [key, value] of Object.entries(d.changes_since ?? {})) {
                kept ||= key in dirty;
                incoming(key, value, key in dirty);
            }
            if (kept) s.status = 'conflict';
            else if (!s.conflicts.length && s.status === 'conflict') s.status = 'saved';
            changed();
            return true;
        },
        close(as: string) {
            if (stopped && s.status === 'closed') return;
            stopped = true;
            clearTimeout(timer);
            s.status = 'closed';
            s.closedAs = as;
            s.unsaved = Object.keys(dirty);
            changed();
        },
        flush,
        pending: () => Object.keys(dirty).length,
        stop() {
            stopped = true;
            clearTimeout(timer);
        },
    };
    return api;
}
