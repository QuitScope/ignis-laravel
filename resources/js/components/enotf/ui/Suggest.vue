<script setup lang="ts">
/**
 * Free text with a suggestion list instead of <datalist> (legacy Ev2Suggest). Suggestions from `items` (filtered
 * locally) or an async `load(q)`; picking one emits its value and `pick` with the whole item (e.g. a POI).
 * Keyboard: ArrowDown/ArrowUp move between the input and the options, Enter picks, Escape closes and returns
 * to the input. Attributes (e.g. `data-mark`) go to the input.
 */
defineOptions({ inheritAttrs: false });
import { computed, ref, useId, watch } from 'vue';
import { usePanel } from '@/lib/enotfUi';

export type SuggestItem = string | { value: string; label?: string; hint?: string; data?: unknown };

const props = defineProps<{ modelValue: string | null | undefined; items?: SuggestItem[]; load?: (q: string) => Promise<SuggestItem[]>; label: string; disabled?: boolean; maxlength?: number }>();
const emit = defineEmits<{ 'update:modelValue': [value: string | null]; pick: [item: SuggestItem] }>();
const id = useId();
const anchor = ref<HTMLInputElement | null>(null);
const panel = ref<HTMLElement | null>(null);
const { open, style, show, close } = usePanel(anchor, panel);
const text = ref(props.modelValue ?? '');
watch(
    () => props.modelValue,
    (v) => (text.value = v ?? ''),
);
const valueOf = (i: SuggestItem) => (typeof i === 'string' ? i : i.value);
const loaded = ref<SuggestItem[]>([]);
const list = computed(() => {
    if (props.load) return loaded.value;
    const q = text.value.trim().toLowerCase();
    return (props.items ?? []).filter((i) => valueOf(i).toLowerCase().includes(q) && valueOf(i) !== text.value).slice(0, 20);
});

let seq = 0;
let skipFocus = false;
const onFocus = () => {
    if (skipFocus) skipFocus = false;
    else void refresh();
};
const refresh = async () => {
    if (props.load) {
        const n = ++seq;
        const res = await props.load(text.value.trim()).catch(() => []);
        if (n === seq) loaded.value = res;
    }
    if (list.value.length) show();
    else close();
};
const input = () => {
    emit('update:modelValue', text.value === '' ? null : text.value);
    void refresh();
};
const pick = (i: SuggestItem) => {
    text.value = valueOf(i);
    emit('update:modelValue', text.value);
    emit('pick', i);
    close();
    skipFocus = true;
    anchor.value?.focus();
    skipFocus = false;
};
const options = () => Array.from(panel.value?.querySelectorAll<HTMLElement>('[role="option"]') ?? []);
const move = (e: KeyboardEvent, n: number) => {
    if (e.key === 'Escape') {
        close();
        if (n >= 0) {
            skipFocus = true;
            anchor.value?.focus();
            skipFocus = false;
        }
        return;
    }
    if (e.key !== 'ArrowDown' && e.key !== 'ArrowUp') return;
    const list = options();
    if (!open.value || !list.length) return;
    e.preventDefault();
    const next = n + (e.key === 'ArrowDown' ? 1 : -1);
    if (next < 0) {
        skipFocus = true;
        anchor.value?.focus();
        skipFocus = false;
    } else list[Math.min(next, list.length - 1)]?.focus();
};
</script>

<template>
    <input
        ref="anchor"
        v-model="text"
        type="text"
        autocomplete="off"
        class="enotf-input"
        role="combobox"
        aria-autocomplete="list"
        :aria-expanded="open"
        :aria-controls="open ? id : undefined"
        :aria-label="label"
        :maxlength="maxlength ?? 255"
        :disabled="disabled"
        v-bind="$attrs"
        @input="input"
        @focus="onFocus"
        @keydown="move($event, -1)"
    />
    <Teleport to="body">
        <div v-if="open" :id="id" ref="panel" role="listbox" :aria-label="label" class="enotf enotf-panel" :style="style">
            <button v-for="(i, n) in list" :key="n" type="button" role="option" class="enotf-panel__item" :aria-selected="false" @click="pick(i)" @keydown="move($event, n)">
                <span class="block">{{ typeof i === 'string' ? i : (i.label ?? i.value) }}</span>
                <small v-if="typeof i !== 'string' && i.hint" class="block text-(--enotf-label)">{{ i.hint }}</small>
            </button>
        </div>
    </Teleport>
</template>
