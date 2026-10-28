<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue';
import { trans } from 'laravel-vue-i18n';
import { X } from '@lucide/vue';
import { addAddresses, debounce, mailRequest, parseAddresses, type AddressEntry } from '@/lib/mail';

/**
 * Several addresses as chips with the address book as combobox suggestions (GET /mail/addressbook?q=, 20 mailboxes and
 * 20 lists). Enter or a click takes the active suggestion, else the typed text; comma, semicolon and leaving the field
 * take the typed text; Backspace on an empty field removes the last chip. Labels are text only.
 */
const props = defineProps<{ id: string; label: string; known: Map<string, AddressEntry> }>();
const model = defineModel<string[]>({ required: true });

const text = ref('');
const results = ref<AddressEntry[]>([]);
const active = ref(-1);
const open = computed(() => results.value.length > 0);
const listId = computed(() => `${props.id}-list`);

const kind = (e: AddressEntry) => (e.kind === 'personal' ? '' : ` · ${trans(`mail.kind.${e.kind}`)}`);
const chip = (address: string) => {
    const e = props.known.get(address.toLowerCase());
    return e ? `${e.name} <${e.address}>${kind(e)}` : address;
};

const lookup = debounce(async (q: string) => {
    const res = await mailRequest<{ results: AddressEntry[] }>('GET', `/mail/addressbook?q=${encodeURIComponent(q)}`);
    if (q !== text.value.trim()) return;
    results.value = (res.data.results ?? []).filter((e) => !model.value.some((a) => a.toLowerCase() === e.address));
    results.value.forEach((e) => props.known.set(e.address, e));
    active.value = -1;
}, 200);
onBeforeUnmount(() => lookup.cancel());

function input() {
    const q = text.value.trim();
    if (/[,;]/.test(text.value)) return commit();
    if (q === '') {
        results.value = [];
        return;
    }
    lookup(q);
}
function commit(address?: string) {
    const added = address ? [address] : parseAddresses(text.value);
    if (added.length > 0) model.value = addAddresses(model.value, added);
    text.value = '';
    results.value = [];
    active.value = -1;
}
function remove(i: number) {
    model.value = model.value.filter((_, j) => j !== i);
}
function key(e: KeyboardEvent) {
    if (e.key === 'ArrowDown' && open.value) {
        e.preventDefault();
        active.value = Math.min(active.value + 1, results.value.length - 1);
    } else if (e.key === 'ArrowUp' && open.value) {
        e.preventDefault();
        active.value = Math.max(active.value - 1, 0);
    } else if (e.key === 'Enter') {
        e.preventDefault();
        commit(results.value[active.value]?.address);
    } else if (e.key === 'Escape' && open.value) {
        e.preventDefault();
        results.value = [];
    } else if (e.key === 'Backspace' && text.value === '' && model.value.length > 0) {
        remove(model.value.length - 1);
    }
}
</script>

<template>
    <div class="relative">
        <div class="flex min-h-9 flex-wrap items-center gap-1 rounded-md border border-border bg-transparent px-2 py-1 focus-within:ring-2 focus-within:ring-(--accent)">
            <span v-for="(a, i) in model" :key="a" class="inline-flex items-center gap-1 rounded-full bg-(--fill-3) px-2 py-0.5 text-xs">
                {{ chip(a) }}
                <button type="button" class="rounded-full hover:bg-(--fill-2)" :aria-label="trans('mail.compose.remove', { address: a })" @click="remove(i)">
                    <X class="size-3" aria-hidden="true" />
                </button>
            </span>
            <input
                :id="id"
                v-model="text"
                type="text"
                role="combobox"
                autocomplete="off"
                class="h-7 min-w-32 flex-1 bg-transparent text-sm outline-none"
                :aria-label="props.label"
                :aria-expanded="open"
                :aria-controls="listId"
                aria-autocomplete="list"
                :aria-activedescendant="active >= 0 ? `${listId}-${active}` : undefined"
                @input="input"
                @keydown="key"
                @blur="commit()"
            />
        </div>
        <ul v-show="open" :id="listId" role="listbox" class="absolute z-20 mt-1 max-h-64 w-full overflow-auto rounded-md border border-border bg-card py-1 text-sm shadow-(--shadow)">
            <li
                v-for="(e, i) in results"
                :id="`${listId}-${i}`"
                :key="e.address"
                role="option"
                :aria-selected="i === active"
                class="cursor-pointer px-3 py-1.5"
                :class="{ 'bg-(--fill-3)': i === active }"
                @mousedown.prevent="commit(e.address)"
            >
                {{ e.name }} &lt;{{ e.address }}&gt;<span class="text-text-2">{{ kind(e) }}</span>
            </li>
        </ul>
    </div>
</template>
