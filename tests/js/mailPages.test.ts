import { createApp, defineComponent, h, nextTick, reactive } from 'vue';
import { afterEach, describe, expect, it, vi } from 'vitest';

vi.mock('@/lib/draftSave', () => ({ xsrfToken: () => 'tok' }));
const router = vi.hoisted(() => ({ get: vi.fn(), reload: vi.fn(), visit: vi.fn() }));
vi.mock('@inertiajs/vue3', () => ({
    router,
    usePage: () => ({ props: { auth: { user: null } } }), // no `realtime` prop: usePulse stays off
    Link: defineComponent({ props: { href: String }, setup: (p, { slots }) => () => h('a', { href: p.href }, slots.default?.()) }),
}));

afterEach(() => {
    vi.unstubAllGlobals();
    vi.useRealTimers();
    document.body.innerHTML = '';
});

function mount(component: object, props: object) {
    const el = document.createElement('div');
    document.body.appendChild(el);
    const app = createApp(component, props);
    app.mount(el);

    return { el, unmount: () => app.unmount() };
}
const flush = async () => { await nextTick(); await new Promise((r) => setTimeout(r, 0)); };
const mailbox = { id: 3, address: 'a.absender@ignis.ef', display_name: 'Alice Absender', kind: 'personal' as const };

vi.setConfig({ testTimeout: 30_000 });

describe('Mail/Index', () => {
    it('renders rows and the reading pane and marks an unread mail read by PATCH', async () => {
        const calls: [string, RequestInit][] = [];
        vi.stubGlobal('fetch', vi.fn(async (url: string, init: RequestInit) => {
            calls.push([url, init]);
            return new Response('{"read":true}', { status: 200 });
        }));
        const { default: Index } = await import('@/Pages/Mail/Index.vue');
        const row = { id: 9, subject: 'Wachplan <b>', from: { name: 'Bob', address: 'b@ignis.ef' }, to: [], date: null, unread: true, flagged: false, draft: false, preview: 'Hallo' };
        const message = { id: 9, folder: 'inbox', role: 'to', subject: 'Wachplan <b>', from: row.from, sentBy: null, header: { to: ['a@ignis.ef'], cc: [], bcc: [] }, date: null, html: '<p>Text</p>', broken: false, draft: false, flagged: false, unread: true };
        const page = mount(Index, {
            folder: 'inbox', state: 'ok', draftDays: 30, selected: 3, mailbox, mailboxes: [{ ...mailbox, unread: 1 }],
            counts: { inbox: 1 }, messages: { data: [row], next: null, prev: null }, message,
        });
        await flush();

        expect(page.el.querySelector('[role="option"]')?.textContent).toContain('Wachplan <b>');
        expect(page.el.querySelector('[role="option"] b')).toBeNull();
        expect(page.el.querySelector('.mail-prose')?.innerHTML).toBe('<p>Text</p>');
        expect(page.el.querySelector('a[href="/mail/compose/reply-all/9?mailbox=3"]')).not.toBeNull();
        await vi.waitFor(() => expect(calls).toHaveLength(1));
        expect(calls[0][0]).toBe('/mail/messages/9/read');
        expect(calls[0][1].method).toBe('PATCH');
        expect(JSON.parse(String(calls[0][1].body))).toEqual({ read: true, mailbox_id: 3 });

        (page.el.querySelector('[role="option"]') as HTMLElement).dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter' }));
        expect(router.get).toHaveBeenCalledWith('/mail/inbox/9?mailbox=3', {}, expect.objectContaining({ only: ['message'] }));
        page.unmount();
    });

    it('keeps a mail marked unread unread, and marks it read again when it is opened later', async () => {
        const reads: boolean[] = [];
        vi.stubGlobal('fetch', vi.fn(async (_url: string, init: RequestInit) => {
            reads.push(JSON.parse(String(init.body)).read);
            return new Response('{}', { status: 200 });
        }));
        const { default: Index } = await import('@/Pages/Mail/Index.vue');
        const msg = (unread: boolean) => ({ id: 9, folder: 'inbox', role: 'to', subject: 'S', from: { name: 'Bob', address: 'b@ignis.ef' }, sentBy: null, header: { to: [], cc: [], bcc: [] }, date: null, html: '', broken: false, draft: false, flagged: false, unread });
        const state = reactive({
            folder: 'inbox', state: 'ok', draftDays: 30, selected: 3, mailbox, mailboxes: [{ ...mailbox, unread: 1 }],
            counts: {}, messages: { data: [], next: null, prev: null }, message: msg(true) as ReturnType<typeof msg> | null,
        });
        router.reload.mockImplementation((o: { only: string[] }) => {
            if (o.only.includes('message') && state.message) state.message = msg(true);
        });
        router.visit.mockImplementation(() => {
            state.message = null;
        });
        const page = mount(defineComponent(() => () => h(Index, state)), {});
        await vi.waitFor(() => expect(reads).toEqual([true]));

        const button = [...page.el.querySelectorAll('button')].find((b) => b.textContent?.includes('mail.action.unread')) as HTMLButtonElement;
        button.click();
        await vi.waitFor(() => expect(reads).toEqual([true, false]));
        await flush();
        await flush();
        expect(reads).toEqual([true, false]);

        state.message = msg(true);
        await vi.waitFor(() => expect(reads).toEqual([true, false, true]));
        page.unmount();
        router.reload.mockReset();
        router.visit.mockReset();
    });

    it('explains a missing mailbox instead of the workspace', async () => {
        const { default: Index } = await import('@/Pages/Mail/Index.vue');
        const page = mount(Index, { folder: 'inbox', state: 'none', draftDays: 30, selected: null, mailbox: null, mailboxes: [], counts: {}, messages: { data: [], next: null, prev: null }, message: null });
        expect(page.el.querySelector('[role="status"]')).not.toBeNull();
        expect(page.el.querySelector('[role="listbox"]')).toBeNull();
        page.unmount();
    });
});

