<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { PlaneTakeoff } from '@lucide/vue';

// "Absent today": names derived from today's all-day absence events of the feed (no extra endpoint).
defineProps<{ names: string[] }>();
</script>

<template>
    <section class="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-lg border border-border bg-card px-4 py-2 text-sm" :aria-label="trans('calendar.absent_today')" data-test="absence-today">
        <h2 class="flex items-center gap-2 font-medium"><PlaneTakeoff class="size-4" aria-hidden="true" />{{ trans('calendar.absent_today') }}</h2>
        <p v-if="names.length === 0" class="text-text-2">{{ trans('calendar.absent_none') }}</p>
        <ul v-else class="flex flex-wrap gap-2">
            <li v-for="n in names" :key="n" class="rounded-full bg-(--fill-3) px-2.5 py-0.5">{{ n }}</li>
        </ul>
    </section>
</template>
