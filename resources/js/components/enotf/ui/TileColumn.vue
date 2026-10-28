<script setup lang="ts">
/**
 * A column of answer tiles (legacy edivi__interactbutton): radio = the catalog code, multiple = a list (exclusive codes
 * stand alone). Tapping the chosen radio again clears it. Writes only through enotfForm.set.
 */
import { Circle, CircleCheck, CircleDot, Square, SquareCheck } from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';
import { computed, useId } from 'vue';
import { type Code, toggleExclusive, useEnotfForm } from '@/lib/enotfUi';

const props = defineProps<{
    field: string;
    options: [Code, string][];
    multiple?: boolean;
    exclusive?: Code[];
    green?: Code;
    header?: string;
}>();
const form = useEnotfForm();
const id = useId();
const value = computed(() => form.values[props.field]);
const isOn = (code: Code) => (props.multiple ? Array.isArray(value.value) && (value.value as Code[]).includes(code) : value.value !== null && value.value !== undefined && String(value.value) === String(code));
const pick = (code: Code) => {
    if (form.readonly) return;
    if (props.multiple) form.set(props.field, toggleExclusive(value.value as Code[] | null, code, props.exclusive));
    else form.set(props.field, isOn(code) ? null : code);
};
</script>

<template>
    <div class="enotf-col" :role="multiple ? 'group' : 'radiogroup'" :aria-labelledby="header ? id : undefined">
        <div v-if="header" :id="id" class="enotf-col__head">{{ header }}</div>
        <button
            v-for="[code, label] in options"
            :key="code"
            type="button"
            :role="multiple ? 'checkbox' : 'radio'"
            :aria-checked="isOn(code)"
            :disabled="form.readonly"
            :class="['enotf-tile', String(code) === String(green) ? 'enotf-tile--ok' : '']"
            @click="pick(code)"
        >
            <span>{{ label }}</span>
            <component :is="multiple ? (isOn(code) ? SquareCheck : Square) : isOn(code) ? (String(code) === String(green) ? CircleCheck : CircleDot) : Circle" class="size-5 shrink-0" aria-hidden="true" />
        </button>
        <p v-if="form.invalid.includes(field)" role="alert" class="px-3 py-1 text-sm text-(--enotf-red)">{{ trans('enotf.field.invalid') }}</p>
        <p v-else-if="form.conflicts.includes(field)" role="status" class="px-3 py-1 text-sm text-(--enotf-yellow)">{{ trans('enotf.ui.conflict') }}</p>
    </div>
</template>
