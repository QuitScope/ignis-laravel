import { createApp, nextTick } from 'vue';
import { afterEach, describe, expect, it, vi } from 'vitest';

vi.mock('@/lib/draftSave', () => ({ xsrfToken: () => 'tok' }));

const props = { can: { create: true, manage: false }, roles: [], timezone: 'Europe/Berlin', options: { colors: ['accent'], categories: ['general', 'absence'], visibilities: ['all'] }, openEvent: null, openNew: false };
const item = (over: Record<string, unknown>) => ({
    id: 1, occurrence_start: '2026-11-10', occurrence_end: '2026-11-10', all_day: true, title: 'Abwesend: Anna A', color: 'gray', category: 'absence', location: null,
    attendee_count: null, my_response: null, can_edit: false, recurring: false, source_locked: true, visibility: 'all', ...over,
});

afterEach(() => vi.unstubAllGlobals());

function mountPage(component: object, pageProps: object) {
    const el = document.createElement('div');
    document.body.appendChild(el);
    const app = createApp(component, pageProps);
    app.mount(el);

    return { el, unmount: () => { app.unmount(); el.remove(); } };
}
const flushPromises = async () => { await nextTick(); await new Promise((r) => setTimeout(r, 0)); };

describe('Calendar/Index', () => {
    it('loads the feed for the visible range and shows events and the absence bar', async () => {
        vi.useFakeTimers({ toFake: ['Date'] });
        vi.setSystemTime(new Date('2026-11-10T09:00:00Z'));
        const urls: string[] = [];
        vi.stubGlobal('fetch', vi.fn(async (url: string) => {
            urls.push(url);

            return new Response(JSON.stringify([item({}), item({ id: 2, title: 'Übung', category: 'general', color: 'green', source_locked: false, all_day: false, occurrence_start: '2026-11-10T08:00:00Z', occurrence_end: '2026-11-10T09:00:00Z' })]), { status: 200 });
        }));
        const { default: Index } = await import('@/Pages/Calendar/Index.vue');
        const wrapper = mountPage(Index, props);
        await flushPromises();
        await vi.waitFor(() => expect(wrapper.el.textContent).toContain('Übung'));

        const range = urls.find((u) => u.startsWith('/calendar/events?from=2026-10-26'));
        expect(range).toContain('to=2026-12-07');
        expect(wrapper.el.querySelector('[data-test="absence-today"]')?.textContent).toContain('Anna A');
        expect(wrapper.el.querySelector('.cal-locked') !== null).toBe(true);
        expect(wrapper.el.querySelector('[data-test="new"]') !== null).toBe(true);
        wrapper.unmount();
        vi.useRealTimers();
    });

    it('shows a visible error when the feed fails and hides the create button without the right', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => new Response('{}', { status: 500 })));
        const { default: Index } = await import('@/Pages/Calendar/Index.vue');
        const wrapper = mountPage(Index, { ...props, can: { create: false, manage: false } });
        await flushPromises();
        await vi.waitFor(() => expect(wrapper.el.querySelector('[data-test="load-error"]') !== null).toBe(true));
        expect(wrapper.el.querySelector('[data-test="new"]') !== null).toBe(false);
        wrapper.unmount();
    });
});

