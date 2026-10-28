<script setup lang="ts">
/**
 * Touch keypad (legacy vitals-keypad.js): digits, decimal comma, ng/nm, backspace, clear; buttons ≥ 44 px, no focus steal
 * (the active text field keeps the focus). With `code` it shows the range strip of that parameter (normal-range colours
 * and a marker at `value`), the 9b "range strip" gap.
 */
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';
import { type BzUnit, rangeStrip } from '@/lib/enotfVitals';

const props = defineProps<{ disabled?: boolean; code?: string; value?: string; bzUnit?: BzUnit }>();
const emit = defineEmits<{ press: [key: string] }>();

const keys: { key: string; label: string; aria?: string }[] = [
    ...['7', '8', '9', '4', '5', '6', '1', '2', '3'].map((d) => ({ key: d, label: d })),
    { key: ',', label: ',' },
    { key: '0', label: '0' },
    { key: 'back', label: '⌫', aria: 'enotf.vitals.backspace' },
    { key: 'ng', label: 'ng', aria: 'enotf.vitals.ng' },
    { key: 'nm', label: 'nm', aria: 'enotf.vitals.nm' },
    { key: 'clear', label: 'C', aria: 'enotf.vitals.clear' },
];
const strip = computed(() => (props.code ? rangeStrip(props.code, props.value ?? '', props.bzUnit ?? 'mg/dl') : null));
</script>

<template>
    <div class="flex gap-3">
        <div v-if="strip" class="enotf-range" aria-hidden="true">
            <div v-for="(s, i) in strip.segs" :key="i" :class="['enotf-range__seg', `enotf-range__seg--${s.grade}`]" :style="{ flexGrow: s.share }">
                <span>{{ String(s.top).replace('.', ',') }}</span>
            </div>
            <div v-if="strip.marker !== null" class="enotf-range__marker" :style="{ top: `${strip.marker}%` }" />
        </div>
        <div class="grid flex-1 grid-cols-3 gap-2" role="group" :aria-label="trans('enotf.vitals.keypad')">
            <button
                v-for="k in keys"
                :key="k.key"
                type="button"
                class="enotf-key"
                :aria-label="k.aria ? trans(k.aria) : undefined"
                :title="k.aria ? trans(k.aria) : undefined"
                :disabled="disabled"
                @mousedown.prevent
                @click="emit('press', k.key)"
            >
                {{ k.label }}
            </button>
        </div>
    </div>
</template>
