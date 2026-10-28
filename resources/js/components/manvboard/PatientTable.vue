<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { Button } from '@/components/ui/button';
import TriageBadge from '@/components/manvboard/TriageBadge.vue';
import { type BoardPatient, patientState } from '@/lib/manv';

// Patients on site as a table, sorted on the server (?sort=&dir=); headers are links.
const props = defineProps<{ patients: BoardPatient[]; incidentId: number; sort: { key: string; dir: string }; canWrite: boolean }>();
defineEmits<{ depart: [patient: BoardPatient] }>();

const columns = [
    { key: 'number', label: 'manvboard.col.patient_number' },
    { key: 'triage', label: 'manvboard.col.triage' },
    { key: null, label: 'manvboard.col.person' },
    { key: 'vehicle', label: 'manvboard.col.vehicle' },
    { key: 'destination', label: 'manvboard.col.destination' },
    { key: null, label: 'manvboard.col.state' },
];

function href(key: string): string {
    const dir = props.sort.key === key && props.sort.dir === 'asc' ? 'desc' : 'asc';
    return `/mci/${props.incidentId}?sort=${key}&dir=${dir}`;
}
const ariaSort = (key: string | null) => (key !== null && props.sort.key === key ? (props.sort.dir === 'asc' ? 'ascending' : 'descending') : undefined);
</script>

<template>
    <div class="overflow-x-auto rounded-lg border border-border bg-card shadow-(--shadow)">
        <table class="w-full text-left text-sm">
            <thead class="border-b border-border text-text-2">
                <tr>
                    <th v-for="c in columns" :key="c.label" class="px-3 py-2 font-medium" :aria-sort="ariaSort(c.key)">
                        <Link v-if="c.key" :href="href(c.key)" preserve-scroll preserve-state class="hover:underline">
                            {{ trans(c.label) }}<span v-if="sort.key === c.key" aria-hidden="true">{{ sort.dir === 'asc' ? ' ▲' : ' ▼' }}</span>
                        </Link>
                        <template v-else>{{ trans(c.label) }}</template>
                    </th>
                    <th class="px-3 py-2" />
                </tr>
            </thead>
            <tbody>
                <tr v-for="p in patients" :key="p.id" class="border-b border-border last:border-0">
                    <td class="px-3 py-2 font-mono font-semibold">{{ p.number }}</td>
                    <td class="px-3 py-2"><TriageBadge :triage="p.triage" /></td>
                    <td class="px-3 py-2">{{ p.age ?? '?' }} {{ trans(`manvboard.sex_short.${p.sex}`) }}</td>
                    <td class="px-3 py-2">{{ p.vehicle ?? trans('manvboard.board.unassigned') }}</td>
                    <td class="px-3 py-2">{{ p.destination ?? '–' }}</td>
                    <td class="px-3 py-2" :class="patientState(p) === 'ready' ? 'text-ok' : 'text-warn'">{{ trans(`manvboard.board.${patientState(p)}`) }}</td>
                    <td class="whitespace-nowrap px-3 py-2 text-right">
                        <Button v-if="canWrite && p.can_depart" size="sm" variant="secondary" @click="$emit('depart', p)">{{ trans('manvboard.board.depart') }}</Button>
                        <Button as-child variant="ghost" size="sm"><Link :href="`/mci/${incidentId}/patients/${p.id}`">{{ trans('manvboard.board.open') }}</Link></Button>
                    </td>
                </tr>
                <tr v-if="patients.length === 0">
                    <td colspan="7" class="px-3 py-6 text-center text-text-2">{{ trans('manvboard.board.empty') }}</td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
