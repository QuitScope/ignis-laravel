<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';

// Takes a Laravel length-aware paginator; the URLs already carry the query string.
defineProps<{
    from: number | null;
    to: number | null;
    total: number;
    prevUrl: string | null;
    nextUrl: string | null;
}>();

const btn = 'rounded-md border border-border px-3 py-1 text-sm hover:bg-(--fill-3)';
const off = 'pointer-events-none opacity-50';
</script>

<template>
    <nav class="flex items-center justify-between gap-3 text-sm text-text-2" aria-label="Pagination">
        <span class="tabular-nums">{{ trans('pagination.summary', { from: String(from ?? 0), to: String(to ?? 0), total: String(total) }) }}</span>
        <div class="flex gap-2">
            <Link v-if="prevUrl" :href="prevUrl" preserve-scroll :class="btn">{{ trans('pagination.prev') }}</Link>
            <span v-else :class="[btn, off]">{{ trans('pagination.prev') }}</span>
            <Link v-if="nextUrl" :href="nextUrl" preserve-scroll :class="btn">{{ trans('pagination.next') }}</Link>
            <span v-else :class="[btn, off]">{{ trans('pagination.next') }}</span>
        </div>
    </nav>
</template>
