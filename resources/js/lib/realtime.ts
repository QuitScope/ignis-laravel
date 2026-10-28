/**
 * Realtime: the server sends a content-free pulse `{topic, id}` on a private channel, the
 * page reloads through its usual endpoint. Polling stays the default and the fallback: without the `realtime` prop,
 * without a connection or after a refused subscription every page polls exactly at its old rate. Connected and
 * subscribed, the poll drops to a safety rate (60 s, bell 120 s); after a reconnect the page reloads once, after a
 * random 0-5 s (a Reverb restart reconnects every page at the same moment).
 * laravel-echo and pusher-js are loaded lazily (dynamic import) on the first subscription, so pages without the prop
 * never load them. Chromium 103 (FiveM CEF): only WebSocket + fetch, no HTTP fallback transports.
 */
import { usePage } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted, ref, watch, type Ref } from 'vue';
import type Echo from 'laravel-echo';
import type { ChannelAuthorizationHandler } from 'pusher-js';
import { xsrf } from '@/lib/asuClient';

export type RealtimeConfig = { key: string; host: string; port: number; scheme: string; path: string };
export type Pulse = { topic: string; id?: number };
export type RealtimeState = 'off' | 'initialized' | 'connecting' | 'connected' | 'unavailable' | 'failed' | 'disconnected';
type Client = Echo<'reverb'>;
type PulseChannel = ReturnType<Client['private']>;

export const realtimeState = ref<RealtimeState>('off');
let client: Promise<Client | null> | undefined;
const refs = new Map<string, number>();

const authorize =
    (path: string): ChannelAuthorizationHandler =>
    ({ socketId, channelName }, done) => {
        fetch(`${path}/broadcasting/auth`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrf() },
            body: JSON.stringify({ socket_id: socketId, channel_name: channelName }),
        })
            .then((r) => (r.ok ? r.json() : Promise.reject(new Error(`broadcasting auth ${r.status}`))))
            .then(
                (d) => done(null, d),
                (e: Error) => done(e, null),
            );
    };

export function echo(cfg: RealtimeConfig | null | undefined): Promise<Client | null> {
    if (!cfg?.key) return Promise.resolve(null);
    return (client ??= (async () => {
        try {
            const [{ default: Echo }, { default: Pusher }] = await Promise.all([import('laravel-echo'), import('pusher-js')]);
            const pusher = new Pusher(cfg.key, {
                cluster: '',
                wsHost: cfg.host,
                wsPort: cfg.port,
                wssPort: cfg.port,
                wsPath: cfg.path,
                forceTLS: cfg.scheme === 'https',
                enabledTransports: ['ws', 'wss'],
                disableStats: true,
                channelAuthorization: { customHandler: authorize(cfg.path) },
            });
            realtimeState.value = 'connecting';
            pusher.connection.bind('state_change', (s: { current: RealtimeState }) => (realtimeState.value = s.current));
            return new Echo({ broadcaster: 'reverb', key: cfg.key, client: pusher });
        } catch {
            realtimeState.value = 'failed';
            return null;
        }
    })());
}

export interface PulseOptions {
    pollMs: number;
    safetyMs?: number;
    whileHidden?: boolean;
}

export function usePulse(channel: string | (() => string | null), topics: string[], onPulse: (p: Pulse) => void, o: PulseOptions): { live: Readonly<Ref<boolean>> } {
    const cfg = (usePage().props as { realtime?: RealtimeConfig | null }).realtime;
    const live = ref(false);
    let stopped = false;
    let missed = false;
    let timer: ReturnType<typeof setTimeout> | undefined;
    let debounce: ReturnType<typeof setTimeout> | undefined;
    let jitter: ReturnType<typeof setTimeout> | undefined;
    let joined: { name: string; ch: PulseChannel; e: Client } | null = null;
    let subscriptions = 0;

    const visible = () => o.whileHidden || !document.hidden;
    const fire = (p: Pulse) => {
        if (stopped) return;
        if (visible()) onPulse(p);
        else missed = true;
    };
    const schedule = () => {
        clearTimeout(timer);
        const ms = o.pollMs > 0 && live.value ? (o.safetyMs ?? 60_000) : o.pollMs;
        if (!stopped && ms > 0) timer = setTimeout(tick, ms);
    };
    const tick = () => {
        if (visible()) onPulse({ topic: 'poll' });
        schedule();
    };
    const onMessage = (p: Pulse) => {
        if (!p || !topics.includes(p.topic)) return;
        clearTimeout(debounce);
        debounce = setTimeout(() => fire(p), 300);
    };
    const onSubscribed = () => {
        if (stopped) return;
        live.value = true;
        if (subscriptions++ > 0) {
            clearTimeout(jitter);
            jitter = setTimeout(() => fire({ topic: 'reconnect' }), Math.random() * 5000);
        }
    };
    const onError = () => (live.value = false);
    const onVisible = () => {
        if (!document.hidden && missed) {
            missed = false;
            fire({ topic: 'visible' });
        }
    };

    const leave = () => {
        if (!joined) return;
        const { name, ch, e } = joined;
        ch.stopListening('.pulse', onMessage);
        ch.subscription.unbind('pusher:subscription_succeeded', onSubscribed);
        ch.subscription.unbind('pusher:subscription_error', onError);
        const n = (refs.get(name) ?? 1) - 1;
        if (n > 0) refs.set(name, n);
        else {
            refs.delete(name);
            e.leave(name);
        }
        joined = null;
        live.value = false;
    };
    const join = async (name: string | null) => {
        leave();
        subscriptions = 0;
        const e = name ? await echo(cfg) : null;
        if (!e || !name || stopped) return;
        const ch = e.private(name);
        refs.set(name, (refs.get(name) ?? 0) + 1);
        ch.listen('.pulse', onMessage).on('pusher:subscription_succeeded', onSubscribed).on('pusher:subscription_error', onError);
        joined = { name, ch, e };
        if (ch.subscription.subscribed && realtimeState.value === 'connected') onSubscribed();
    };

    watch(live, schedule, { flush: 'sync' });
    watch(realtimeState, (s) => {
        if (s !== 'connected') live.value = false;
    });
    onMounted(() => {
        document.addEventListener('visibilitychange', onVisible);
        schedule();
        if (cfg) watch(typeof channel === 'string' ? () => channel : channel, (name) => void join(name), { immediate: true });
    });
    onBeforeUnmount(() => {
        stopped = true;
        clearTimeout(timer);
        clearTimeout(debounce);
        clearTimeout(jitter);
        document.removeEventListener('visibilitychange', onVisible);
        leave();
    });

    return { live };
}
