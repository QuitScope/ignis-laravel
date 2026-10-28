/**
 * eNOTF device sync: the status poll (enotfSession, 10 s, paused while hidden) lists the open
 * protocols of the vehicle with their revision. When this protocol's revision is ahead of ours, or it is no longer listed
 * (released, withdrawn or hidden elsewhere), GET /enotf/p/{enr}/changes?since=<rev> brings what changed: the autosave
 * adopts it field by field (local unsaved edits stay); a protocol that is no longer open switches the page to read only.
 * Framework-free.
 */
import type { createEnotfAutosave } from '@/lib/enotfAutosave';

export interface StatusProtocol {
    enr: string;
    revision: number;
    status: string;
}

export interface ChangesResponse {
    revision: number;
    status: string;
    released: boolean;
    changes_since: Record<string, unknown>;
}

type Autosave = Pick<ReturnType<typeof createEnotfAutosave>, 'state' | 'pull' | 'close'>;

export function createEnotfSync(o: { url: string; enr: string; autosave: Autosave; fetchImpl?: typeof fetch }) {
    const doFetch: typeof fetch = o.fetchImpl ?? ((...a) => fetch(...a));
    let pulling = false;

    async function pull(): Promise<void> {
        if (pulling || o.autosave.state.status === 'closed') return;
        pulling = true;
        const res = await doFetch(`${o.url}/changes?since=${o.autosave.state.revision}`, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        }).catch(() => null);
        pulling = false;
        if (res?.status === 403 || res?.status === 404) return o.autosave.close('withdrawn');
        if (!res?.ok) return;
        const body = (await res.json().catch(() => null)) as ChangesResponse | null;
        if (!body) return;
        if (body.status !== 'open') o.autosave.close(body.status);
        else o.autosave.pull(body);
    }

    return {
        async onStatus(protocols: StatusProtocol[]): Promise<void> {
            const mine = protocols.find((p) => p.enr === o.enr);
            if (mine && mine.status === 'open' && mine.revision <= o.autosave.state.revision) return;
            await pull();
        },
        pull,
    };
}
