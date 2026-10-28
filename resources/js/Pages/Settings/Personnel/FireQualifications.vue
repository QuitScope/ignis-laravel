<script setup lang="ts">
import LookupTable, { type LookupField, type LookupRow } from '@/components/LookupTable.vue';

defineProps<{ rows: LookupRow[]; can: { manage: boolean } }>();

const fields: LookupField[] = [
    { key: 'priority', type: 'number', required: true },
    { key: 'short_name', type: 'text', required: true, max: 255 },
    { key: 'name', type: 'text', required: true, max: 255 },
    { key: 'name_male', type: 'text', required: true, max: 255 },
    { key: 'name_female', type: 'text', required: true, max: 255 },
    { key: 'is_none', type: 'checkbox' },
];
</script>

<template>
    <LookupTable
        title="personnel_settings.fire.title"
        lead="personnel_settings.fire.lead"
        path="/settings/personnel/fire-qualifications"
        :rows="rows"
        :fields="fields"
        :can="can"
    />
</template>
