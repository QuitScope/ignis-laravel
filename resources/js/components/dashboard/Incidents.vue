<script setup lang="ts">
import { computed } from 'vue';
import { trans } from 'laravel-vue-i18n';
import Bezel from './Bezel.vue';
import Delta from './Delta.vue';
import Sparkline from './Sparkline.vue';

// "Einsätze" (legacy incidents.php): per hour since 0:00 and per day over seven days; the head value is the last one.
const props = defineProps<{ data: { hours: number[]; days: number[]; delta: number } }>();

const series = computed(() => [
    { name: 'dashboard.incidents.per_hour', label: 'dashboard.incidents.hour_label', values: props.data.hours },
    { name: 'dashboard.incidents.per_day', label: 'dashboard.incidents.day_label', values: props.data.days },
]);
const last = (values: number[]) => String(values[values.length - 1] ?? 0);
</script>

<template>
    <Bezel icon="chart-line" title-id="home-incidents-title">
        <template #title>{{ trans('dashboard.incidents.title') }}</template>
        <div class="ignis-sparks">
            <div v-for="s in series" :key="s.name" class="ignis-spark">
                <div class="ignis-spark__head">
                    <span class="ignis-spark__label">{{ trans(s.name) }}</span><b class="ignis-spark__value">{{ last(s.values) }}</b>
                </div>
                <Sparkline :values="s.values" :label="trans('dashboard.spark.last', { label: trans(s.label), value: last(s.values) })" />
            </div>
        </div>
        <template #foot><Delta :value="data.delta" />{{ trans('dashboard.delta.vs') }}</template>
    </Bezel>
</template>
