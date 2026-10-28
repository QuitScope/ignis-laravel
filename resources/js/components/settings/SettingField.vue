<script setup lang="ts">
import { computed, ref } from 'vue';
import { trans } from 'laravel-vue-i18n';
import { colorWarnings, DARK_BACKGROUND } from '@/lib/color';
import { linesToList, type ConfigField, type FieldValue } from '@/lib/settingFields';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

// One registry setting as a form control, by type. Label and hint are `settings.<key>.label|hint`.
const props = defineProps<{ field: ConfigField; modelValue: FieldValue; error?: string }>();
const emit = defineEmits<{ 'update:modelValue': [value: FieldValue] }>();

const id = computed(() => 'setting-' + props.field.key.replace(/\./g, '-'));
const describedby = computed(() => (props.error ? `${id.value}-hint ${id.value}-error` : `${id.value}-hint`));
const text = computed(() => String(props.modelValue));
const listText = ref(Array.isArray(props.modelValue) ? props.modelValue.join('\n') : '');
const warnings = computed(() => (props.field.type === 'color' ? colorWarnings(text.value) : []));
const validColor = computed(() => /^#[0-9a-f]{6}$/i.test(text.value));
const select = 'h-9 w-full rounded-md border border-border bg-card px-3 text-sm';

function onList(e: Event) {
    listText.value = (e.target as HTMLTextAreaElement).value;
    emit('update:modelValue', linesToList(listText.value));
}

function onNumber(v: string | number) {
    if (v === '') return emit('update:modelValue', '');
    const n = Number(v);
    if (!Number.isNaN(n)) emit('update:modelValue', n);
}

function optionLabel(o: string): string {
    const key = `settings.${props.field.key}.option.${o}`;
    const label = trans(key);

    return label === key ? o : label;
}
</script>

<template>
    <div class="space-y-1">
        <div v-if="field.type === 'bool'" class="flex items-center gap-2">
            <Checkbox :id="id" :model-value="modelValue === true" :aria-describedby="describedby" :aria-invalid="!!error" @update:model-value="(v) => emit('update:modelValue', v === true)" />
            <Label :for="id" class="font-normal">{{ trans(`settings.${field.key}.label`) }}</Label>
        </div>
        <template v-else>
            <Label :for="field.deletable_only ? undefined : id">{{ trans(`settings.${field.key}.label`) }}</Label>

            <div v-if="field.deletable_only" class="flex items-center gap-3 text-sm">
                <code class="rounded bg-(--fill-2) px-2 py-1">{{ field.value || trans('settings.empty') }}</code>
                <div v-if="field.value" class="flex items-center gap-2">
                    <Checkbox :id="id" :model-value="modelValue === true" @update:model-value="(v) => emit('update:modelValue', v === true)" />
                    <Label :for="id" class="font-normal">{{ trans('settings.clear') }}</Label>
                </div>
            </div>

            <Input
                v-else-if="field.secret"
                :id="id"
                type="password"
                autocomplete="new-password"
                :model-value="text"
                :placeholder="trans(field.is_set ? 'settings.secret.set' : 'settings.secret.unset')"
                :aria-describedby="describedby"
                :aria-invalid="!!error"
                @update:model-value="(v) => emit('update:modelValue', String(v))"
            />

            <select v-else-if="field.type === 'enum'" :id="id" :value="text" :class="select" :aria-describedby="describedby" :aria-invalid="!!error" @change="emit('update:modelValue', ($event.target as HTMLSelectElement).value)">
                <option v-for="o in field.options" :key="o" :value="o">{{ optionLabel(o) }}</option>
            </select>

            <div v-else-if="field.type === 'color'" class="space-y-2">
                <div class="flex items-center gap-2">
                    <input
                        type="color"
                        class="h-9 w-12 cursor-pointer rounded-md border border-border bg-card p-1"
                        :value="validColor ? text : DARK_BACKGROUND"
                        :aria-label="trans(`settings.${field.key}.label`)"
                        @input="emit('update:modelValue', ($event.target as HTMLInputElement).value)"
                    />
                    <Input :id="id" class="max-w-32 font-mono" :model-value="text" maxlength="7" :aria-describedby="describedby" :aria-invalid="!!error" @update:model-value="(v) => emit('update:modelValue', String(v))" />
                    <span class="h-9 w-24 rounded-md border border-border" :style="{ backgroundColor: validColor ? text : 'transparent' }" role="img" :aria-label="trans('settings.color.preview')" />
                </div>
                <p v-for="w in warnings" :key="w" class="rounded-md bg-(--warn-soft) px-3 py-2 text-sm" role="status">{{ trans('settings.color.warn.' + w) }}</p>
            </div>

            <textarea
                v-else-if="field.type === 'list'"
                :id="id"
                rows="4"
                :value="listText"
                :class="[select, 'h-auto py-2']"
                :aria-describedby="describedby"
                :aria-invalid="!!error"
                @input="onList"
            />

            <Input
                v-else
                :id="id"
                :type="field.type === 'int' ? 'number' : field.type === 'url' ? 'url' : 'text'"
                :model-value="text"
                :aria-describedby="describedby"
                :aria-invalid="!!error"
                @update:model-value="(v) => (field.type === 'int' ? onNumber(v) : emit('update:modelValue', String(v)))"
            />
        </template>

        <p :id="id + '-hint'" class="text-sm text-text-2">{{ trans(`settings.${field.key}.hint`) }}</p>
        <p v-if="error" :id="id + '-error'" class="text-sm text-danger" role="alert">{{ error }}</p>
    </div>
</template>
