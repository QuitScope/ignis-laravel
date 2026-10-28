<script setup lang="ts">
/**
 * Date as a text field `TT.MM.JJJJ` (legacy force-german-date.js: no native date popup; DUI types with insertText).
 * Formats on blur; emits `Y-m-d`, null when emptied; an invalid entry stays visible and marked, nothing is emitted.
 */
import { inject, ref, watch } from 'vue';
import { type EnotfForm, formatDate, parseDate } from '@/lib/enotfUi';

const props = defineProps<{ modelValue: string | null | undefined; disabled?: boolean; label?: string; id?: string }>();
const emit = defineEmits<{ 'update:modelValue': [value: string | null] }>();
const form = inject<EnotfForm | null>('enotfForm', null);
const text = ref(formatDate(props.modelValue));
const bad = ref(false);
watch(
    () => props.modelValue,
    (v) => {
        text.value = formatDate(v);
        bad.value = false;
    },
);
const commit = () => {
    const raw = text.value.trim();
    const iso = raw === '' ? null : parseDate(raw, form?.clock.now());
    bad.value = raw !== '' && iso === null;
    if (bad.value) return;
    text.value = formatDate(iso);
    if (iso !== (props.modelValue ?? null)) emit('update:modelValue', iso);
};
</script>

<template>
    <input
        :id="id"
        v-model="text"
        type="text"
        inputmode="numeric"
        autocomplete="off"
        maxlength="10"
        placeholder="TT.MM.JJJJ"
        class="enotf-input"
        :aria-label="label"
        :aria-invalid="bad"
        :disabled="disabled"
        @input="text = text.replace(/[^0-9.\-]/g, '')"
        @blur="commit"
        @keydown.enter.prevent="commit"
    />
</template>
