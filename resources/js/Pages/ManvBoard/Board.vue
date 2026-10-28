<script setup lang="ts">
import { computed } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { Button } from '@/components/ui/button';
import PatientTable from '@/components/manvboard/PatientTable.vue';
import TriageColumn from '@/components/manvboard/TriageColumn.vue';
import { type BoardPatient, lanes } from '@/lib/manv';
import { usePulse } from '@/lib/realtime';

type Resource = { id: number; label: string; vehicle_type: string | null; status: string; load: number };

const props = defineProps<{
    incident: { id: number; incident_number: string; location: string; status: string; started_at: string; lna_name: string | null; orgl_name: string | null };
    patients: BoardPatient[];
    resources: Resource[];
    stats: { total: number; waiting: number; departed: number };
    sort: { key: string; dir: string };
    can: { write: boolean; manage: boolean };
}>();

const boardLanes = computed(() => lanes(props.patients));
const departureError = computed(() => (usePage().props.errors as Record<string, string | undefined>).departure);

usePulse(`manv.${props.incident.id}`, ['board'], () => router.reload({ only: ['incident', 'patients', 'resources', 'stats', 'can'] }), { pollMs: 20_000, whileHidden: true });

function depart(p: BoardPatient) {
    if (!confirm(trans('manvboard.board.depart_confirm', { number: p.number }))) return;
    router.post(`/mci/${props.incident.id}/patients/${p.id}/departure`, {}, { preserveScroll: true });
}
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <p class="text-sm text-text-2">{{ trans(`manvboard.status.${incident.status}`) }}</p>
                <h1 class="text-2xl font-semibold">{{ incident.incident_number }}</h1>
                <p class="text-sm text-text-2">{{ incident.location }} · {{ trans('manvboard.board.started', { at: incident.started_at }) }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <Button as-child variant="ghost"><Link :href="`/mci/${incident.id}/log`">{{ trans('manvboard.log') }}</Link></Button>
                <Button as-child variant="ghost"><Link :href="`/mci/${incident.id}/resources`">{{ trans('manvboard.resources.nav') }}</Link></Button>
                <Button v-if="can.manage" as-child variant="secondary"><Link :href="`/mci/${incident.id}/edit`">{{ trans('manvboard.edit') }}</Link></Button>
                <Button v-if="can.write" as-child><Link :href="`/mci/${incident.id}/patients/create`">{{ trans('manvboard.board.new_patient') }}</Link></Button>
            </div>
        </div>

        <p v-if="incident.status !== 'active'" class="rounded-md bg-(--fill-3) px-3 py-2 text-sm text-text-2">{{ trans('manvboard.board.readonly') }}</p>
        <p v-if="departureError" class="rounded-md bg-danger/10 px-3 py-2 text-sm text-danger" role="alert">{{ departureError }}</p>

        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-lg border border-border bg-card p-3 text-sm">
                <strong>{{ trans('manvboard.col.lna') }}:</strong> {{ incident.lna_name ?? trans('manvboard.board.unassigned') }}
            </div>
            <div class="rounded-lg border border-border bg-card p-3 text-sm">
                <strong>{{ trans('manvboard.col.orgl') }}:</strong> {{ incident.orgl_name ?? trans('manvboard.board.unassigned') }}
            </div>
            <div class="rounded-lg border border-border bg-card p-3">
                <p class="text-sm text-text-2">{{ trans('manvboard.board.on_site') }}</p>
                <p class="text-2xl font-semibold tabular-nums">{{ stats.waiting }}</p>
                <p class="text-xs text-text-2">{{ trans('manvboard.board.total', { total: String(stats.total) }) }}</p>
            </div>
            <div class="rounded-lg border border-border bg-card p-3">
                <p class="text-sm text-text-2">{{ trans('manvboard.board.transported') }}</p>
                <p class="text-2xl font-semibold tabular-nums">{{ stats.departed }}</p>
            </div>
        </div>

        <div class="flex gap-3 overflow-x-auto pb-2" role="group" :aria-label="trans('manvboard.board.lanes')">
            <TriageColumn v-for="l in boardLanes" :key="l.key" :lane="l.key" :patients="l.patients" :incident-id="incident.id" :can-write="can.write" @depart="depart" />
        </div>

        <h2 class="text-lg font-semibold">{{ trans('manvboard.board.table') }}</h2>
        <PatientTable :patients="patients" :incident-id="incident.id" :sort="sort" :can-write="can.write" @depart="depart" />

        <h2 class="text-lg font-semibold">{{ trans('manvboard.board.vehicles') }}</h2>
        <ul v-if="resources.length" class="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
            <li v-for="r in resources" :key="r.id" class="rounded-lg border border-border bg-card p-3 text-sm">
                <p class="font-semibold">{{ r.label }}</p>
                <p class="text-text-2">
                    <template v-if="r.vehicle_type">{{ r.vehicle_type }} · </template>{{ trans(`manvboard.resource_status.${r.status}`) }} ·
                    {{ trans('manvboard.board.load', { count: String(r.load) }) }}
                </p>
            </li>
        </ul>
        <p v-else class="text-sm text-text-2">{{ trans('manvboard.board.vehicles_empty') }}</p>

        <p class="text-xs text-text-2">{{ trans('manvboard.board.refreshing') }}</p>
        <Link href="/mci" class="text-sm hover:underline">← {{ trans('manvboard.back') }}</Link>
    </div>
</template>
