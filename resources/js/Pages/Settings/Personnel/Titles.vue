<script setup lang="ts">
import LookupTable, { type LookupField, type LookupRow } from '@/components/LookupTable.vue';

defineProps<{ rows: LookupRow[]; can: { manage: boolean } }>();

const fields: LookupField[] = [
    { key: 'name', type: 'text', required: true, max: 50 },
    { key: 'priority', type: 'number', required: true },
];
</script>

<template>
    <LookupTable
        title="personnel_settings.titles.title"
        lead="personnel_settings.titles.lead"
        path="/settings/personnel/titles"
        :rows="rows"
        :fields="fields"
        :can="can"
    />
</template>
