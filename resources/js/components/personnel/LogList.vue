<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import Pagination from '@/components/Pagination.vue';
import { Button } from '@/components/ui/button';

export type LogEntry = {
    id: number;
    type: number;
    content: string;
    author: string;
    created_at: string | null;
    can_delete: boolean;
};

const props = defineProps<{
    entries: { data: LogEntry[]; from: number | null; to: number | null; total: number; prev_page_url: string | null; next_page_url: string | null };
    empty: string;
    deleteUrl: string; // entry id is appended
}>();

const tone: Record<number, string> = { 1: 'text-ok', 2: 'text-danger' };
const when = (iso: string | null) => (iso ? new Date(iso).toLocaleString('de-DE', { dateStyle: 'medium', timeStyle: 'short' }) : '');

function remove(entry: LogEntry) {
    if (confirm(trans('personnel.profile.note_delete_confirm'))) {
        router.delete(`${props.deleteUrl}/${entry.id}`, { preserveScroll: true });
    }
}
</script>

<template>
    <div class="space-y-3">
        <p v-if="!entries.data.length" class="text-sm text-text-2">{{ empty }}</p>
        <ul v-else class="divide-y divide-border">
            <li v-for="e in entries.data" :key="e.id" class="flex items-start justify-between gap-3 py-2">
                <div class="min-w-0">
                    <p class="whitespace-pre-wrap break-words text-sm">{{ e.content }}</p>
                    <p class="text-xs text-text-2">
                        <span class="font-medium" :class="tone[e.type]">{{ trans(`personnel.note_type.${e.type}`) }}</span>
                        · {{ trans('personnel.profile.by', { author: e.author }) }} · {{ when(e.created_at) }}
                    </p>
                </div>
                <Button v-if="e.can_delete" variant="ghost" size="sm" :aria-label="trans('personnel.profile.note_delete')" @click="remove(e)">
                    {{ trans('personnel.delete') }}
                </Button>
            </li>
        </ul>
        <Pagination v-if="entries.total > entries.data.length || entries.prev_page_url" :from="entries.from" :to="entries.to" :total="entries.total" :prev-url="entries.prev_page_url" :next-url="entries.next_page_url" />
    </div>
</template>
