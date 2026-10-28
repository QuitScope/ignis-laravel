<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { triageClass } from '@/lib/manv';

defineProps<{ triage: string | null }>();
</script>

<template>
    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold whitespace-nowrap" :class="triageClass(triage)">
        {{ triage ? trans(`manvboard.triage.${triage}`) : trans('manvboard.triage.none') }}
    </span>
</template>
