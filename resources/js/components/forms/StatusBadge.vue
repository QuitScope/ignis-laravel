<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { statusTone } from '@/lib/formIcons';

defineProps<{ status: string }>();
</script>

<template>
    <span class="inline-block rounded-full px-2 py-0.5 text-xs font-medium whitespace-nowrap" :class="statusTone(status)">{{ trans(`forms.status.${status}`) }}</span>
</template>
