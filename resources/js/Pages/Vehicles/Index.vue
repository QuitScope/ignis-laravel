<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import Pagination from '@/components/Pagination.vue';
import VehicleLink from '@/components/vehicles/VehicleLink.vue';
import VehiclePreview, { type PreviewDefect } from '@/components/vehicles/VehiclePreview.vue';
import VehicleStatusBadge from '@/components/vehicles/VehicleStatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';

type Row = {
    id: number;
    name: string;
    identifier: string;
    type: string;
    sort_order: number;
    service: number;
    license_plate: string | null;
    is_active: boolean;
    state: 'operable' | 'blocked' | 'inactive';
    blocking_defects_count: number;
    open_defects_count: number;
    fms_status: string | null;
    fms_label: string | null;
    preview_defects: PreviewDefect[];
};
type Filters = { search: string; active: string; operable: string; sort: string; dir: 'asc' | 'desc' };

const props = defineProps<{
    vehicles: { data: Row[]; from: number | null; to: number | null; total: number; prev_page_url: string | null; next_page_url: string | null };
    filters: Filters;
    fmsLabels: Record<string, string>;
    can: { manage: boolean };
}>();

const search = ref(props.filters.search);
const active = ref(props.filters.active);
const operable = ref(props.filters.operable);

const params = (over: Record<string, string> = {}) => {
    const all: Record<string, string> = {
        search: search.value,
        active: active.value,
        operable: operable.value,
        sort: props.filters.sort,
        dir: props.filters.dir,
        ...over,
    };
    return Object.fromEntries(Object.entries(all).filter(([, v]) => v !== ''));
};
function load(over: Record<string, string> = {}) {
    router.get('/vehicles', params(over), { preserveState: true, preserveScroll: true, replace: true });
}
let timer: ReturnType<typeof setTimeout> | undefined;
watch(search, () => {
    clearTimeout(timer);
    timer = setTimeout(() => load(), 300);
});
watch([active, operable], () => load());

const sortBy = (key: string) => load({ sort: key, dir: props.filters.sort === key && props.filters.dir === 'asc' ? 'desc' : 'asc' });
const ariaSort = (key: string) => (props.filters.sort === key ? (props.filters.dir === 'asc' ? 'ascending' : 'descending') : 'none');

const columns = [
    ['priority', 'vehicles.col.priority'],
    ['name', 'vehicles.col.name'],
    ['license_plate', 'vehicles.col.license_plate'],
    ['service', 'vehicles.col.service'],
    ['defects', 'vehicles.col.defects'],
    ['active', 'vehicles.col.status'],
] as const;

const expanded = ref<number | null>(null);

const selected = ref<number[]>([]);
watch(() => props.vehicles.data, () => (selected.value = selected.value.filter((id) => props.vehicles.data.some((r) => r.id === id))));
const allSelected = computed(() => props.vehicles.data.length > 0 && selected.value.length === props.vehicles.data.length);
const toggleAll = () => (selected.value = allSelected.value ? [] : props.vehicles.data.map((r) => r.id));
const action = ref<'' | 'activate' | 'deactivate' | 'delete' | 'fms_status'>('');
const fmsStatus = ref('');
const confirming = ref(false);
const bulkError = computed(() => {
    const e = usePage().props.errors as Record<string, string>;
    return e.ids ?? e.action ?? e.fms_status;
});
const canApply = computed(() => selected.value.length > 0 && action.value !== '' && (action.value !== 'fms_status' || fmsStatus.value !== ''));
function apply() {
    if (!canApply.value) return;
    if (action.value === 'delete' && !confirming.value) {
        confirming.value = true;
        return;
    }
    confirming.value = false;
    const data: Record<string, unknown> = { action: action.value, ids: selected.value };
    if (action.value === 'fms_status') data.fms_status = fmsStatus.value;
    router.post('/vehicles/bulk', data as Record<string, string>, {
        preserveScroll: true,
        onSuccess: () => {
            selected.value = [];
            action.value = '';
        },
    });
}

