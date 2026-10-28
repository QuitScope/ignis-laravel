<script setup lang="ts">
import { computed, useId } from 'vue';
import { sparkPath } from '@/lib/dashboardWidgets';

// Legacy spark.js as a component: line and fading area in the series colour (cyan), dot at the last value.
const props = defineProps<{ values: number[]; label: string }>();

const W = 100;
const H = 40;
const path = computed(() => sparkPath(props.values, W, H));
const id = `ignis-spark-${useId()}`;
</script>

<template>
    <div class="ignis-spark__plot" role="img" :aria-label="label">
        <svg :viewBox="`0 0 ${W} ${H}`" preserveAspectRatio="none" aria-hidden="true" focusable="false">
            <defs>
                <linearGradient :id="id" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0" stop-color="currentColor" stop-opacity=".3" />
                    <stop offset="1" stop-color="currentColor" stop-opacity="0" />
                </linearGradient>
            </defs>
            <path :d="path.area" :fill="`url(#${id})`" />
            <path class="ignis-spark__line" :d="path.line" />
        </svg>
        <span class="ignis-spark__end" aria-hidden="true" :style="{ left: `${path.end[0]}%`, top: `${(path.end[1] / H) * 100}%` }" />
    </div>
</template>
