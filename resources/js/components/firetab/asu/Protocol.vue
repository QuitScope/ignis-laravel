<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import type { AsuEventRow, AsuTruppRow } from '@/lib/asuClient';

/** All events, newest first. Voided events stay in the list (struck through and marked in words). */
const props = defineProps<{ events: AsuEventRow[]; trupps: AsuTruppRow[]; writable: boolean }>();
const emit = defineEmits<{ void: [id: number] }>();

const sure = ref<number | null>(null);
const time = (ms: number) => new Date(ms).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
const truppName = (id: number) => props.trupps.find((t) => t.id === id)?.name ?? `#${id}`;
const person = (e: AsuEventRow) => props.trupps.find((t) => t.id === e.trupp_id)?.members.find((m) => m.id === e.member_id)?.name ?? '';
const note = (e: AsuEventRow) => {
    if (e.type !== 'mayday' || !e.note) return '';
    try {
        const n = JSON.parse(e.note) as Record<string, string>;
        return [n.member, n.standort, n.lage].filter(Boolean).join(' · ');
    } catch {
        return e.note;
    }
};
const confirmVoid = (id: number) => {
    if (sure.value !== id) {
        sure.value = id;
        return;
    }
    sure.value = null;
    emit('void', id);
};
</script>

<template>
    <section class="space-y-2">
        <h3 class="text-lg font-semibold">{{ trans('firetab.asu.protocol') }}</h3>
        <ul v-if="events.length" class="divide-y divide-border rounded-md border border-border">
            <li v-for="e in events" :key="e.client_uuid ?? e.id" class="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2">
                <time class="font-mono text-sm text-text-2">{{ time(e.at) }}</time>
                <span :class="{ 'line-through opacity-70': e.voided }">
                    <b>{{ truppName(e.trupp_id) }}</b> · {{ trans(`firetab.asu.event.${e.type}`) }}<template v-if="person(e)"> · {{ person(e) }}</template
                    ><template v-if="e.bar != null"> · {{ e.bar }} bar</template><template v-if="note(e)"> · {{ note(e) }}</template>
                </span>
                <span v-if="e.voided" class="rounded border border-border px-2 text-sm font-semibold">{{ trans('firetab.asu.voided') }}</span>
                <span v-else-if="e.pending" class="text-sm text-text-2">{{ trans('firetab.asu.sending') }}</span>
                <Button
                    v-if="writable && !e.voided && !e.pending"
                    type="button"
                    size="sm"
                    :variant="sure === e.id ? 'destructive' : 'ghost'"
                    class="ml-auto min-h-11"
                    @click="confirmVoid(e.id)"
                    @blur="sure = null"
                >
                    {{ sure === e.id ? trans('firetab.asu.void_confirm') : trans('firetab.asu.void') }}
                </Button>
            </li>
        </ul>
        <p v-else class="text-text-2">{{ trans('firetab.asu.protocol_empty') }}</p>
    </section>
</template>
