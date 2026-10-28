<script setup lang="ts">
/** Header of the clinic portal pages (clinic device, no account): board, availability, sign out (POST). */
import { router } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';

defineProps<{ hospital: string; current: 'board' | 'availability' }>();
const links = [
    { key: 'board', href: '/enotf/schnittstelle', label: 'enotf.portal.board' },
    { key: 'availability', href: '/enotf/schnittstelle/availability', label: 'enotf.portal.availability' },
] as const;
</script>

<template>
    <header class="flex flex-wrap items-center justify-between gap-3 border-b border-border pb-3">
        <h1 class="text-2xl font-semibold">{{ hospital }}</h1>
        <nav class="flex flex-wrap items-center gap-2" :aria-label="trans('enotf.portal.title')">
            <a
                v-for="l in links"
                :key="l.key"
                :href="l.href"
                :aria-current="l.key === current ? 'page' : undefined"
                :class="['inline-flex min-h-11 items-center rounded-md border px-3', l.key === current ? 'border-accent bg-accent text-white' : 'border-border']"
                >{{ trans(l.label) }}</a
            >
            <button type="button" class="min-h-11 rounded-md border border-border px-3" @click="router.post('/enotf/schnittstelle/logout')">{{ trans('enotf.portal.logout') }}</button>
        </nav>
    </header>
</template>
