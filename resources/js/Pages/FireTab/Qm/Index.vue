<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { ref, watch } from 'vue';
import Pagination from '@/components/Pagination.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type Row = {
    id: number;
    number: string | null;
    location: string | null;
    keyword: string | null;
    started_at: string | null;
    leader: string | null;
    status: string;
    finalized: boolean;
    archived: boolean;
    vehicles_count: number;
    sitreps_count: number;
};

const props = defineProps<{
    incidents: { data: Row[]; from: number | null; to: number | null; total: number; prev_page_url: string | null; next_page_url: string | null };
    filters: { archived: boolean; q: string; status: string; sort: string; dir: string };
    counts: { active: number; archived: number };
    statuses: string[];
    federated: number | null;
}>();

const q = ref(props.filters.q);
const status = ref(props.filters.status);
const selected = ref<number[]>([]);

function load(extra: Record<string, string> = {}) {
    const all: Record<string, string> = { q: q.value, status: status.value, sort: props.filters.sort, dir: props.filters.dir, ...extra };
    if (props.filters.archived) all.archived = '1';
    router.get('/firetab/qm', Object.fromEntries(Object.entries(all).filter(([, v]) => v !== '')), { preserveState: true, replace: true });
}
watch([q, status], () => load());
function sortBy(col: string) {
    load({ sort: col, dir: props.filters.sort === col && props.filters.dir === 'asc' ? 'desc' : 'asc' });
}

function bulk() {
    router.post('/firetab/qm/bulk-archive', { ids: selected.value }, { preserveScroll: true, onSuccess: () => (selected.value = []) });
}
const toggle = (id: number) => (selected.value = selected.value.includes(id) ? selected.value.filter((x) => x !== id) : [...selected.value, id]);
const cols = ['number', 'location', 'keyword', 'started_at', 'status'] as const;
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-2xl font-semibold">{{ trans('firetab.qm.title') }}</h1>
            <div class="flex gap-4">
                <Link v-if="federated !== null" href="/federation/incidents" class="text-accent">{{ trans('federation.views.link', { count: String(federated) }) }}</Link>
                <Link href="/firetab/qm/empty" class="text-accent">{{ trans('firetab.qm.empty.link') }}</Link>
            </div>
        </div>

        <nav class="flex gap-2" aria-label="tabs">
            <Link
                href="/firetab/qm"
                class="rounded-md border px-3 py-1 text-sm"
                :class="!filters.archived ? 'border-accent text-accent' : 'border-border text-text-2'"
                >{{ trans('firetab.qm.active') }} ({{ counts.active }})</Link
            >
            <Link
                href="/firetab/qm?archived=1"
                class="rounded-md border px-3 py-1 text-sm"
                :class="filters.archived ? 'border-accent text-accent' : 'border-border text-text-2'"
                >{{ trans('firetab.qm.archived') }} ({{ counts.archived }})</Link
            >
        </nav>

        <div class="flex flex-wrap items-end gap-3">
            <Input v-model="q" type="search" maxlength="100" class="w-64" :placeholder="trans('firetab.qm.search')" :aria-label="trans('firetab.qm.search')" />
            <select v-model="status" class="h-9 rounded-md border border-border bg-card px-3 text-sm" :aria-label="trans('firetab.qm.col.status')">
                <option value="">{{ trans('firetab.qm.all_statuses') }}</option>
                <option v-for="s in statuses" :key="s" :value="s">{{ trans(`firetab.status.${s}`) }}</option>
            </select>
            <Button v-if="selected.length && !filters.archived" variant="ghost" size="sm" @click="bulk">{{ trans('firetab.qm.bulk_archive') }} ({{ selected.length }})</Button>
        </div>

        <div class="overflow-x-auto rounded-lg border border-border bg-card shadow-(--shadow)">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-border text-text-2">
                    <tr>
                        <th v-if="!filters.archived" class="w-8 px-3 py-2" />
                        <th v-for="c in cols" :key="c" class="px-3 py-2 font-medium" :aria-sort="filters.sort === c ? (filters.dir === 'asc' ? 'ascending' : 'descending') : undefined">
                            <button type="button" @click="sortBy(c)">{{ trans(`firetab.qm.col.${c}`) }}</button>
                        </th>
                        <th class="px-3 py-2 font-medium">{{ trans('firetab.qm.col.leader') }}</th>
                        <th class="px-3 py-2 font-medium">{{ trans('firetab.qm.col.counts') }}</th>
                        <th class="px-3 py-2" />
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="i in incidents.data" :key="i.id" class="border-b border-border last:border-0">
                        <td v-if="!filters.archived" class="px-3 py-2"><input type="checkbox" :checked="selected.includes(i.id)" @change="toggle(i.id)" /></td>
                        <td class="px-3 py-2">{{ i.number ?? '#' + i.id }}</td>
                        <td class="px-3 py-2">{{ i.location }}</td>
                        <td class="px-3 py-2">{{ i.keyword }}</td>
                        <td class="px-3 py-2 tabular-nums">{{ i.started_at }}</td>
                        <td class="px-3 py-2">
                            {{ trans(`firetab.status.${i.status}`) }}
                            <span class="block text-xs text-text-2">{{ i.finalized ? trans('firetab.qm.finalized') : trans('firetab.qm.open_state') }}</span>
                        </td>
                        <td class="px-3 py-2">{{ i.leader }}</td>
                        <td class="px-3 py-2 tabular-nums">{{ i.vehicles_count }} / {{ i.sitreps_count }}</td>
                        <td class="px-3 py-2 text-right"><Link :href="`/firetab/qm/incidents/${i.id}`" class="text-accent">{{ trans('firetab.qm.open') }}</Link></td>
                    </tr>
                    <tr v-if="incidents.data.length === 0">
                        <td colspan="9" class="px-3 py-6 text-center text-text-2">{{ trans('firetab.qm.empty_list') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <Pagination :from="incidents.from" :to="incidents.to" :total="incidents.total" :prev-url="incidents.prev_page_url" :next-url="incidents.next_page_url" />
    </div>
</template>
