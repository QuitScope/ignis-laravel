<script setup lang="ts">
/** Availability badge of one department (plain text, colour only as an extra cue). */
import { trans } from 'laravel-vue-i18n';

defineProps<{ status: string }>();
const tone: Record<string, string> = {
    not_staffed: 'border-border text-text-2',
    available: 'border-ok text-ok',
    partially_available: 'border-warn text-warn',
    full: 'border-danger text-danger',
};
</script>

<template>
    <span :class="['inline-flex rounded-md border px-2 py-0.5 text-sm', tone[status] ?? tone.not_staffed]">{{ trans(`enotf.hospital.status.${status}`) }}</span>
</template>
