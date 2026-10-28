<script setup lang="ts">
import { trans, transChoice } from 'laravel-vue-i18n';

withDefaults(defineProps<{ state: 'operable' | 'blocked' | 'inactive'; blocking: number; active?: boolean }>(), { active: undefined });

const tone = {
    operable: 'bg-(--ok-soft) text-ok',
    blocked: 'bg-(--danger-soft) text-danger',
    inactive: 'bg-(--fill-3) text-text-2',
};
</script>

<template>
    <span class="inline-flex flex-wrap gap-1">
        <span class="inline-block rounded-full px-2 py-0.5 text-xs font-medium" :class="tone[state]">
            {{ state === 'blocked' ? transChoice('vehicles.state.blocked', blocking) : trans(`vehicles.state.${state}`) }}
        </span>
        <span
            v-if="active !== undefined"
            class="inline-block rounded-full px-2 py-0.5 text-xs font-medium"
            :class="active ? 'bg-(--info-soft) text-info' : 'bg-(--fill-3) text-text-2'"
            :title="trans('vehicles.field.is_active_hint')"
        >{{ trans(active ? 'vehicles.switch.on' : 'vehicles.switch.off') }}</span>
    </span>
</template>
