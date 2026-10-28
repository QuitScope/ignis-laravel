<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { Button } from '@/components/ui/button';

type ListRow = {
    id: number;
    name: string;
    address: string;
    kind: 'static' | 'dynamic';
    senders: 'all' | 'managers';
    members_count: number;
    rule: Partial<Record<string, string[]>>;
};

/** Distribution lists (mail.lists.manage): static members or a dynamic rule, who may write to them. */
defineProps<{ lists: ListRow[] }>();

const ruleKeys = ['role', 'rank', 'ems_qualification', 'fire_qualification', 'specialty'];

function summary(l: ListRow): string {
    if (l.kind === 'static') return trans('mail.lists.members_count', { count: String(l.members_count) });
    const parts = ruleKeys.filter((k) => (l.rule[k] ?? []).length > 0).map((k) => `${trans(`mail.lists.rule.${k}`)}: ${(l.rule[k] ?? []).join(', ')}`);

    return parts.length ? parts.join(' · ') : trans('mail.lists.no_rule');
}

function destroy(l: ListRow) {
    if (confirm(trans('mail.lists.delete_confirm', { name: l.name }))) router.delete(`/mail/lists/${l.id}`);
}
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold">{{ trans('mail.lists.title') }}</h1>
                <p class="mt-1 text-sm text-text-2">{{ trans('mail.lists.intro') }}</p>
            </div>
            <Button as-child><Link href="/mail/lists/create">{{ trans('mail.lists.new') }}</Link></Button>
        </div>

        <div class="overflow-x-auto rounded-lg border border-border bg-card shadow-(--shadow)">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-border text-text-2">
                    <tr>
                        <th class="px-3 py-2 font-medium">{{ trans('mail.lists.name') }}</th>
                        <th class="px-3 py-2 font-medium">{{ trans('mail.lists.address') }}</th>
                        <th class="px-3 py-2 font-medium">{{ trans('mail.lists.kind') }}</th>
                        <th class="px-3 py-2 font-medium">{{ trans('mail.lists.senders') }}</th>
                        <th class="px-3 py-2"><span class="sr-only">{{ trans('mail.lists.actions') }}</span></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="l in lists" :key="l.id" class="border-b border-border last:border-0">
                        <td class="px-3 py-2 font-medium">{{ l.name }}</td>
                        <td class="px-3 py-2">{{ l.address }}</td>
                        <td class="px-3 py-2">
                            {{ trans(`mail.lists.kind_${l.kind}`) }}
                            <span class="block text-xs text-text-2">{{ summary(l) }}</span>
                        </td>
                        <td class="px-3 py-2">{{ trans(`mail.lists.senders_${l.senders}`) }}</td>
                        <td class="whitespace-nowrap px-3 py-2 text-right">
                            <Button as-child variant="ghost" size="sm"><Link :href="`/mail/lists/${l.id}/edit`">{{ trans('mail.lists.edit') }}</Link></Button>
                            <Button variant="ghost" size="sm" @click="destroy(l)">{{ trans('mail.lists.delete') }}</Button>
                        </td>
                    </tr>
                    <tr v-if="lists.length === 0">
                        <td colspan="5" class="px-3 py-6 text-center text-text-2">{{ trans('mail.lists.empty') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
