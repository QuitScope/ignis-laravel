/**
 * FireTab offline hint: the incident pages report every server contact they already make (Inertia visits and reloads,
 * the ASÜ poll); after more than 15 minutes of failing contact a hint shows. Counted from the first failure, not from the
 * last success: a page that simply made no request for a while is not offline. No own requests (fetch only, no EventSource).
 */
import { router } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted, ref } from 'vue';

export const OFFLINE_HINT_MS = 15 * 60_000;

export const offlineTooLong = (failingSince: number | null, now: number): boolean => failingSince !== null && now - failingSince > OFFLINE_HINT_MS;

const failingSince = ref<number | null>(null);

export function reportContact(ok: boolean, now = Date.now()): void {
    if (ok) failingSince.value = null;
    else failingSince.value ??= now;
}

export function useOfflineHint() {
    const show = ref(false);
    let timer: ReturnType<typeof setInterval> | undefined;
    const offs: (() => void)[] = [];
    const check = () => (show.value = offlineTooLong(failingSince.value, Date.now()));
    onMounted(() => {
        offs.push(
            router.on('success', () => reportContact(true)),
            router.on('error', () => reportContact(true)),
            router.on('exception', () => reportContact(false)),
            router.on('invalid', (e) => reportContact(e.detail.response.status < 500)),
        );
        timer = setInterval(check, 30_000);
        check();
    });
    onBeforeUnmount(() => {
        clearInterval(timer);
        offs.forEach((off) => off());
    });
    return show;
}
