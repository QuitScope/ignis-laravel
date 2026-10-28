<script setup lang="ts">
import LookupTable, { type LookupField, type LookupRow } from '@/components/LookupTable.vue';

defineProps<{ rows: LookupRow[]; can: { manage: boolean } }>();

const fields: LookupField[] = [
    { key: 'priority', type: 'number', required: true },
    { key: 'name', type: 'text', required: true, max: 255 },
    { key: 'name_male', type: 'text', required: true, max: 255 },
    { key: 'name_female', type: 'text', required: true, max: 255 },
    { key: 'badge', type: 'text', required: false, max: 255 },
    { key: 'is_archive', type: 'checkbox' },
];
</script>

<template>
    <LookupTable
        title="personnel_settings.ranks.title"
        lead="personnel_settings.ranks.lead"
        path="/settings/personnel/ranks"
        :rows="rows"
        :fields="fields"
        :can="can"
    />
</template>
