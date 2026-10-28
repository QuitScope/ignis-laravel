<script setup lang="ts">
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import Pagination from '@/components/Pagination.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type Row = {
    id: number;
    kind: 'personal' | 'group';
    address: string;
    display_name: string;
    is_active: boolean;
    is_locked: boolean;
    members_count: number;
    owner: string | null;
    account: string | null;
    is_own: boolean;
};
type Filters = { kind: string | null; state: string | null; q: string };

/** Mailbox administration (mail.admin): metadata only, never mail, subjects or counts of them. */
const props = defineProps<{
    mailboxes: { data: Row[]; from: number | null; to: number | null; total: number; prev_page_url: string | null; next_page_url: string | null };
    filters: Filters;
}>();

const kind = ref(props.filters.kind ?? '');
const state = ref(props.filters.state ?? '');
const q = ref(props.filters.q);

function apply() {
    const query: Record<string, string> = {};
    if (kind.value) query.kind = kind.value;
    if (state.value) query.state = state.value;
    if (q.value.trim()) query.q = q.value.trim();
    router.get('/settings/mail/mailboxes', query, { preserveState: true, replace: true });
}

const stateOf = (m: Row) => (m.is_locked ? 'locked' : m.is_active ? 'active' : 'inactive');
const select = 'h-9 rounded-md border border-border bg-card px-3 text-sm';
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold">{{ trans('mail.admin.title') }}</h1>
                <p class="mt-1 text-sm text-text-2">{{ trans('mail.admin.intro') }}</p>
            </div>
            <div class="flex gap-2">
                <Button as-child variant="ghost"><Link href="/settings/mail">{{ trans('mail.settings.nav') }}</Link></Button>
                <Button as-child><Link href="/settings/mail/mailboxes/groups/create">{{ trans('mail.admin.new_group') }}</Link></Button>
            </div>
        </div>

        <form class="flex flex-wrap items-center gap-2" role="search" @submit.prevent="apply">
            <select v-model="kind" :class="select" :aria-label="trans('mail.admin.kind')" @change="apply">
                <option value="">{{ trans('mail.admin.all_kinds') }}</option>
                <option value="personal">{{ trans('mail.kind.personal') }}</option>
                <option value="group">{{ trans('mail.kind.group') }}</option>
            </select>
            <select v-model="state" :class="select" :aria-label="trans('mail.admin.state')" @change="apply">
                <option value="">{{ trans('mail.admin.all_states') }}</option>
                <option v-for="s in ['active', 'inactive', 'locked']" :key="s" :value="s">{{ trans(`mail.admin.state_${s}`) }}</option>
            </select>
            <Input v-model="q" class="w-56" :placeholder="trans('mail.admin.search')" :aria-label="trans('mail.admin.search')" />
            <Button type="submit" variant="ghost">{{ trans('mail.admin.filter') }}</Button>
        </form>

        <div class="overflow-x-auto rounded-lg border border-border bg-card shadow-(--shadow)">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-border text-text-2">
                    <tr>
                        <th class="px-3 py-2 font-medium">{{ trans('mail.admin.name') }}</th>
                        <th class="px-3 py-2 font-medium">{{ trans('mail.admin.address') }}</th>
                        <th class="px-3 py-2 font-medium">{{ trans('mail.admin.kind') }}</th>
                        <th class="px-3 py-2 font-medium">{{ trans('mail.admin.state') }}</th>
                        <th class="px-3 py-2 font-medium">{{ trans('mail.admin.owner') }}</th>
                        <th class="px-3 py-2"><span class="sr-only">{{ trans('mail.admin.actions') }}</span></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="m in mailboxes.data" :key="m.id" class="border-b border-border last:border-0">
                        <td class="px-3 py-2 font-medium">
                            {{ m.display_name }}
                            <span v-if="m.is_own" class="ml-1 text-xs text-text-2">({{ trans('mail.admin.own') }})</span>
                        </td>
                        <td class="px-3 py-2">{{ m.address }}</td>
                        <td class="px-3 py-2">{{ trans(`mail.kind.${m.kind}`) }}</td>
                        <td class="px-3 py-2">{{ trans(`mail.admin.state_${stateOf(m)}`) }}</td>
                        <td class="px-3 py-2">
                            <template v-if="m.kind === 'group'">{{ trans('mail.admin.members_count', { count: String(m.members_count) }) }}</template>
                            <template v-else>{{ m.owner ?? '–' }} <span class="block text-xs text-text-2">{{ m.account ?? trans('mail.admin.no_account') }}</span></template>
                        </td>
                        <td class="whitespace-nowrap px-3 py-2 text-right">
                            <Button as-child variant="ghost" size="sm"><Link :href="`/settings/mail/mailboxes/${m.id}/edit`">{{ trans('mail.admin.edit') }}</Link></Button>
                        </td>
                    </tr>
                    <tr v-if="mailboxes.data.length === 0">
                        <td colspan="6" class="px-3 py-6 text-center text-text-2">{{ trans('mail.admin.empty') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Pagination :from="mailboxes.from" :to="mailboxes.to" :total="mailboxes.total" :prev-url="mailboxes.prev_page_url" :next-url="mailboxes.next_page_url" />
    </div>
</template>
