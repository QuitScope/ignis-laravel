<script setup lang="ts">
import { computed } from 'vue';
import { trans } from 'laravel-vue-i18n';
import { CircleCheck, ListChecks } from '@lucide/vue';
import type { OpenItem } from './createEditor';

const props = defineProps<{ items: OpenItem[]; missingReasons: Record<string, string> }>();
const emit = defineEmits<{ reveal: [item: OpenItem] }>();

const entries = computed(() => {
    const out: { items: OpenItem[]; why: string }[] = [];
    const byReason = new Map<string, { items: OpenItem[]; why: string }>();
    for (const item of props.items) {
        const reason = item.kind === 'variable' && item.state === 'missing' ? props.missingReasons[item.name] : undefined;
        if (reason && byReason.has(reason)) {
            byReason.get(reason)!.items.push(item);
            continue;
        }
        const entry = {
            items: [item],
            why: reason ?? (item.kind === 'field'
                ? (item.section ? trans('documents.editor.open.in_section', { section: item.section }) : '')
                : (item.state === 'unknown' ? trans('documents.editor.open.unknown_why') : '')),
        };
        if (reason) byReason.set(reason, entry);
        out.push(entry);
    }
    return out;
});

const name = (i: OpenItem) => (i.kind === 'variable' && i.state === 'unknown' ? `{{${i.name}}}` : i.label);
const text = (e: { items: OpenItem[] }) => {
    const first = e.items[0];
    if (e.items.length > 1) return trans('documents.editor.open.missing_many', { names: e.items.map(name).join(', ') });
    if (first.kind === 'field') return trans('documents.editor.open.field', { label: first.label });
    return first.state === 'unknown' ? trans('documents.editor.open.unknown', { name: name(first) }) : trans('documents.editor.open.missing', { label: first.label });
};
</script>

<template>
    <section class="rounded-lg border border-border bg-card p-3 text-sm" aria-live="polite" :aria-label="trans('documents.editor.open.title')">
        <p class="flex items-center gap-2 font-medium">
            <CircleCheck v-if="!items.length" class="size-4 text-ok" aria-hidden="true" />
            <ListChecks v-else class="size-4 text-warn" aria-hidden="true" />
            {{ items.length ? trans('documents.editor.open.title') : trans('documents.editor.open.done') }}
        </p>
        <ul v-if="items.length" class="mt-2 space-y-1">
            <li v-for="(e, i) in entries" :key="i">
                <button type="button" class="w-full rounded-md px-2 py-1 text-left hover:bg-(--fill-3) focus-visible:outline-2 focus-visible:outline-accent" @click="emit('reveal', e.items[0])">
                    <span :class="e.items[0].kind === 'field' ? 'text-danger' : 'text-warn'">{{ text(e) }}</span>
                    <span v-if="e.why" class="block text-xs text-text-2">{{ e.why }}</span>
                </button>
            </li>
        </ul>
    </section>
</template>
