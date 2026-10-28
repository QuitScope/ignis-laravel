<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import Pagination from '@/components/Pagination.vue';
import { Button } from '@/components/ui/button';

type Entry = { id: number; occurred_at: string | null; action: string; description: string; user_name: string | null };

defineProps<{
    incident: { id: number; incident_number: string; location: string };
    entries: { data: Entry[]; from: number | null; to: number | null; total: number; prev_page_url: string | null; next_page_url: string | null };
}>();

const actionLabel = (a: string) => {
    const t = trans(`manvboard.action.${a}`);
    return t === `manvboard.action.${a}` ? a : t;
};
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-2xl font-semibold">{{ trans('manvboard.log_title', { number: incident.incident_number }) }}</h1>
            <Button as-child variant="ghost"><Link href="/mci">{{ trans('manvboard.back') }}</Link></Button>
        </div>

        <div class="overflow-x-auto rounded-lg border border-border bg-card shadow-(--shadow)">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-border text-text-2">
                    <tr>
                        <th class="px-3 py-2 font-medium">{{ trans('manvboard.col.time') }}</th>
                        <th class="px-3 py-2 font-medium">{{ trans('manvboard.col.action') }}</th>
                        <th class="px-3 py-2 font-medium">{{ trans('manvboard.col.description') }}</th>
                        <th class="px-3 py-2 font-medium">{{ trans('manvboard.col.user') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="e in entries.data" :key="e.id" class="border-b border-border last:border-0">
                        <td class="whitespace-nowrap px-3 py-2 tabular-nums">{{ e.occurred_at }}</td>
                        <td class="px-3 py-2">{{ actionLabel(e.action) }}</td>
                        <td class="px-3 py-2">{{ e.description }}</td>
                        <td class="px-3 py-2">{{ e.user_name ?? '–' }}</td>
                    </tr>
                    <tr v-if="entries.data.length === 0">
                        <td colspan="4" class="px-3 py-6 text-center text-text-2">{{ trans('manvboard.log_empty') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Pagination :from="entries.from" :to="entries.to" :total="entries.total" :prev-url="entries.prev_page_url" :next-url="entries.next_page_url" />
    </div>
</template>
