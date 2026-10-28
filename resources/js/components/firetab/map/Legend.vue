<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { TYPE_COLOR } from '@/lib/leafletMap';

defineProps<{ colors: string[] }>();
</script>

<template>
    <section class="rounded-md border border-border p-3 text-sm" :aria-label="trans('firetab.map.legend')">
        <h2 class="mb-2 font-semibold">{{ trans('firetab.map.legend') }}</h2>
        <ul class="flex flex-wrap gap-x-4 gap-y-1">
            <li v-for="(color, type) in TYPE_COLOR" :key="type" class="flex items-center gap-2">
                <span class="inline-block size-4 rounded-full" :style="{ background: color }" aria-hidden="true" />
                {{ type === 'custom' ? trans('firetab.map.type_custom') : type }}
            </li>
        </ul>
        <ul class="mt-2 flex flex-wrap gap-x-4 gap-y-1">
            <li v-for="color in colors" :key="color" class="flex items-center gap-2">
                <span class="inline-block size-4 rounded-sm border" :style="{ background: color, borderColor: color }" aria-hidden="true" />
                {{ trans(`firetab.map.colors.${color.slice(1)}`) }}
            </li>
        </ul>
    </section>
</template>
