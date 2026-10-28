import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { createApp, defineComponent } from 'vue';

type Cb = (...a: unknown[]) => void;
const h = vi.hoisted(() => ({
    props: {} as Record<string, unknown>,
    echoes: 0,
    left: [] as string[],
    handlers: new Map<string, Cb[]>(),
}));
const emit = (ev: string, data?: unknown) => (h.handlers.get(ev) ?? []).forEach((cb) => cb(data));
const bind = (ev: string, cb: Cb) => h.handlers.set(ev, [...(h.handlers.get(ev) ?? []), cb]);
const unbind = (ev: string, cb: Cb) => h.handlers.set(ev, (h.handlers.get(ev) ?? []).filter((x) => x !== cb));

vi.mock('@inertiajs/vue3', () => ({ usePage: () => ({ props: h.props }) }));
vi.mock('pusher-js', () => ({ default: class { connection = { bind: () => undefined }; } }));
vi.mock('laravel-echo', () => ({
    default: class {
        constructor() {
            h.echoes++;
        }
        private() {
            const ch = {
                subscription: { subscribed: false, unbind },
                listen: (ev: string, cb: Cb) => (bind(ev.slice(1), cb), ch),
                on: (ev: string, cb: Cb) => (bind(ev, cb), ch),
                stopListening: (ev: string, cb: Cb) => (unbind(ev.slice(1), cb), ch),
            };
            return ch;
        }
        leave(name: string) {
            h.left.push(name);
        }
    },
}));

const cfg = { key: 'k', host: 'ignis.test', port: 443, scheme: 'https', path: '' };
let onPulse: ReturnType<typeof vi.fn>;

async function mount() {
    const { usePulse } = await import('@/lib/realtime');
    const app = createApp(defineComponent({ setup: () => (usePulse('manv.1', ['board'], onPulse, { pollMs: 20_000, safetyMs: 60_000 }), () => null) }));
    app.mount(document.createElement('div'));
    await vi.dynamicImportSettled();
    for (let i = 0; i < 5; i++) await Promise.resolve();
    return app;
}

beforeEach(() => {
    vi.resetModules();
    vi.useFakeTimers();
    Object.assign(h, { props: {}, echoes: 0, left: [], handlers: new Map() });
    onPulse = vi.fn();
});
afterEach(() => vi.useRealTimers());

it('polls at the old rate and loads no client without the realtime prop', async () => {
    await mount();
    vi.advanceTimersByTime(40_000);
    expect(onPulse).toHaveBeenCalledTimes(2);
    expect(h.echoes).toBe(0);
});

it('drops to the safety rate when subscribed and debounces pulses of its topics', async () => {
    h.props.realtime = cfg;
    await mount();
    emit('pusher:subscription_succeeded');
    vi.advanceTimersByTime(59_000);
    expect(onPulse).not.toHaveBeenCalled();
    emit('pulse', { topic: 'board', id: 1 });
    emit('pulse', { topic: 'board', id: 1 });
    emit('pulse', { topic: 'list' });
    vi.advanceTimersByTime(300);
    expect(onPulse).toHaveBeenCalledTimes(1);
    expect(onPulse).toHaveBeenLastCalledWith({ topic: 'board', id: 1 });
});

it('reloads once after a reconnect and falls back to polling on a subscription error', async () => {
    h.props.realtime = cfg;
    await mount();
    emit('pusher:subscription_succeeded');
    emit('pusher:subscription_succeeded');
    expect(onPulse).not.toHaveBeenCalled();
    vi.advanceTimersByTime(5000);
    expect(onPulse).toHaveBeenCalledWith({ topic: 'reconnect' });
    onPulse.mockClear();
    emit('pusher:subscription_error', { status: 403 });
    vi.advanceTimersByTime(20_000);
    expect(onPulse).toHaveBeenCalledWith({ topic: 'poll' });
});

it('leaves the channel and stops polling on unmount', async () => {
    h.props.realtime = cfg;
    const app = await mount();
    app.unmount();
    vi.advanceTimersByTime(60_000);
    expect(h.left).toEqual(['manv.1']);
    expect(onPulse).not.toHaveBeenCalled();
});
