<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { sparks } from '@/components/auth/sparks';
import { hexToRgb } from '@/lib/color';

// Stage beside the sign-in form (legacy login.php, .ignis-login-stage): glow, sparks, the mark as a glowing outline and
// "{organisation} ◆ ıgnıs". Pure decoration, hidden from assistive technology.
defineProps<{ org: string | null }>();

const MARK = 'M24 0H96V72L72 96H0V24ZM36 80H52V44L36 60ZM40 24L50 34L60 24L50 14Z';
const SHAPES = MARK.split(/(?<=Z)/);

const stage = ref<HTMLElement>();
const canvas = ref<HTMLCanvasElement>();
let stop = () => {};

const lighten = (hex: string, keep: number) => {
    const rgb = hexToRgb(hex);
    return rgb ? `rgb(${rgb.map((c) => Math.round(c * keep + 255 * (1 - keep))).join(',')})` : '';
};

onMounted(() => {
    const css = getComputedStyle(stage.value!);
    const fill = css.getPropertyValue('--accent-fill');
    stage.value!.style.setProperty('--stage-trace', lighten(fill, 0.85));
    stage.value!.style.setProperty('--stage-trace-2', lighten(css.getPropertyValue('--accent-fill-2') || fill, 0.7));
    stop = sparks(canvas.value!);
});
onBeforeUnmount(() => stop());
</script>

<template>
    <div ref="stage" class="ignis-login-stage">
        <div class="ignis-login-stage__heat" />
        <canvas ref="canvas" class="ignis-login-stage__sparks" />
        <div class="ignis-login-stage__emblem">
            <div class="ignis-login-stage__mark">
                <svg class="ignis-login-stage__glow" viewBox="0 0 96 96">
                    <path v-for="d in SHAPES" :key="d" class="ignis-login-stage__line" pathLength="1" :d="d" />
                </svg>
                <svg viewBox="0 0 96 96">
                    <linearGradient id="ignis-login-stage-trace" x1="0" y1="1" x2="1" y2="0"><stop offset="0" /><stop offset="1" /></linearGradient>
                    <path class="ignis-login-stage__fill" :d="MARK" />
                    <path v-for="d in SHAPES" :key="d" class="ignis-login-stage__line" pathLength="1" :d="d" />
                </svg>
            </div>
            <p class="ignis-login-stage__caption">
                <template v-if="org">
                    <span class="ignis-login-stage__org">{{ org }}</span>
                    <span class="ignis-login-stage__dot" />
                </template>
                <span class="ignis-login-stage__product">ıgnıs</span>
            </p>
        </div>
    </div>
</template>
