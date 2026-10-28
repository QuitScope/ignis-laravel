<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import FaIcon from '@/components/FaIcon.vue';
import { faIcons } from '@/lib/faIcons';
import Delta from './Delta.vue';

// "Einsätze heute" (legacy kpis.php). rd/fire are null when that source is not visible to the viewer.
defineProps<{ data: { today: number; rd?: number | null; fire?: number | null; delta: number } }>();
</script>

<template>
    <div class="ignis-kpi">
        <div class="ignis-kpi__top">
            <span class="ignis-kpi__label"><FaIcon :icon="faIcons['truck-fast']" />{{ trans('dashboard.kpi.incidents') }}</span>
            <span><Delta :value="data.delta" /><span class="sr-only"> {{ trans('dashboard.delta.vs') }}</span></span>
        </div>
        <div class="ignis-kpi__value">{{ data.today }}</div>
        <div class="ignis-kpi__sub">
            {{ data.rd == null ? trans('dashboard.kpi.only_fire') : data.fire == null ? trans('dashboard.kpi.only_rd') : trans('dashboard.kpi.incidents_rd', { count: String(data.rd) }) }}
        </div>
    </div>
</template>
