<script setup lang="ts">
import { ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import Pagination from '@/components/Pagination.vue';
import EntryDialog, { type Entry } from '@/components/logbook/EntryDialog.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';

type Filters = { vehicle: string; type: string; from: string; to: string };

const props = defineProps<{
    entries: { data: Entry[]; from: number | null; to: number | null; total: number; prev_page_url: string | null; next_page_url: string | null };
    filters: Filters;
    stats: { count: number; km: number };
    types: string[];
    vehicles: { id: number; label: string }[];
    can: { manage: boolean };
}>();

const vehicle = ref(props.filters.vehicle);
const type = ref(props.filters.type);
const from = ref(props.filters.from);
const to = ref(props.filters.to);

function load() {
    const all = { vehicle: vehicle.value, type: type.value, from: from.value, to: to.value };
    router.get('/logbook', Object.fromEntries(Object.entries(all).filter(([, v]) => v !== '')), { preserveState: true, preserveScroll: true, replace: true });
}
watch([vehicle, type, from, to], load);
function reset() {
    vehicle.value = type.value = from.value = to.value = '';
}

const editing = ref<Entry | null>(null);
const formOpen = ref(false);
function open(e: Entry | null) {
    editing.value = e;
    formOpen.value = true;
}

const deleting = ref<Entry | null>(null);
function destroy() {
    if (deleting.value === null) return;
    router.delete(`/logbook/${deleting.value.id}`, { preserveScroll: true, onSuccess: () => (deleting.value = null) });
}

const select = 'h-9 rounded-md border border-border bg-card px-3 text-sm';
const km = (n: number) => n.toLocaleString(undefined, { minimumFractionDigits: 1, maximumFractionDigits: 1 });
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold">{{ trans('logbook.title') }}</h1>
                <p class="mt-1 text-sm text-text-2" data-test="stats">{{ trans('logbook.stats', { count: String(stats.count), km: km(stats.km) }) }}</p>
            </div>
            <Button v-if="can.manage" @click="open(null)">{{ trans('logbook.new') }}</Button>
        </div>

        <div class="flex flex-wrap items-end gap-3">
            <select v-model="vehicle" :class="select" :aria-label="trans('logbook.filter.vehicle')">
                <option value="">{{ trans('logbook.filter.vehicle') }}: {{ trans('logbook.filter.all') }}</option>
                <option v-for="v in vehicles" :key="v.id" :value="String(v.id)">{{ v.label }}</option>
            </select>
            <select v-model="type" :class="select" :aria-label="trans('logbook.filter.type')">
                <option value="">{{ trans('logbook.filter.type') }}: {{ trans('logbook.filter.all') }}</option>
                <option v-for="t in types" :key="t" :value="t">{{ trans(`logbook.type.${t}`) }}</option>
            </select>
            <label class="text-sm text-text-2">{{ trans('logbook.filter.from') }} <Input v-model="from" type="date" class="w-40" /></label>
            <label class="text-sm text-text-2">{{ trans('logbook.filter.to') }} <Input v-model="to" type="date" class="w-40" /></label>
            <Button variant="ghost" size="sm" @click="reset">{{ trans('logbook.filter.reset') }}</Button>
        </div>

        <div class="overflow-x-auto rounded-lg border border-border bg-card shadow-(--shadow)">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-border text-text-2">
                    <tr>
                        <th class="px-3 py-2 font-medium">{{ trans('logbook.col.date') }}</th>
                        <th class="px-3 py-2 font-medium">{{ trans('logbook.col.times') }}</th>
                        <th class="px-3 py-2 font-medium">{{ trans('logbook.col.vehicle') }}</th>
                        <th class="px-3 py-2 font-medium">{{ trans('logbook.col.type') }}</th>
                        <th class="px-3 py-2 text-right font-medium">{{ trans('logbook.col.km') }}</th>
                        <th class="px-3 py-2 font-medium">{{ trans('logbook.col.driver') }}</th>
                        <th class="px-3 py-2 font-medium">{{ trans('logbook.col.reason') }}</th>
                        <th class="px-3 py-2 font-medium">{{ trans('logbook.col.source') }}</th>
                        <th v-if="can.manage" class="px-3 py-2" />
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="e in entries.data" :key="e.id" class="border-b border-border last:border-0">
                        <td class="px-3 py-2 tabular-nums">{{ e.trip_date }}</td>
                        <td class="px-3 py-2 tabular-nums">
                            {{ e.departed_at }} – {{ e.arrived_at ?? '–' }}
                            <span v-if="e.past_midnight" class="ml-1 rounded-full bg-(--fill-3) px-2 py-0.5 text-xs text-text-2">{{ trans('logbook.past_midnight') }}</span>
                        </td>
                        <td class="px-3 py-2">
                            {{ e.vehicle_identifier }}
                            <span v-if="e.station_name" class="block text-xs text-text-2">{{ e.station_name }}</span>
                        </td>
                        <td class="px-3 py-2">{{ trans(`logbook.type.${e.trip_type}`) }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ km(e.distance_km) }}</td>
                        <td class="px-3 py-2">{{ e.driver_name }}</td>
                        <td class="max-w-xs truncate px-3 py-2" :title="e.reason ?? ''">{{ e.reason }}</td>
                        <td class="px-3 py-2">{{ trans(`logbook.source.${e.source}`) }}</td>
                        <td v-if="can.manage" class="whitespace-nowrap px-3 py-2 text-right">
                            <Button variant="ghost" size="sm" @click="open(e)">{{ trans('logbook.edit') }}</Button>
                            <Button variant="ghost" size="sm" @click="deleting = e">{{ trans('logbook.delete') }}</Button>
                        </td>
                    </tr>
                    <tr v-if="entries.data.length === 0">
                        <td :colspan="can.manage ? 9 : 8" class="px-3 py-6 text-center text-text-2">{{ trans('logbook.empty') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Pagination :from="entries.from" :to="entries.to" :total="entries.total" :prev-url="entries.prev_page_url" :next-url="entries.next_page_url" />

        <EntryDialog v-if="can.manage" :open="formOpen" :entry="editing" :vehicles="vehicles" :types="types" @close="formOpen = false" />

        <Dialog :open="deleting !== null" @update:open="(v) => !v && (deleting = null)">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{{ trans('logbook.delete') }}</DialogTitle>
                </DialogHeader>
                <p v-if="deleting" class="text-sm">{{ trans('logbook.delete_confirm', { date: deleting.trip_date, vehicle: deleting.vehicle_identifier }) }}</p>
                <DialogFooter>
                    <Button variant="ghost" @click="deleting = null">{{ trans('logbook.cancel') }}</Button>
                    <Button variant="destructive" @click="destroy">{{ trans('logbook.delete') }}</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
