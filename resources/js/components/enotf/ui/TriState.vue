<script setup lang="ts">
/**
 * Three-state button (legacy special-rights button): – -> nein -> ja -> –, catalog codes 'nein'/'ja'.
 * With `field` it writes through enotfForm.set; without, it is a v-model (focus views keep values until "speichern").
 */
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';
import { useEnotfForm } from '@/lib/enotfUi';

const props = defineProps<{ field?: string; modelValue?: string | null; label: string }>();
const emit = defineEmits<{ 'update:modelValue': [value: string | null] }>();
const form = useEnotfForm();
const NEXT: Record<string, string | null> = { '': 'nein', nein: 'ja', ja: null };
const value = computed(() => String((props.field ? form.values[props.field] : props.modelValue) ?? ''));
const color = computed(() => ({ ja: 'text-(--enotf-green)', nein: 'text-(--enotf-red)' })[value.value] ?? 'text-(--enotf-label)');
const cycle = () => {
    const next = NEXT[value.value] ?? null;
    if (props.field) form.set(props.field, next);
    emit('update:modelValue', next);
};
</script>

<template>
    <button type="button" class="enotf-tile" :aria-label="`${label}: ${value || trans('enotf.protocol.none')}`" :disabled="form.readonly" @click="cycle">
        <span>{{ label }}</span>
        <span :class="color">{{ value || '–' }}</span>
    </button>
</template>
