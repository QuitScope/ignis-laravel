<script setup lang="ts">
import IncidentForm, { type IncidentData } from '@/components/manvboard/IncidentForm.vue';

defineProps<{ incident: IncidentData; statuses: string[] }>();
</script>

<template>
    <IncidentForm :incident="incident" :statuses="statuses" />
</template>
