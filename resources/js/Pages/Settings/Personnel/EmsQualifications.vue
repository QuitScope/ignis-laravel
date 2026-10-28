<script setup lang="ts">
import LookupTable, { type LookupField, type LookupRow } from '@/components/LookupTable.vue';

defineProps<{ rows: LookupRow[]; can: { manage: boolean } }>();

const fields: LookupField[] = [
    { key: 'priority', type: 'number', required: true },
    { key: 'name', type: 'text', required: true, max: 255 },
    { key: 'name_male', type: 'text', required: true, max: 255 },
    { key: 'name_female', type: 'text', required: true, max: 255 },
    { key: 'abbreviation', type: 'text', required: false, max: 50 },
    { key: 'is_none', type: 'checkbox' },
    { key: 'is_trainable', type: 'checkbox' },
];
</script>

<template>
    <LookupTable
        title="personnel_settings.ems.title"
        lead="personnel_settings.ems.lead"
        path="/settings/personnel/ems-qualifications"
        :rows="rows"
        :fields="fields"
        :can="can"
    />
</template>
