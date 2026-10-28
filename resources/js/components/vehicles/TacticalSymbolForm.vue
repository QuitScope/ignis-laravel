<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import TacticalSymbolPreview from '@/components/vehicles/TacticalSymbolPreview.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { emptySymbol, type SymbolTemplate, type SymbolValues } from '@/lib/tacticalSymbol';
import { SYMBOL_SELECTS, SYMBOL_TEXTS, symbolOptions, type SymbolKey } from '@/lib/tacticalSymbolOptions';

// The fields of one tactical symbol with a live preview. `errors` and `prefix` take the server's field errors
// (`prefix.grundzeichen`, ...); `name` is the vehicle's own symbol name and is optional.
const props = defineProps<{
    modelValue: SymbolValues;
    prefix: string;
    errors?: Record<string, string | undefined>;
    name?: string;
    nameError?: string;
    templates?: SymbolTemplate[];
}>();
const emit = defineEmits<{ 'update:modelValue': [SymbolValues]; 'update:name': [string] }>();

const field = 'h-9 w-full rounded-md border border-border bg-card px-3 text-sm';
const set = (key: SymbolKey, value: string) => emit('update:modelValue', { ...props.modelValue, [key]: value });
const input = (ev: Event) => (ev.target as HTMLInputElement | HTMLSelectElement).value;
const error = (key: string) => props.errors?.[`${props.prefix}.${key}`];

function load(ev: Event) {
    const select = ev.target as HTMLSelectElement;
    const template = props.templates?.find((t) => String(t.id) === select.value);
    if (template) emit('update:modelValue', emptySymbol(template.symbol));
    select.value = '';
}
</script>

<template>
    <fieldset class="space-y-3">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <legend class="text-sm font-medium">{{ trans('symbols.title') }}</legend>
            <select v-if="templates?.length" :class="[field, 'w-auto min-w-40']" :aria-label="trans('symbols.template.load')" @change="load">
                <option value="">{{ trans('symbols.template.load') }}</option>
                <option v-for="t in templates" :key="t.id" :value="t.id">{{ t.name }}</option>
            </select>
        </div>

        <div class="rounded-md bg-(--fill-1) p-3 text-center">
            <p class="mb-1 text-xs text-text-2">{{ trans('symbols.preview') }}</p>
            <TacticalSymbolPreview :symbol="modelValue" :name="name" />
        </div>

        <div v-for="key in SYMBOL_SELECTS" :key="key" class="space-y-1">
            <Label :for="`${prefix}-${key}`">{{ trans(`symbols.field.${key}`) }}</Label>
            <select :id="`${prefix}-${key}`" :value="modelValue[key]" :class="field" :aria-invalid="!!error(key)" @change="set(key, input($event))">
                <option value="">{{ trans('symbols.none') }}</option>
                <option v-for="o in symbolOptions[key]" :key="o.value" :value="o.value">{{ o.label }}</option>
            </select>
            <p v-if="error(key)" class="text-sm text-danger" role="alert">{{ error(key) }}</p>
        </div>

        <div v-for="key in SYMBOL_TEXTS" :key="key" class="space-y-1">
            <Label :for="`${prefix}-${key}`">{{ trans(`symbols.field.${key}`) }}</Label>
            <Input :id="`${prefix}-${key}`" :model-value="modelValue[key]" maxlength="100" :aria-invalid="!!error(key)" @update:model-value="set(key, String($event))" />
            <p class="text-xs text-text-2">{{ trans(`symbols.hint.${key}`) }}</p>
            <p v-if="error(key)" class="text-sm text-danger" role="alert">{{ error(key) }}</p>
        </div>

        <div v-if="name !== undefined" class="space-y-1">
            <Label :for="`${prefix}-name`">{{ trans('symbols.field.name') }}</Label>
            <Input :id="`${prefix}-name`" :model-value="name" maxlength="100" :aria-invalid="!!nameError" @update:model-value="emit('update:name', String($event))" />
            <p class="text-xs text-text-2">{{ trans('symbols.hint.name') }}</p>
            <p v-if="nameError" class="text-sm text-danger" role="alert">{{ nameError }}</p>
        </div>
    </fieldset>
</template>
