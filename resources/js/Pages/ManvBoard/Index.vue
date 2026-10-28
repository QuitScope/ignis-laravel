<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import Pagination from '@/components/Pagination.vue';
import { Button } from '@/components/ui/button';
import { usePulse } from '@/lib/realtime';

type Incident = {
    id: number;
    incident_number: string;
    location: string;
    status: string;
    started_at: string | null;
    ended_at: string | null;
    lna_name: string | null;
    orgl_name: string | null;
    counts: { total: number; waiting: number; departed: number; triage: Record<string, number> };
};

defineProps<{
    incidents: { data: Incident[]; from: number | null; to: number | null; total: number; prev_page_url: string | null; next_page_url: string | null };
    filter: string;
    statuses: string[];
    can: { manage: boolean };
}>();

usePulse('manv', ['list'], () => router.reload({ only: ['incidents'] }), { pollMs: 0 });

function setFilter(e: Event) {
    router.get('/mci', { status: (e.target as HTMLSelectElement).value }, { preserveState: true, replace: true });
}
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-2xl font-semibold">{{ trans('manvboard.nav') }}</h1>
            <div class="flex items-center gap-2">
                <select :value="filter" class="h-9 rounded-md border border-border bg-card px-3 text-sm" :aria-label="trans('manvboard.col.status')" @change="setFilter">
                    <option v-for="s in statuses" :key="s" :value="s">{{ trans(`manvboard.status.${s}`) }}</option>
                    <option value="all">{{ trans('manvboard.filter.all') }}</option>
                </select>
                <Button v-if="can.manage" as-child><Link href="/mci/create">{{ trans('manvboard.new_incident') }}</Link></Button>
            </div>
        </div>

        <div class="overflow-x-auto rounded-lg border border-border bg-card shadow-(--shadow)">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-border text-text-2">
                    <tr>
                        <th class="px-3 py-2 font-medium">{{ trans('manvboard.col.number') }}</th>
                        <th class="px-3 py-2 font-medium">{{ trans('manvboard.col.location') }}</th>
                        <th class="px-3 py-2 font-medium">{{ trans('manvboard.col.status') }}</th>
                        <th class="px-3 py-2 font-medium">{{ trans('manvboard.col.started') }}</th>
                        <th class="px-3 py-2 font-medium">{{ trans('manvboard.col.lna') }} / {{ trans('manvboard.col.orgl') }}</th>
                        <th class="px-3 py-2 font-medium">{{ trans('manvboard.col.patients') }}</th>
                        <th class="px-3 py-2" />
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="i in incidents.data" :key="i.id" class="border-b border-border last:border-0">
                        <td class="px-3 py-2 font-medium"><Link :href="`/mci/${i.id}`" class="hover:underline">{{ i.incident_number }}</Link></td>
                        <td class="px-3 py-2">{{ i.location }}</td>
                        <td class="px-3 py-2">{{ trans(`manvboard.status.${i.status}`) }}</td>
                        <td class="px-3 py-2 tabular-nums">{{ i.started_at }}</td>
                        <td class="px-3 py-2">{{ i.lna_name ?? '–' }} / {{ i.orgl_name ?? '–' }}</td>
                        <td class="px-3 py-2 tabular-nums">
                            {{ trans('manvboard.counts', { total: String(i.counts.total), waiting: String(i.counts.waiting), departed: String(i.counts.departed) }) }}
                            <span class="block text-xs text-text-2">
                                <template v-for="(n, k) in i.counts.triage" :key="k"><span v-if="n > 0" class="mr-2">{{ trans(`manvboard.triage.${k}`) }}: {{ n }}</span></template>
                            </span>
                        </td>
                        <td class="whitespace-nowrap px-3 py-2 text-right">
                            <Button as-child variant="ghost" size="sm"><Link :href="`/mci/${i.id}/log`">{{ trans('manvboard.log') }}</Link></Button>
                            <Button v-if="can.manage" as-child variant="ghost" size="sm"><Link :href="`/mci/${i.id}/edit`">{{ trans('manvboard.edit') }}</Link></Button>
                        </td>
                    </tr>
                    <tr v-if="incidents.data.length === 0">
                        <td colspan="7" class="px-3 py-6 text-center text-text-2">{{ trans('manvboard.empty') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Pagination :from="incidents.from" :to="incidents.to" :total="incidents.total" :prev-url="incidents.prev_page_url" :next-url="incidents.next_page_url" />
    </div>
</template>