describe('EventDialog', () => {
    it('checks the start weekday for a weekly series and posts wall times with the XSRF header', async () => {
        const calls: { url: string; init: RequestInit }[] = [];
        vi.stubGlobal('fetch', vi.fn(async (url: string, init: RequestInit) => {
            calls.push({ url, init });

            return new Response(JSON.stringify({ id: 5, conflicts: [] }), { status: 201 });
        }));
        const { default: EventDialog } = await import('@/components/calendar/EventDialog.vue');
        const saved = vi.fn();
        const wrapper = mountPage(EventDialog, {
            open: true, detail: null, initial: { start: '2026-11-09T09:00', end: '2026-11-09T10:00', allDay: false }, roles: [], timezone: 'Europe/Berlin',
            options: props.options, onSaved: saved,
        });
        await flushPromises();
        const dlg = document.body;
        const set = async (sel: string, value: string) => {
            const el = dlg.querySelector(sel) as HTMLInputElement | HTMLSelectElement;
            el.value = value;
            el.dispatchEvent(new Event(el.tagName === 'SELECT' ? 'change' : 'input', { bubbles: true }));
            await flushPromises();
        };
        await set('#ev-title', 'Übung');
        await set('#ev-freq', 'WEEKLY');
        const boxes = [...dlg.querySelectorAll('[role="group"] input[type="checkbox"]')] as HTMLInputElement[];
        expect(boxes[0].checked).toBe(true);
        expect(boxes[0].disabled).toBe(true);
        expect(dlg.querySelector('[data-test="rule-summary"]')?.textContent).toContain('FREQ=WEEKLY;INTERVAL=1;BYDAY=MO');

        (dlg.querySelector('form') as HTMLFormElement).dispatchEvent(new Event('submit', { cancelable: true }));
        await vi.waitFor(() => expect(calls.length).toBe(1));
        expect(calls[0].url).toBe('/calendar');
        expect((calls[0].init.headers as Record<string, string>)['X-XSRF-TOKEN']).toBe('tok');
        expect(JSON.parse(String(calls[0].init.body))).toMatchObject({ title: 'Übung', starts_at: '2026-11-09T09:00', ends_at: '2026-11-09T10:00', all_day: false, recurrence_rule: 'FREQ=WEEKLY;INTERVAL=1;BYDAY=MO', recurrence_until: null });
        await vi.waitFor(() => expect(saved).toHaveBeenCalled());
        wrapper.unmount();
    });

    it('shows server errors per field', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => new Response(JSON.stringify({ message: 'x', errors: { recurrence_rule: ['Der Beginn muss auf einem der gewählten Wochentage liegen.'] } }), { status: 422 })));
        const { default: EventDialog } = await import('@/components/calendar/EventDialog.vue');
        const wrapper = mountPage(EventDialog, { open: true, detail: null, initial: { start: '2026-11-09T09:00', end: '2026-11-09T10:00', allDay: false }, roles: [], timezone: 'Europe/Berlin', options: props.options });
        await flushPromises();
        (document.body.querySelector('form') as HTMLFormElement).dispatchEvent(new Event('submit', { cancelable: true }));
        await vi.waitFor(() => expect(document.body.textContent).toContain('gewählten Wochentage'));
        wrapper.unmount();
    });
});

describe('SubscriptionDialog', () => {
    it('shows the link once after creating it, warns that the old one stops, and revokes', async () => {
        const calls: { url: string; init: RequestInit }[] = [];
        const link = 'http://localhost/api/calendar/ical/' + 'a'.repeat(64);
        vi.stubGlobal('fetch', vi.fn(async (url: string, init: RequestInit) => {
            calls.push({ url, init });
            if (init.method === 'POST') return new Response(JSON.stringify({ url: link }), { status: 200 });
            if (init.method === 'DELETE') return new Response(null, { status: 204 });

            return new Response(JSON.stringify({ has_subscription: true }), { status: 200 });
        }));
        const { default: SubscriptionDialog } = await import('@/components/calendar/SubscriptionDialog.vue');
        const wrapper = mountPage(SubscriptionDialog, { open: true });
        const body = document.body;
        await vi.waitFor(() => expect(body.querySelector('[data-test="subscription-state"]')?.textContent).toContain('calendar.subscription.active'));
        expect(body.querySelector('[data-test="subscription-url"]')).toBeNull();

        (body.querySelector('[data-test="subscription-generate"]') as HTMLButtonElement).click();
        await vi.waitFor(() => expect((body.querySelector('#calendar-subscription-url') as HTMLInputElement | null)?.value).toBe(link));
        expect(calls[1].url).toBe('/calendar/subscription');
        expect((calls[1].init.headers as Record<string, string>)['X-XSRF-TOKEN']).toBe('tok');
        expect(body.textContent).toContain('calendar.subscription.replaced');

        (body.querySelector('[data-test="subscription-revoke"]') as HTMLButtonElement).click();
        await vi.waitFor(() => expect(body.querySelector('[data-test="subscription-state"]')?.textContent).toContain('calendar.subscription.none'));
        expect(calls[2].init.method).toBe('DELETE');
        expect(body.querySelector('#calendar-subscription-url')).toBeNull();
        wrapper.unmount();
    });

    it('tells an expired session apart', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => new Response('{}', { status: 419 })));
        const { default: SubscriptionDialog } = await import('@/components/calendar/SubscriptionDialog.vue');
        const wrapper = mountPage(SubscriptionDialog, { open: true });
        await vi.waitFor(() => expect(document.body.querySelector('[role="alert"]')?.textContent).toContain('calendar.error.session'));
        wrapper.unmount();
    });
});
