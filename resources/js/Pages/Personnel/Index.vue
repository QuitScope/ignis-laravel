<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import Pagination from '@/components/Pagination.vue';
import PersonnelLink from '@/components/personnel/PersonnelLink.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';

type Row = {
    id: number;
    service_number: string;
    full_name: string;
    rank: string;
    ems: string | null;
    fire: string | null;
    hired_at: string;
    can_delete: boolean;
};
type Option = { id: number; name: string };
type Filters = { search: string; archive: boolean; sort: string; dir: 'asc' | 'desc'; rank: number; ems: number; fire: number };

const props = defineProps<{
    personnel: {
        data: Row[];
        from: number | null;
        to: number | null;
        total: number;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    filters: Filters;
    ranks: Option[];
    fireQualifications: Option[];
    emsQualifications: Option[];
    can: { create: boolean };
}>();

const search = ref(props.filters.search);
const rank = ref(props.filters.rank);
const ems = ref(props.filters.ems);
const fire = ref(props.filters.fire);

const params = (over: Record<string, string | number> = {}) => {
    const all: Record<string, string | number> = {
        search: search.value,
        rank: rank.value,
        ems: ems.value,
        fire: fire.value,
        archive: props.filters.archive ? 1 : 0,
        sort: props.filters.sort,
        dir: props.filters.dir,
        ...over,
    };
    return Object.fromEntries(Object.entries(all).filter(([, v]) => v !== '' && v !== 0));
};

function load(over: Record<string, string | number> = {}) {
    router.get('/personnel', params(over), { preserveState: true, preserveScroll: true, replace: true });
}
let timer: ReturnType<typeof setTimeout> | undefined;
watch(search, () => {
    clearTimeout(timer);
    timer = setTimeout(() => load(), 300);
});
watch([rank, ems, fire], () => load());

const sortBy = (key: string) =>
    load({ sort: key, dir: props.filters.sort === key && props.filters.dir === 'asc' ? 'desc' : 'asc' });
const ariaSort = (key: string) =>
    props.filters.sort === key ? (props.filters.dir === 'asc' ? 'ascending' : 'descending') : 'none';

const exportUrl = computed(() => '/personnel?' + new URLSearchParams({ ...params(), export: 'csv' } as Record<string, string>));
const archiveUrl = computed(() => '/personnel' + (props.filters.archive ? '' : '?archive=1'));

const columns = [
    ['service_number', 'personnel.col.service_number'],
    ['name', 'personnel.col.name'],
    ['rank', 'personnel.col.rank'],
    ['ems', 'personnel.col.ems'],
    ['fire', 'personnel.col.fire'],
    ['hired_at', 'personnel.col.hired_at'],
] as const;

const date = (iso: string) => new Date(iso + 'T00:00:00').toLocaleDateString();

const deleting = ref<Row | null>(null);
const deleteError = computed(() => (usePage().props.errors as Record<string, string>).delete);
function destroy() {
    if (!deleting.value) return;
    router.delete(`/personnel/${deleting.value.id}`, { preserveScroll: true, onFinish: () => (deleting.value = null) });
}
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold">
                    {{ trans(filters.archive ? 'personnel.title_archive' : 'personnel.title') }}
                    <span class="ml-1 rounded-full bg-(--fill-3) px-2 py-0.5 align-middle text-sm font-medium text-text-2">
                        {{ personnel.total }}
                    </span>
                </h1>
                <p class="mt-1 text-sm text-text-2">{{ trans('personnel.lead') }}</p>
                <p v-if="deleteError" class="mt-1 text-sm text-danger" role="alert">{{ deleteError }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <Button as-child variant="outline">
                    <a :href="exportUrl">{{ trans('personnel.export') }}</a>
                </Button>
                <Button as-child variant="outline">
                    <Link :href="archiveUrl">{{ trans(filters.archive ? 'personnel.show_active' : 'personnel.show_archive') }}</Link>
                </Button>
                <Button v-if="can.create && !filters.archive" as-child>
                    <Link href="/personnel/create">{{ trans('personnel.new') }}</Link>
                </Button>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <Input
                v-model="search"
                type="search"
                class="max-w-xs"
                :placeholder="trans('personnel.search_placeholder')"
                :aria-label="trans('personnel.search')"
            />
            <select v-model.number="rank" class="h-9 rounded-md border border-border bg-card px-3 text-sm" :aria-label="trans('personnel.col.rank')">
                <option :value="0">{{ trans('personnel.filter.all_ranks') }}</option>
                <option v-for="o in ranks" :key="o.id" :value="o.id">{{ o.name }}</option>
            </select>
            <select v-model.number="ems" class="h-9 rounded-md border border-border bg-card px-3 text-sm" :aria-label="trans('personnel.col.ems')">
                <option :value="0">{{ trans('personnel.filter.all_ems') }}</option>
                <option v-for="o in emsQualifications" :key="o.id" :value="o.id">{{ o.name }}</option>
            </select>
            <select v-model.number="fire" class="h-9 rounded-md border border-border bg-card px-3 text-sm" :aria-label="trans('personnel.col.fire')">
                <option :value="0">{{ trans('personnel.filter.all_fire') }}</option>
                <option v-for="o in fireQualifications" :key="o.id" :value="o.id">{{ o.name }}</option>
            </select>
        </div>

        <div class="overflow-x-auto rounded-lg border border-border bg-card shadow-(--shadow)">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-border text-text-2">
                    <tr>
                        <th v-for="[key, label] in columns" :key="key" class="px-3 py-2 font-medium" :aria-sort="ariaSort(key)">
                            <button type="button" class="font-medium hover:text-text" @click="sortBy(key)">
                                {{ trans(label) }}
                                <span aria-hidden="true">{{ filters.sort === key ? (filters.dir === 'asc' ? '▲' : '▼') : '' }}</span>
                            </button>
                        </th>
                        <th class="px-3 py-2" />
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="p in personnel.data" :key="p.id" class="border-b border-border last:border-0">
                        <td class="px-3 py-2 font-medium tabular-nums">{{ p.service_number }}</td>
                        <td class="px-3 py-2">
                            <PersonnelLink :id="p.id" :name="p.full_name" />
                        </td>
                        <td class="px-3 py-2">{{ p.rank }}</td>
                        <td class="px-3 py-2">{{ p.ems ?? '–' }}</td>
                        <td class="px-3 py-2">{{ p.fire ?? '–' }}</td>
                        <td class="px-3 py-2 tabular-nums">{{ date(p.hired_at) }}</td>
                        <td class="whitespace-nowrap px-3 py-2 text-right">
                            <Button v-if="p.can_delete" variant="ghost" size="sm" :aria-label="trans('personnel.delete_row', { name: p.full_name })" @click="deleting = p">
                                {{ trans('personnel.delete') }}
                            </Button>
                        </td>
                    </tr>
                    <tr v-if="!personnel.data.length">
                        <td colspan="7" class="px-3 py-6 text-center text-text-2">{{ trans('personnel.empty') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Pagination
            :from="personnel.from"
            :to="personnel.to"
            :total="personnel.total"
            :prev-url="personnel.prev_page_url"
            :next-url="personnel.next_page_url"
        />

        <Dialog :open="deleting !== null" @update:open="(v) => !v && (deleting = null)">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{{ trans('personnel.delete') }}</DialogTitle>
                </DialogHeader>
                <p class="text-sm">{{ trans('personnel.delete_confirm', { name: deleting?.full_name ?? '' }) }}</p>
                <DialogFooter>
                    <Button variant="ghost" @click="deleting = null">{{ trans('personnel.cancel') }}</Button>
                    <Button variant="destructive" @click="destroy">{{ trans('personnel.delete') }}</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