const select = 'h-9 rounded-md border border-border bg-card px-3 text-sm';
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold">
                    {{ trans('vehicles.title') }}
                    <span class="ml-1 rounded-full bg-(--fill-3) px-2 py-0.5 align-middle text-sm font-medium text-text-2">{{ vehicles.total }}</span>
                </h1>
                <p class="mt-1 text-sm text-text-2">{{ trans('vehicles.lead') }}</p>
            </div>
            <Button v-if="can.manage" as-child>
                <Link href="/vehicles/create">{{ trans('vehicles.new') }}</Link>
            </Button>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <Input v-model="search" type="search" class="max-w-xs" :placeholder="trans('vehicles.search_placeholder')" :aria-label="trans('vehicles.search')" />
            <select v-model="active" :class="select" :aria-label="trans('vehicles.filter.active')">
                <option value="">{{ trans('vehicles.filter.all_switch') }}</option>
                <option value="1">{{ trans('vehicles.switch.on') }}</option>
                <option value="0">{{ trans('vehicles.switch.off') }}</option>
            </select>
            <select v-model="operable" :class="select" :aria-label="trans('vehicles.filter.operable')">
                <option value="">{{ trans('vehicles.filter.all_operable') }}</option>
                <option value="1">{{ trans('vehicles.filter.operable_yes') }}</option>
                <option value="0">{{ trans('vehicles.filter.operable_no') }}</option>
            </select>
        </div>

        <div v-if="can.manage" class="flex flex-wrap items-center gap-2 text-sm">
            <span class="text-text-2">{{ trans('vehicles.bulk.selected', { count: String(selected.length) }) }}</span>
            <select v-model="action" :class="select" :aria-label="trans('vehicles.bulk.action')">
                <option value="">{{ trans('vehicles.bulk.choose') }}</option>
                <option value="activate">{{ trans('vehicles.bulk.activate') }}</option>
                <option value="deactivate">{{ trans('vehicles.bulk.deactivate') }}</option>
                <option value="fms_status">{{ trans('vehicles.bulk.fms_status') }}</option>
                <option value="delete">{{ trans('vehicles.bulk.delete') }}</option>
            </select>
            <select v-if="action === 'fms_status'" v-model="fmsStatus" :class="select" :aria-label="trans('vehicles.field.fms_status')">
                <option value="">{{ trans('vehicles.bulk.choose_status') }}</option>
                <option v-for="(label, code) in fmsLabels" :key="code" :value="String(code)">{{ code }} – {{ label }}</option>
            </select>
            <Button size="sm" :disabled="!canApply" @click="apply">{{ trans('vehicles.bulk.apply') }}</Button>
            <p v-if="bulkError" class="text-danger" role="alert">{{ bulkError }}</p>
        </div>

        <div class="overflow-x-auto rounded-lg border border-border bg-card shadow-(--shadow)">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-border text-text-2">
                    <tr>
                        <th v-if="can.manage" class="w-8 px-3 py-2">
                            <input type="checkbox" class="size-4" :checked="allSelected" :aria-label="trans('vehicles.bulk.select_all')" @change="toggleAll" />
                        </th>
                        <th v-for="[key, label] in columns" :key="key" class="px-3 py-2 font-medium" :aria-sort="ariaSort(key)">
                            <button type="button" class="font-medium hover:text-text" @click="sortBy(key)">
                                {{ trans(label) }}
                                <span aria-hidden="true">{{ filters.sort === key ? (filters.dir === 'asc' ? '▲' : '▼') : '' }}</span>
                            </button>
                        </th>
                        <th class="px-3 py-2 font-medium">{{ trans('vehicles.col.fms') }}</th>
                        <th class="px-3 py-2" />
                    </tr>
                </thead>
                <tbody>
                    <template v-for="v in vehicles.data" :key="v.id">
                        <tr class="border-b border-border last:border-0">
                            <td v-if="can.manage" class="px-3 py-2">
                                <input v-model="selected" type="checkbox" class="size-4" :value="v.id" :aria-label="trans('vehicles.bulk.select_row', { name: v.name })" />
                            </td>
                            <td class="px-3 py-2 tabular-nums">{{ v.sort_order }}</td>
                            <td class="px-3 py-2">
                                <VehicleLink :id="v.id" :name="v.name" />
                                <span class="block text-xs text-text-2">{{ v.identifier }} · {{ v.type }}</span>
                            </td>
                            <td class="px-3 py-2">{{ v.license_plate ?? '–' }}</td>
                            <td class="px-3 py-2">{{ trans(`vehicles.service.${v.service}`) }}</td>
                            <td class="px-3 py-2 tabular-nums">{{ v.open_defects_count }}</td>
                            <td class="px-3 py-2"><VehicleStatusBadge :state="v.state" :blocking="v.blocking_defects_count" :active="v.is_active" /></td>
                            <td class="px-3 py-2">{{ v.fms_status === null ? '–' : `${v.fms_status} – ${v.fms_label}` }}</td>
                            <td class="whitespace-nowrap px-3 py-2 text-right">
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    :aria-expanded="expanded === v.id"
                                    :aria-label="trans('vehicles.preview.toggle', { name: v.name })"
                                    @click="expanded = expanded === v.id ? null : v.id"
                                >
                                    {{ trans('vehicles.preview.title') }}
                                </Button>
                            </td>
                        </tr>
                        <tr v-if="expanded === v.id" class="border-b border-border bg-(--fill-3)/40">
                            <td :colspan="can.manage ? 9 : 8" class="px-3 py-3">
                                <VehiclePreview :id="v.id" :open-count="v.open_defects_count" :blocking="v.blocking_defects_count" :defects="v.preview_defects" :can-manage="can.manage" />
                            </td>
                        </tr>
                    </template>
                    <tr v-if="!vehicles.data.length">
                        <td :colspan="can.manage ? 9 : 8" class="px-3 py-6 text-center text-text-2">{{ trans('vehicles.empty') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Pagination :from="vehicles.from" :to="vehicles.to" :total="vehicles.total" :prev-url="vehicles.prev_page_url" :next-url="vehicles.next_page_url" />

        <Dialog :open="confirming" @update:open="(v) => !v && (confirming = false)">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{{ trans('vehicles.bulk.delete') }}</DialogTitle>
                </DialogHeader>
                <p class="text-sm">{{ trans('vehicles.bulk.delete_confirm', { count: String(selected.length) }) }}</p>
                <DialogFooter>
                    <Button variant="ghost" @click="confirming = false">{{ trans('vehicles.cancel') }}</Button>
                    <Button variant="destructive" @click="apply">{{ trans('vehicles.delete') }}</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
