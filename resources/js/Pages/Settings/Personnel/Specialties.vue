<script setup lang="ts">
import LookupTable, { type LookupField, type LookupRow } from '@/components/LookupTable.vue';

defineProps<{ rows: LookupRow[]; can: { manage: boolean } }>();

const fields: LookupField[] = [
    { key: 'number', type: 'number', required: true },
    { key: 'name', type: 'text', required: true, max: 255 },
    { key: 'is_disabled', type: 'checkbox' },
];
</script>

<template>
    <LookupTable
        title="personnel_settings.specialties.title"
        lead="personnel_settings.specialties.lead"
        path="/settings/personnel/specialties"
        :rows="rows"
        :fields="fields"
        :can="can"
    />
</template>
