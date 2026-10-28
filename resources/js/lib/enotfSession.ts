/**
 * Tablet side of the eNOTF crew session: polls GET /enotf/status every 10 s (paused
 * while the page is hidden, If-None-Match → 304), sends the PIN heartbeat on user input (at most once a minute; the poll
 * itself is no activity) and leaves for the lock screen when the crew is locked, or for /enotf/loggedout when the crew
 * this page had is gone. Pages without a crew (QM reading a protocol) only poll.
 */
import { onBeforeUnmount, onMounted, ref, type Ref } from 'vue';
import { xsrf } from '@/lib/asuClient';
import { usePulse } from '@/lib/realtime';

export const lockscreenUrl = (): string => `/enotf/lockscreen?return=${encodeURIComponent(location.pathname + location.search)}`;

export interface EnotfStatus {
    crew: { vehicle_id: number } | null;
    locked: boolean;
    protocols: { enr: string; revision: number; status: string }[];
    shares: { id: number; enr: string; from: string; expires_at: string }[];
    emd_last_sync_at?: string | null;
    time?: string;
}

export function useEnotfSession(
    onStatus?: (status: EnotfStatus) => void,
    onTime?: (serverNow: string) => void,
): { vehicle: Ref<number | null>; emdSyncAt: Ref<string | null> } {
    let etag = '';
    let hadCrew = false;
    let lastBeat = 0;
    const vehicle = ref<number | null>(null);
    const emdSyncAt = ref<string | null>(null);

    const poll = async () => {
        if (document.hidden) return;
        const headers: Record<string, string> = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
        if (etag) headers['If-None-Match'] = etag;
        const res = await fetch('/enotf/status', { credentials: 'same-origin', headers }).catch(() => null);
        const date = res?.headers.get('Date');
        if (res?.status === 304 && date) onTime?.(new Date(date).toISOString());
        if (res?.status !== 200) return;
        etag = res.headers.get('ETag') ?? '';
        const body = (await res.json().catch(() => null)) as EnotfStatus | null;
        if (!body) return;
        if (body.time) onTime?.(body.time);
        vehicle.value = body.crew?.vehicle_id ?? null;
        emdSyncAt.value = body.emd_last_sync_at ?? null;
        if (body.locked) location.assign(lockscreenUrl());
        else if (body.crew) {
            hadCrew = true;
            onStatus?.(body);
        } else if (hadCrew) location.assign('/enotf/loggedout');
    };
    const beat = () => {
        if (Date.now() - lastBeat < 60_000) return;
        lastBeat = Date.now();
        fetch('/enotf/pin-activity', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrf() },
        })
            .then((r) => {
                if (r.status === 423) location.assign(lockscreenUrl());
            })
            .catch(() => undefined);
    };
    const onVisible = () => void poll();
    usePulse(() => (vehicle.value ? `enotf.vehicle.${vehicle.value}` : null), ['status'], () => void poll(), { pollMs: 10_000, safetyMs: 10_000 });

    onMounted(() => {
        void poll();
        document.addEventListener('visibilitychange', onVisible);
        window.addEventListener('pageshow', onVisible); // bfcache restore: re-sync the clock at once
        window.addEventListener('pointerdown', beat);
        window.addEventListener('keydown', beat);
    });
    onBeforeUnmount(() => {
        document.removeEventListener('visibilitychange', onVisible);
        window.removeEventListener('pageshow', onVisible);
        window.removeEventListener('pointerdown', beat);
        window.removeEventListener('keydown', beat);
    });

    return { vehicle, emdSyncAt };
}
