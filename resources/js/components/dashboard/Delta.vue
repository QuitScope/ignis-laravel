<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import FaIcon from '@/components/FaIcon.vue';
import { faIcons } from '@/lib/faIcons';

// Legacy $dashboardDelta: neutral pill with an arrow and the difference to yesterday at this time.
defineProps<{ value: number }>();
</script>

<template>
    <span class="ignis-delta">
        <template v-if="value > 0"><span class="sr-only">{{ trans('dashboard.delta.up') }} </span><FaIcon :icon="faIcons['arrow-up']" class="size-3" />{{ value }}</template>
        <template v-else-if="value < 0"><span class="sr-only">{{ trans('dashboard.delta.down') }} </span><FaIcon :icon="faIcons['arrow-down']" class="size-3" />{{ -value }}</template>
        <template v-else><span aria-hidden="true">0</span><span class="sr-only">{{ trans('dashboard.delta.same') }}</span></template>
    </span>
</template>
