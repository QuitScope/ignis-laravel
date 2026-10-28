<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { ref, watch } from 'vue';
import Pagination from '@/components/Pagination.vue';
import { Input } from '@/components/ui/input';

type Row = {
    id: number;
    instance: string;
    number: string | null;
    keyword: string | null;
    location: string | null;
    status: string | null;
    occurred_at: string | null;
    leader: string | null;
    finalized: boolean;
};

const props = defineProps<{
    rows: { data: Row[]; from: number | null; to: number | null; total: number; prev_page_url: string | null; next_page_url: string | null };
    filters: { link: string; q: string };
    links: { id: number; name: string }[];
}>();

const q = ref(props.filters.q);
const link = ref(props.filters.link);
watch([q, link], () =>
    router.get('/federation/incidents', Object.fromEntries(Object.entries({ q: q.value, link: link.value }).filter(([, v]) => v !== '')), { preserveState: true, replace: true }),
);
const when = (iso: string | null) => (iso ? new Date(iso).toLocaleString() : '–');
const status = (s: string | null) => {
    if (!s) return '–';
    const t = trans(`firetab.status.${s}`);
    return t === `firetab.status.${s}` ? s : t;
};
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold">{{ trans('federation.views.incidents.title') }}</h1>
                <p class="mt-1 text-sm text-text-2">{{ trans('federation.views.lead') }}</p>
            </div>
            <Link href="/firetab/qm" class="text-accent">{{ trans('firetab.qm.title') }}</Link>
        </div>

        <div class="flex flex-wrap items-end gap-3">
            <Input v-model="q" type="search" maxlength="100" class="w-64" :placeholder="trans('federation.views.search')" :aria-label="trans('federation.views.search')" />
            <select v-model="link" class="h-9 rounded-md border border-border bg-card px-3 text-sm" :aria-label="trans('federation.views.col.instance')">
                <option value="">{{ trans('federation.views.all_instances') }}</option>
                <option v-for="l in links" :key="l.id" :value="String(l.id)">{{ l.name }}</option>
            </select>
        </div>

        <div class="overflow-x-auto rounded-lg border border-border bg-card shadow-(--shadow)">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-border text-text-2">
                    <tr>
                        <th v-for="c in ['instance', 'number', 'keyword', 'location', 'date', 'status', 'leader']" :key="c" class="px-3 py-2 font-medium">
                            {{ trans(`federation.views.col.${c}`) }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="r in rows.data" :key="r.id" class="border-b border-border last:border-0">
                        <td class="px-3 py-2"><span class="rounded bg-(--fill-2) px-1.5 py-0.5 text-xs">{{ r.instance }}</span></td>
                        <td class="px-3 py-2">{{ r.number ?? '–' }}</td>
                        <td class="px-3 py-2">{{ r.keyword ?? '–' }}</td>
                        <td class="px-3 py-2">{{ r.location ?? '–' }}</td>
                        <td class="px-3 py-2 tabular-nums">{{ when(r.occurred_at) }}</td>
                        <td class="px-3 py-2">
                            {{ status(r.status) }}
                            <span v-if="r.finalized" class="block text-xs text-text-2">{{ trans('federation.views.finalized') }}</span>
                        </td>
                        <td class="px-3 py-2">{{ r.leader ?? '–' }}</td>
                    </tr>
                    <tr v-if="rows.data.length === 0">
                        <td colspan="7" class="px-3 py-6 text-center text-text-2">{{ trans('federation.views.empty') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <Pagination :from="rows.from" :to="rows.to" :total="rows.total" :prev-url="rows.prev_page_url" :next-url="rows.next_page_url" />
    </div>
</template>