describe('ComposeForm', () => {
    it('creates the draft once, then saves in place, and stops after a 409', async () => {
        vi.useFakeTimers({ shouldAdvanceTime: true });
        const calls: [string, string, Record<string, unknown>][] = [];
        let put = 0;
        vi.stubGlobal('fetch', vi.fn(async (url: string, init: RequestInit) => {
            calls.push([init.method ?? 'GET', url, JSON.parse(String(init.body ?? '{}'))]);
            if (init.method === 'POST') return new Response('{"messageId":41}', { status: 201 });
            put++;
            return new Response(put === 1 ? '{"messageId":41}' : '{"message":"Schon gesendet"}', { status: put === 1 ? 200 : 409 });
        }));
        const { default: ComposeForm } = await import('@/components/mail/ComposeForm.vue');
        const form = mount(ComposeForm, {
            mode: 'reply', mailbox, draftId: null, subject: 'Re: X', recipients: { to: ['b@ignis.ef'], cc: [], bcc: [] },
            body: { type: 'doc', content: [{ type: 'paragraph' }] }, inReplyTo: 7, forwardFrom: null, forwardNames: [],
        });
        const subject = form.el.querySelector('input[maxlength="255"]') as HTMLInputElement;
        const type = async (v: string) => {
            subject.value = v;
            subject.dispatchEvent(new Event('input'));
            await flush();
            await vi.advanceTimersByTimeAsync(1600);
            await flush();
        };

        await type('Re: X 1');
        await type('Re: X 2');
        await type('Re: X 3');
        await type('Re: X 4');

        expect(calls.map(([m, u]) => `${m} ${u}`)).toEqual(['POST /mail/drafts', 'PUT /mail/drafts/41', 'PUT /mail/drafts/41']);
        expect(calls[0][2]).toMatchObject({ mailbox_id: 3, subject: 'Re: X 1', to: ['b@ignis.ef'], in_reply_to: 7 });
        expect(form.el.querySelector('[role="alert"]')?.textContent).toContain('Schon gesendet');
        expect((form.el.querySelector('button[type="submit"]') as HTMLButtonElement).disabled).toBe(true);
        form.unmount();
    });

    it('creates the forward draft before an upload, shows progress, errors and removes an attachment', async () => {
        const calls: string[] = [];
        vi.stubGlobal('fetch', vi.fn(async (url: string, init: RequestInit) => {
            calls.push(`${init.method} ${url}`);
            if (init.method === 'POST') return new Response('{"messageId":41,"attachments":[{"id":5,"name":"original.pdf","size":2048}]}', { status: 201 });
            return new Response('{"deleted":true}', { status: 200 });
        }));
        const xhrs: { url: string; upload: { onprogress: ((e: object) => void) | null }; onload: (() => void) | null; status: number; responseText: string }[] = [];
        vi.stubGlobal('XMLHttpRequest', class {
            url = '';
            status = 0;
            responseText = '';
            upload = { onprogress: null };
            onload: (() => void) | null = null;
            constructor() { xhrs.push(this); }
            open(_m: string, url: string) { this.url = url; }
            setRequestHeader() {}
            send() {}
        });
        const { default: ComposeForm } = await import('@/components/mail/ComposeForm.vue');
        const form = mount(ComposeForm, {
            mode: 'forward', mailbox, draftId: null, subject: 'Fwd: X', recipients: { to: [], cc: [], bcc: [] },
            body: { type: 'doc', content: [{ type: 'paragraph' }] }, inReplyTo: null, forwardFrom: 7, forwardNames: ['original.pdf'], attachments: [],
        });
        expect(form.el.textContent).toContain('mail.attachment.forwarded'); // the names go along when the draft is stored
        const input = form.el.querySelector('input[type="file"]') as HTMLInputElement;
        const pick = async (files: File[]) => {
            Object.defineProperty(input, 'files', { value: files, configurable: true });
            input.dispatchEvent(new Event('change'));
            await flush();
            await flush();
        };
        const send = form.el.querySelector('button[type="submit"]') as HTMLButtonElement;

        await pick([new File(['x'.repeat(5 * 1024 * 1024 + 1)], 'gross.txt'), new File(['Plan'], 'plan.txt')]);
        expect(calls).toEqual(['POST /mail/drafts']); // the draft first, the big file never leaves the browser
        expect(xhrs.map((x) => x.url)).toEqual(['/mail/drafts/41/attachments']);
        expect(form.el.querySelector('[role="alert"]')?.textContent).toContain('mail.attachment.too_large');
        xhrs[0].upload.onprogress?.({ lengthComputable: true, loaded: 1, total: 4 });
        await flush();
        expect((form.el.querySelector('progress') as HTMLProgressElement).value).toBe(25);
        expect(send.disabled).toBe(true);
        Object.assign(xhrs[0], { status: 201, responseText: '{"attachment":{"id":6,"name":"plan.txt","size":4}}' });
        xhrs[0].onload?.();
        await flush();
        expect(form.el.querySelector('progress')).toBeNull();
        expect(send.disabled).toBe(false);
        expect([...form.el.querySelectorAll('li')].map((li) => li.textContent)).toEqual(expect.arrayContaining([expect.stringContaining('original.pdf'), expect.stringContaining('plan.txt')]));

        await pick([new File(['%PDF'], 'voll.pdf')]);
        Object.assign(xhrs[1], { status: 422, responseText: '{"message":"Im Postfach ist kein Platz mehr"}' });
        xhrs[1].onload?.();
        await flush();
        expect([...form.el.querySelectorAll('[role="alert"]')].map((a) => a.textContent)).toContain('Im Postfach ist kein Platz mehr');

        [...form.el.querySelectorAll('li')].find((li) => li.textContent?.includes('plan.txt'))?.querySelector('button')?.click();
        await flush();
        expect(calls).toContain('DELETE /mail/attachments/6');
        expect(form.el.textContent).not.toContain('plan.txt');
        form.unmount();
    });
});
