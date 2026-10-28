<script setup lang="ts">
import { computed } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { Button } from '@/components/ui/button';
import PatientForm, { type Hospital, type PatientData, type Vehicle } from '@/components/manvboard/PatientForm.vue';
import TriageBadge from '@/components/manvboard/TriageBadge.vue';
import { triageClass } from '@/lib/manv';

const props = defineProps<{
    incident: { id: number; incident_number: string; location: string; status: string };
    patient: PatientData & { number: string; triaged_at: string | null; vehicle: string | null; departed_at: string | null; can_depart: boolean };
    vehicles: Vehicle[];
    hospitals: Hospital[];
    triages: string[];
    sexes: string[];
    no_transport: string;
    can: { write: boolean };
}>();

const base = computed(() => `/mci/${props.incident.id}/patients/${props.patient.id}`);
const errors = computed(() => usePage().props.errors as Record<string, string | undefined>);
const name = computed(() => [props.patient.first_name, props.patient.last_name].filter(Boolean).join(' '));

function setTriage(triage: string) {
    router.patch(`${base.value}/triage`, { triage }, { preserveScroll: true });
}
function depart() {
    if (confirm(trans('manvboard.board.depart_confirm', { number: props.patient.number }))) router.post(`${base.value}/departure`, {}, { preserveScroll: true });
}
</script>

<template>
    <div class="mx-auto w-full max-w-3xl space-y-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="font-mono text-2xl font-semibold">{{ patient.number }}</h1>
                <p v-if="name" class="text-lg">{{ name }}</p>
                <p class="text-sm text-text-2">{{ incident.incident_number }} · {{ incident.location }}</p>
            </div>
            <div class="text-right">
                <TriageBadge :triage="patient.triage" />
                <p v-if="patient.triaged_at" class="mt-1 text-xs text-text-2">{{ trans('manvboard.patient.triaged_at', { at: patient.triaged_at }) }}</p>
                <p v-if="patient.departed_at" class="mt-1 text-xs text-text-2">{{ trans('manvboard.patient.departed', { at: patient.departed_at }) }}</p>
            </div>
        </div>

        <p v-if="!can.write && incident.status !== 'active'" class="rounded-md bg-(--fill-3) px-3 py-2 text-sm text-text-2">{{ trans('manvboard.board.readonly') }}</p>
        <p v-if="errors.departure || errors.triage" class="rounded-md bg-danger/10 px-3 py-2 text-sm text-danger" role="alert">{{ errors.departure ?? errors.triage }}</p>

        <section v-if="can.write && !patient.departed_at" class="rounded-lg border border-border bg-card p-4 shadow-(--shadow)">
            <h2 class="mb-3 font-semibold">{{ trans('manvboard.patient.quick_triage') }}</h2>
            <div class="flex flex-wrap gap-2">
                <button
                    v-for="t in triages"
                    :key="t"
                    type="button"
                    class="rounded-md px-3 py-1.5 text-sm font-semibold"
                    :class="[triageClass(t), patient.triage === t ? 'ring-2 ring-ring ring-offset-2' : '']"
                    :aria-pressed="patient.triage === t"
                    @click="setTriage(t)"
                >
                    {{ trans(`manvboard.triage.${t}`) }} – {{ trans(`manvboard.triage_name.${t}`) }}
                </button>
            </div>
            <Button v-if="patient.can_depart" class="mt-4" variant="secondary" @click="depart">{{ trans('manvboard.board.depart') }}</Button>
        </section>

        <PatientForm
            :incident-id="incident.id"
            :patient="patient"
            :vehicles="vehicles"
            :hospitals="hospitals"
            :triages="triages"
            :sexes="sexes"
            :no-transport="no_transport"
            :readonly="!can.write"
            :frozen="patient.departed_at !== null"
        />
    </div>
</template>
