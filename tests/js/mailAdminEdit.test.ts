import { createApp, defineComponent, h, nextTick, reactive } from 'vue';
import { afterEach, expect, it, vi } from 'vitest';

const router = vi.hoisted(() => ({ delete: vi.fn(), patch: vi.fn() }));
const puts = vi.hoisted(() => [] as [string, unknown][]);
vi.mock('@inertiajs/vue3', () => ({
    router,
    Link: defineComponent({ props: { href: String }, setup: (p, { slots }) => () => h('a', { href: p.href }, slots.default?.()) }),
    usePage: () => ({ props: { errors: {} } }),
    useForm: (data: Record<string, unknown>) => {
        const form = reactive({ ...data, errors: {}, processing: false }) as Record<string, unknown>;
        for (const m of ['put', 'post', 'patch']) form[m] = (url: string) => puts.push([url, { ...data, user_id: form.user_id }]);
        form.reset = () => undefined;
        return form;
    },
}));

afterEach(() => {
    vi.unstubAllGlobals();
    vi.clearAllMocks();
    puts.length = 0;
    document.body.innerHTML = '';
});

const base = {
    isOwn: false, canChooseDomain: false, domains: ['ignis.ef'], members: [], memberCandidates: [],
    accountCandidates: [{ id: 7, name: 'Neu' }],
};
const mailbox = (account: string | null) => ({
    id: 4, kind: 'personal', address: 'p.umzug@ignis.ef', local: 'p.umzug', domain: 'ignis.ef', display_name: 'Paul Umzug',
    is_active: true, is_locked: false, owner: 'Paul Umzug', account,
});

async function mount(account: string | null, extra: object = {}) {
    const { default: Edit } = await import('@/Pages/Mail/Admin/Edit.vue');
    const el = document.createElement('div');
    document.body.appendChild(el);
    createApp(Edit, { ...base, mailbox: mailbox(account), ...extra }).mount(el);
    await nextTick();

    return el;
}

it('keeps assign disabled until an account is chosen and releases only by a confirmed DELETE', async () => {
    const el = await mount('Paul');
    const select = el.querySelector<HTMLSelectElement>('#account-user')!;
    const assign = select.closest('form')!.querySelector<HTMLButtonElement>('button[type="submit"]')!;

    expect(select.selectedOptions[0].disabled).toBe(true);
    expect(assign.disabled).toBe(true);

    vi.stubGlobal('confirm', vi.fn(() => false));
    el.querySelector<HTMLButtonElement>('[data-test="release"]')!.click();
    expect(router.delete).not.toHaveBeenCalled();

    vi.stubGlobal('confirm', vi.fn(() => true));
    el.querySelector<HTMLButtonElement>('[data-test="release"]')!.click();
    expect(router.delete).toHaveBeenCalledWith('/settings/mail/mailboxes/4/account', expect.anything());

    select.value = '7';
    select.dispatchEvent(new Event('change'));
    await nextTick();
    expect(assign.disabled).toBe(false);
    assign.click();
    expect(puts).toEqual([['/settings/mail/mailboxes/4/account', { user_id: 7 }]]);
});

it('offers no release without an assigned account', async () => {
    const el = await mount(null);
    expect(el.querySelector('[data-test="release"]')).toBeNull();
});

it('disables the release and explains why while the record still links the account', async () => {
    const el = await mount('Paul', { releaseBlocked: true });
    const release = el.querySelector<HTMLButtonElement>('[data-test="release"]')!;

    expect(release.disabled).toBe(true);
    expect(release.getAttribute('aria-describedby')).toBe('release-blocked');
    expect(el.querySelector('#release-blocked')).not.toBeNull();
});
