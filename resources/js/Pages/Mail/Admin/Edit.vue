<script setup lang="ts">
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type MailboxData = {
    id: number;
    kind: 'personal' | 'group';
    address: string;
    local: string;
    domain: string;
    display_name: string;
    is_active: boolean;
    is_locked: boolean;
    owner: string | null;
    account: string | null;
};
type Account = { id: number; name: string };

/**
 * One mailbox (mail.admin): address, lock, account (personal) or name and members (group). Metadata only, no mail.
 * The own mailbox is shown read-only: somebody else changes it (four eyes).
 */
const props = defineProps<{
    mailbox: MailboxData;
    isOwn: boolean;
    canChooseDomain: boolean;
    domains: string[];
    members: (Account & { is_active: boolean })[];
    memberCandidates: Account[];
    accountCandidates: Account[];
    releaseBlocked?: boolean;
}>();

const base = `/settings/mail/mailboxes/${props.mailbox.id}`;
const address = useForm({ local: props.mailbox.local, domain: props.mailbox.domain });
const name = useForm({ name: props.mailbox.display_name });
const account = useForm({ user_id: null as number | null });
const member = useForm({ user_id: null as number | null });
const page = usePage();
const pageError = (key: string) => (page.props.errors as Record<string, string | undefined>)[key];

function toggleLock() {
    router.patch(`${base}/lock`, { locked: !props.mailbox.is_locked });
}

function releaseAccount() {
    if (confirm(trans('mail.admin.account_release_confirm', { address: props.mailbox.address }))) router.delete(`${base}/account`, { preserveScroll: true });
}

function removeMember(m: Account) {
    if (confirm(trans('mail.admin.member_remove_confirm', { name: m.name }))) router.delete(`${base}/members/${m.id}`, { preserveScroll: true });
}

const err = (f: { errors: object }, key: string) => (f.errors as Record<string, string | undefined>)[key];
const label = 'block text-sm font-medium';
const select = 'h-9 rounded-md border border-border bg-card px-3 text-sm';
const card = 'space-y-3 rounded-lg border border-border bg-card p-4 shadow-(--shadow)';
</script>

