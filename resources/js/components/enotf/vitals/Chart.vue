<script setup lang="ts">
/**
 * Vital signs course as plain SVG (no library, Chromium 103, prints on paper): one line per parameter, told apart by
 * dash pattern and the legend, so it reads without colour. Two axes like the legacy chart: left SpO₂/AF/etCO₂/Temp,
 * right RR/HF/BZ. 'ng'/'nm' are no points.
 */
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';
import { series, type VitalRow } from '@/lib/enotfVitals';

const props = defineProps<{ rows: VitalRow[] }>();

const W = 700;
const H = 320;
const PAD = { l: 40, r: 40, t: 10, b: 30 };
const DASH: Record<string, string> = { spo2: '', herzfreq: '8 4', rrsys: '2 3', rrdias: '2 3', atemfreq: '12 4 2 4', etco2: '4 4', bz: '1 5', temp: '10 2' };

const data = computed(() => series(props.rows));
const x = (i: number) => PAD.l + (data.value.times.length < 2 ? (W - PAD.l - PAD.r) / 2 : (i * (W - PAD.l - PAD.r)) / (data.value.times.length - 1));
const y = (v: number, right = false) => PAD.t + (1 - v / (right ? data.value.maxRight : data.value.max)) * (H - PAD.t - PAD.b);
const ticks = [0, 1, 2, 3, 4];
const short = (t: string) => t.slice(11, 16);
</script>

<template>
    <figure v-if="data.lines.length" class="m-0">
        <svg :viewBox="`0 0 ${W} ${H}`" class="h-auto w-full" role="img" :aria-label="trans('enotf.print.vitals')">
            <g class="text-[10px]" fill="currentColor">
                <template v-for="i in ticks" :key="i">
                    <line :x1="PAD.l" :x2="W - PAD.r" :y1="y((data.max / 4) * i)" :y2="y((data.max / 4) * i)" stroke="currentColor" stroke-opacity="0.15" />
                    <text :x="PAD.l - 4" :y="y((data.max / 4) * i) + 3" text-anchor="end">{{ (data.max / 4) * i }}</text>
                    <text :x="W - PAD.r + 4" :y="y((data.maxRight / 4) * i, true) + 3">{{ (data.maxRight / 4) * i }}</text>
                </template>
                <text v-for="(t, i) in data.times" :key="t" :x="x(i)" :y="H - 10" text-anchor="middle">{{ short(t) }}</text>
            </g>
            <g v-for="l in data.lines" :key="l.code" fill="none" stroke="currentColor" stroke-width="2">
                <polyline :points="l.points.map(([i, v]) => `${x(i)},${y(v, l.right)}`).join(' ')" :stroke-dasharray="DASH[l.code]" />
                <circle v-for="([i, v], n) in l.points" :key="n" :cx="x(i)" :cy="y(v, l.right)" r="3" fill="currentColor" />
            </g>
        </svg>
        <figcaption class="flex flex-wrap gap-x-4 gap-y-1 text-xs">
            <span v-for="l in data.lines" :key="l.code" class="inline-flex items-center gap-1">
                <svg width="28" height="8" aria-hidden="true"><line x1="0" x2="28" y1="4" y2="4" stroke="currentColor" stroke-width="2" :stroke-dasharray="DASH[l.code]" /></svg>
                {{ trans(`enotf.vitals.p.${l.code}`) }} ({{ trans(l.right ? 'enotf.ui.vl.axis_right' : 'enotf.ui.vl.axis_left') }})
            </span>
        </figcaption>
    </figure>
</template>
