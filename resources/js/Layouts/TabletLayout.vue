<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { useDuiKeys } from '@/lib/duiKeys';
import { useFiveMIdentify } from '@/lib/fivemIdentify';

// Vehicle tablet (FiveM CEF, Chromium 103): no main navigation, touch targets at least 44 px (min-h-11) in the pages.
const page = usePage<{ app: { name: string } }>();
useDuiKeys();
useFiveMIdentify();
</script>

<template>
    <div class="flex min-h-screen flex-col bg-bg text-text">
        <header class="flex min-h-11 items-center border-b border-border px-4">
            <span class="text-sm font-semibold text-text-2">{{ page.props.app.name }}</span>
        </header>
        <main class="w-full flex-1 p-4"><slot /></main>
    </div>
</template>