<template>
    <div class="mx-auto w-full max-w-3xl space-y-5">
        <div>
            <Link href="/settings/mail/mailboxes" class="text-sm text-text-2 hover:underline">← {{ trans('mail.admin.title') }}</Link>
            <h1 class="mt-1 text-2xl font-semibold">{{ mailbox.display_name }}</h1>
            <p class="text-sm text-text-2">
                {{ mailbox.address }} · {{ trans(`mail.kind.${mailbox.kind}`) }} ·
                {{ trans(`mail.admin.state_${mailbox.is_locked ? 'locked' : mailbox.is_active ? 'active' : 'inactive'}`) }}
            </p>
            <p v-if="mailbox.kind === 'personal'" class="text-sm text-text-2">
                {{ trans('mail.admin.owner') }}: {{ mailbox.owner ?? '–' }} · {{ trans('mail.admin.account') }}: {{ mailbox.account ?? trans('mail.admin.no_account') }}
            </p>
        </div>

        <p v-if="isOwn" class="rounded-md bg-(--fill-3) px-3 py-2 text-sm" role="note">{{ trans('mail.admin.own_hint') }}</p>
        <p v-if="pageError('mailbox')" class="text-sm text-danger" role="alert">{{ pageError('mailbox') }}</p>

        <form v-if="!isOwn" :class="card" @submit.prevent="address.put(base)">
            <h2 class="font-semibold">{{ trans('mail.admin.address') }}</h2>
            <p class="text-xs text-text-2">{{ trans('mail.admin.address_hint') }}</p>
            <div class="flex items-center gap-2">
                <Input v-model="address.local" required maxlength="64" autocomplete="off" :aria-label="trans('mail.admin.local')" />
                <span aria-hidden="true">@</span>
                <select v-if="canChooseDomain" v-model="address.domain" :class="select" :aria-label="trans('mail.admin.domain')">
                    <option v-for="d in domains" :key="d" :value="d">{{ d }}</option>
                </select>
                <span v-else class="text-sm">{{ mailbox.domain }}</span>
            </div>
            <p v-if="err(address, 'local')" class="text-sm text-danger" role="alert">{{ err(address, 'local') }}</p>
            <p v-if="err(address, 'domain')" class="text-sm text-danger" role="alert">{{ err(address, 'domain') }}</p>
            <Button type="submit" :disabled="address.processing">{{ trans('mail.lists.save') }}</Button>
        </form>

        <section v-if="!isOwn" :class="card">
            <h2 class="font-semibold">{{ trans('mail.admin.lock_title') }}</h2>
            <p class="text-xs text-text-2">{{ trans('mail.admin.lock_hint') }}</p>
            <Button type="button" variant="outline" @click="toggleLock">{{ trans(mailbox.is_locked ? 'mail.admin.unlock' : 'mail.admin.lock') }}</Button>
        </section>

        <template v-if="mailbox.kind === 'group'">
            <form :class="card" @submit.prevent="name.patch(`${base}/name`)">
                <h2 class="font-semibold">{{ trans('mail.admin.name') }}</h2>
                <Input v-model="name.name" required maxlength="150" :aria-label="trans('mail.admin.name')" />
                <p v-if="err(name, 'name')" class="text-sm text-danger" role="alert">{{ err(name, 'name') }}</p>
                <Button type="submit" :disabled="name.processing">{{ trans('mail.lists.save') }}</Button>
            </form>

            <section :class="card">
                <h2 class="font-semibold">{{ trans('mail.admin.members') }}</h2>
                <p class="text-xs text-text-2">{{ trans('mail.admin.members_hint') }}</p>
                <ul class="divide-y divide-border text-sm">
                    <li v-for="m in members" :key="m.id" class="flex items-center justify-between py-1.5">
                        <span>{{ m.name }} <span v-if="!m.is_active" class="text-xs text-text-2">({{ trans('mail.admin.inactive_account') }})</span></span>
                        <Button type="button" variant="ghost" size="sm" @click="removeMember(m)">{{ trans('mail.admin.member_remove') }}</Button>
                    </li>
                    <li v-if="members.length === 0" class="py-1.5 text-text-2">{{ trans('mail.admin.no_members') }}</li>
                </ul>
                <p v-if="pageError('user_id') && !member.errors.user_id" class="text-sm text-danger" role="alert">{{ pageError('user_id') }}</p>
                <form class="flex flex-wrap items-center gap-2" @submit.prevent="member.post(`${base}/members`, { preserveScroll: true, onSuccess: () => member.reset() })">
                    <label class="sr-only" for="member-add">{{ trans('mail.admin.member_add') }}</label>
                    <select id="member-add" v-model="member.user_id" :class="select" required>
                        <option :value="null" disabled>{{ trans('mail.admin.member_choose') }}</option>
                        <option v-for="c in memberCandidates" :key="c.id" :value="c.id">{{ c.name }}</option>
                    </select>
                    <Button type="submit" :disabled="member.processing || member.user_id === null">{{ trans('mail.admin.member_add') }}</Button>
                </form>
                <p v-if="err(member, 'user_id')" class="text-sm text-danger" role="alert">{{ err(member, 'user_id') }}</p>
            </section>
        </template>

        <form v-else-if="!isOwn" :class="card" @submit.prevent="account.put(`${base}/account`)">
            <h2 class="font-semibold">{{ trans('mail.admin.account_title') }}</h2>
            <p class="text-xs text-text-2">{{ trans('mail.admin.account_hint') }}</p>
            <label class="sr-only" for="account-user">{{ trans('mail.admin.account') }}</label>
            <select id="account-user" v-model="account.user_id" :class="select">
                <option :value="null" disabled>{{ trans('mail.admin.member_choose') }}</option>
                <option v-for="c in accountCandidates" :key="c.id" :value="c.id">{{ c.name }}</option>
            </select>
            <p v-if="err(account, 'user_id') || pageError('user_id')" class="text-sm text-danger" role="alert">{{ err(account, 'user_id') || pageError('user_id') }}</p>
            <p v-if="mailbox.account !== null && releaseBlocked" id="release-blocked" class="text-xs text-text-2">{{ trans('mail.admin.release_blocked') }}</p>
            <div class="flex flex-wrap gap-2">
                <Button type="submit" :disabled="account.processing || account.user_id === null">{{ trans('mail.admin.account_save') }}</Button>
                <Button v-if="mailbox.account !== null" type="button" variant="ghost" data-test="release" :disabled="releaseBlocked" :aria-describedby="releaseBlocked ? 'release-blocked' : undefined" @click="releaseAccount">{{ trans('mail.admin.account_release') }}</Button>
            </div>
        </form>
    </div>
</template>
