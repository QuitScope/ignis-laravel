<script setup lang="ts">
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import Pagination from '@/components/Pagination.vue';
import VehicleLink from '@/components/vehicles/VehicleLink.vue';
import DefectDialog from '@/components/vehicles/DefectDialog.vue';
import { type Assignable, type DefectRow, DEFECT_STATUSES, defectTone, time } from '@/components/vehicles/defects';
import { Button } from '@/components/ui/button';

const props = defineProps<{
    defects: { data: DefectRow[]; from: number | null; to: number | null; total: number; prev_page_url: string | null; next_page_url: string | null };
    stats: Record<'open' | 'in_progress' | 'deferred' | 'resolved' | 'blocking' | 'total', number>;
    filters: { vehicle: number | null; status: string };
    vehicles: { id: number; name: string; type: string }[];
    users: Assignable[];
    can: { manage: boolean; delete: boolean; create: boolean };
}>();

const query = (over: { vehicle?: number | null; status?: string }) => {
    const all = { vehicle: props.filters.vehicle, status: props.filters.status, ...over };
    return Object.fromEntries(Object.entries(all).filter(([, v]) => v !== null && v !== '').map(([k, v]) => [k, String(v)]));
};
const vehicle = ref<number | ''>(props.filters.vehicle ?? '');
const pickVehicle = () => router.get('/vehicles/defects', query({ vehicle: vehicle.value === '' ? null : vehicle.value }), { preserveScroll: true });
const statusHref = (status: string) => {
    const q = new URLSearchParams(query({ status })).toString();
    return '/vehicles/defects' + (q ? `?${q}` : '');
};
const reportHref = '/vehicles/defects/create' + (props.filters.vehicle ? `?vehicle=${props.filters.vehicle}` : '');

const opened = ref<number | null>(null);

const statTone: Record<string, string> = { open: 'text-danger', in_progress: 'text-warn', deferred: 'text-info', resolved: 'text-ok', blocking: 'text-danger' };
const card = 'rounded-lg border border-border bg-card p-4 shadow-(--shadow)';
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold">{{ trans('vehicles.defects.title') }}</h1>
                <p class="mt-1 text-sm text-text-2">{{ trans('vehicles.defects.lead') }}</p>
            </div>
            <div class="flex gap-2">
                <Button as-child variant="ghost">
                    <Link href="/vehicles">{{ trans('vehicles.nav') }}</Link>
                </Button>
                <Button v-if="can.create" as-child>
                    <Link :href="reportHref">{{ trans('vehicles.defects.report') }}</Link>
                </Button>
            </div>
        </div>

        <dl class="grid grid-cols-2 gap-3 sm:grid-cols-5" :aria-label="trans('vehicles.defects.stats')">
            <div v-for="key in [...DEFECT_STATUSES, 'blocking'] as const" :key="key" :class="card">
                <dt class="text-xs text-text-2">{{ key === 'blocking' ? trans('vehicles.defects.blocking') : trans(`vehicles.defect_status.${key}`) }}</dt>
                <dd class="text-2xl font-semibold tabular-nums" :class="statTone[key]">{{ stats[key] }}</dd>
            </div>
        </dl>

        <div class="flex flex-wrap items-center gap-3">
            <select v-model="vehicle" class="h-9 rounded-md border border-border bg-card px-3 text-sm" :aria-label="trans('vehicles.defects.field.vehicle')" @change="pickVehicle">
                <option value="">{{ trans('vehicles.defects.all_vehicles') }}</option>
                <option v-for="v in vehicles" :key="v.id" :value="v.id">{{ v.name }} ({{ v.type }})</option>
            </select>
            <nav class="flex flex-wrap gap-1 text-sm" :aria-label="trans('vehicles.defects.col.status')">
                <Link
                    v-for="s in ['', ...DEFECT_STATUSES]"
                    :key="s"
                    :href="statusHref(s)"
                    preserve-scroll
                    class="rounded-md px-3 py-1.5 hover:bg-(--fill-3)"
                    :class="filters.status === s ? 'bg-(--fill-3) font-medium' : ''"
                    :aria-current="filters.status === s ? 'true' : undefined"
                >
                    {{ s === '' ? trans('vehicles.defects.all_statuses') : trans(`vehicles.defect_status.${s}`) }}
                    <span class="text-xs text-text-2 tabular-nums">{{ s === '' ? stats.total : stats[s] }}</span>
                </Link>
            </nav>
        </div>

        <div :class="[card, 'overflow-x-auto p-0']">
            <table class="w-full text-sm">
                <thead class="border-b border-border text-left text-text-2">
                    <tr>
                        <th class="px-4 py-2 font-medium">{{ trans('vehicles.defects.col.defect') }}</th>
                        <th class="px-4 py-2 font-medium">{{ trans('vehicles.defects.col.vehicle') }}</th>
                        <th class="px-4 py-2 font-medium">{{ trans('vehicles.defects.col.status') }}</th>
                        <th class="px-4 py-2 font-medium">{{ trans('vehicles.defects.col.assignee') }}</th>
                        <th class="px-4 py-2 font-medium">{{ trans('vehicles.defects.col.last_event') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="d in defects.data" :key="d.id" class="border-b border-border last:border-0">
                        <td class="px-4 py-2">
                            <button type="button" class="text-left font-medium hover:underline" @click="opened = d.id">{{ d.title }}</button>
                            <span class="block text-xs text-text-2">
                                {{ trans(`vehicles.defect_category.${d.category}`) }} · {{ time(d.created_at) }}<template v-if="d.reporter"> · {{ d.reporter }}</template>
                            </span>
                        </td>
                        <td class="px-4 py-2"><VehicleLink :id="d.vehicle.id" :name="d.vehicle.name" /></td>
                        <td class="px-4 py-2">
                            <span class="inline-flex flex-wrap gap-1">
                                <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="defectTone[d.status]">{{ trans(`vehicles.defect_status.${d.status}`) }}</span>
                                <span v-if="d.is_blocking && d.status !== 'resolved'" class="rounded-full bg-(--danger-soft) px-2 py-0.5 text-xs font-medium text-danger">
                                    {{ trans('vehicles.defects.blocking') }}
                                </span>
                            </span>
                        </td>
                        <td class="px-4 py-2">{{ d.assignee ?? '–' }}</td>
                        <td class="px-4 py-2 text-xs">
                            <template v-if="d.last_event">
                                <span class="block">{{ d.last_event.details ?? d.last_event.action }}</span>
                                <span class="block text-text-2">{{ time(d.last_event.time) }}<template v-if="d.last_event.user"> · {{ d.last_event.user }}</template></span>
                            </template>
                            <template v-else>–</template>
                        </td>
                    </tr>
                    <tr v-if="defects.data.length === 0">
                        <td colspan="5" class="px-4 py-6 text-center text-text-2">{{ trans('vehicles.defects.empty') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Pagination :from="defects.from" :to="defects.to" :total="defects.total" :prev-url="defects.prev_page_url" :next-url="defects.next_page_url" />

        <DefectDialog :defect-id="opened" :users="users" :can="can" @close="opened = null" />
    </div>
</template>
