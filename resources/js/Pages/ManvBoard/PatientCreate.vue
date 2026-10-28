<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import PatientForm, { type Hospital, type Vehicle } from '@/components/manvboard/PatientForm.vue';

defineProps<{
    incident: { id: number; incident_number: string; location: string };
    vehicles: Vehicle[];
    hospitals: Hospital[];
    triages: string[];
    sexes: string[];
    no_transport: string;
}>();
</script>

<template>
    <div class="mx-auto w-full max-w-3xl space-y-4">
        <div>
            <h1 class="text-2xl font-semibold">{{ trans('manvboard.patient.create_title') }}</h1>
            <p class="text-sm text-text-2">{{ incident.incident_number }} · {{ incident.location }}</p>
        </div>
        <PatientForm
            :incident-id="incident.id"
            :patient="null"
            :vehicles="vehicles"
            :hospitals="hospitals"
            :triages="triages"
            :sexes="sexes"
            :no-transport="no_transport"
            :readonly="false"
        />
    </div>
</template>
