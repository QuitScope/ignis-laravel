<script setup lang="ts">
/**
 * Selection list without the native popup (legacy ev2-select.js): a button in field look, the panel teleported to
 * body (position:fixed), a search field from 8 options, typing a letter jumps, tap outside or Escape closes.
 * With `field` it writes the catalog code through enotfForm.set and shows its 422/conflict mark; without, it is a
 * v-model (focus views). Attributes (e.g. `data-mark`) go to the button.
 */
defineOptions({ inheritAttrs: false });
import { ChevronDown } from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';
import { computed, nextTick, ref, useId } from 'vue';
import { type Code, fieldMark, useEnotfForm, usePanel } from '@/lib/enotfUi';

const props = defineProps<{ options: [Code, string][]; field?: string; modelValue?: Code | null; label: string; placeholder?: string; disabled?: boolean }>();
const emit = defineEmits<{ 'update:modelValue': [value: Code | null] }>();
const form = useEnotfForm();
const id = useId();
const anchor = ref<HTMLElement | null>(null);
const panel = ref<HTMLElement | null>(null);
const { open, style, show, close } = usePanel(anchor, panel);
const query = ref('');

const value = computed(() => (props.field ? (form.values[props.field] as Code | null | undefined) : props.modelValue) ?? null);
const current = computed(() => props.options.find(([c]) => String(c) === String(value.value)));
const locked = computed(() => props.disabled || (!!props.field && form.readonly));
const shown = computed(() => {
    const q = query.value.trim().toLowerCase();
    return q ? props.options.filter(([, l]) => l.toLowerCase().includes(q)) : props.options;
});

const toggle = async () => {
    if (open.value) return close();
    if (locked.value) return;
    query.value = '';
    show();
    await nextTick();
    (panel.value?.querySelector('input, [aria-selected="true"], button') as HTMLElement | null)?.focus();
};
const pick = (code: Code | null) => {
    if (props.field) form.set(props.field, code);
    emit('update:modelValue', code);
    close();
    anchor.value?.focus();
};
const jump = (e: KeyboardEvent) => {
    if (e.key === 'Escape') {
        close();
        anchor.value?.focus();
        return;
    }
    if (props.options.length >= 8 || e.key.length !== 1) return;
    const i = props.options.findIndex(([, l]) => l.toLowerCase().startsWith(e.key.toLowerCase()));
    if (i >= 0) (panel.value?.querySelectorAll('[role="option"]')[i + 1] as HTMLElement | undefined)?.focus();
};
</script>

<template>
    <button
        ref="anchor"
        type="button"
        class="enotf-input flex items-center justify-between gap-2"
        aria-haspopup="listbox"
        :aria-expanded="open"
        :aria-controls="open ? id : undefined"
        :aria-label="`${label}: ${current?.[1] ?? placeholder ?? trans('enotf.protocol.none')}`"
        :disabled="locked"
        :data-mark="field ? fieldMark(form, field) : null"
        v-bind="$attrs"
        @click="toggle"
    >
        <span :class="current ? '' : 'text-(--enotf-label)'">{{ current?.[1] ?? placeholder ?? trans('enotf.protocol.none') }}</span>
        <ChevronDown class="size-4 shrink-0" aria-hidden="true" />
    </button>
    <Teleport to="body">
        <div v-if="open" :id="id" ref="panel" class="enotf enotf-panel" :style="style" @keydown="jump">
            <input
                v-if="options.length >= 8"
                v-model="query"
                type="text"
                autocomplete="off"
                class="enotf-input"
                :placeholder="trans('enotf.ui.search')"
                :aria-label="`${label} ${trans('enotf.ui.search')}`"
            />
            <div role="listbox" :aria-label="label">
                <button type="button" role="option" class="enotf-panel__item text-(--enotf-label)" :aria-selected="value === null" @click="pick(null)">
                    {{ trans('enotf.protocol.none') }}
                </button>
                <button
                    v-for="[code, text] in shown"
                    :key="code"
                    type="button"
                    role="option"
                    class="enotf-panel__item"
                    :aria-selected="String(code) === String(value)"
                    @click="pick(code)"
                >
                    {{ text }}
                </button>
                <p v-if="shown.length === 0" class="px-3 py-2 text-sm text-(--enotf-label)">{{ trans('enotf.ui.no_match') }}</p>
            </div>
        </div>
    </Teleport>
</template>
