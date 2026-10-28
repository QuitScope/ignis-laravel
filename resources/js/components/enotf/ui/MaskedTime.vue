<script setup lang="ts">
/**
 * Time as a text field `HH:MM`, 24 h (legacy force-24h-time.js). Formats on blur; emits `HH:MM`, null when emptied.
 * `fillNow`: tapping the empty field takes that time (server time).
 */
import { ref, watch } from 'vue';
import { parseTime } from '@/lib/enotfUi';

const props = defineProps<{ modelValue: string | null | undefined; disabled?: boolean; label?: string; id?: string; fillNow?: () => string }>();
const emit = defineEmits<{ 'update:modelValue': [value: string | null] }>();
const text = ref(props.modelValue ?? '');
const bad = ref(false);
watch(
    () => props.modelValue,
    (v) => {
        text.value = v ?? '';
        bad.value = false;
    },
);
const commit = () => {
    const raw = text.value.trim();
    const t = raw === '' ? null : parseTime(raw);
    bad.value = raw !== '' && t === null;
    if (bad.value) return;
    text.value = t ?? '';
    if (t !== (props.modelValue ?? null)) emit('update:modelValue', t);
};
const tap = () => {
    if (props.disabled || !props.fillNow || text.value !== '') return;
    text.value = props.fillNow();
    commit();
};
</script>

<template>
    <input
        :id="id"
        v-model="text"
        type="text"
        inputmode="numeric"
        autocomplete="off"
        maxlength="5"
        placeholder="HH:MM"
        class="enotf-input"
        :aria-label="label"
        :aria-invalid="bad"
        :disabled="disabled"
        @input="text = text.replace(/[^0-9:]/g, '')"
        @click="tap"
        @blur="commit"
        @keydown.enter.prevent="commit"
    />
</template>
