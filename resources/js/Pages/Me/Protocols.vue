<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';

defineProps<{
    sources: { key: string; label: string }[];
    groups: { key: string; label: string; rows: { title: string; sub: string | null; href: string }[] }[];
    source: string;
}>();
</script>

<template>
    <div class="space-y-4">
        <h1 class="text-2xl font-semibold">{{ trans('protocols.mine_title') }}</h1>
        <nav v-if="sources.length > 1" class="flex flex-wrap gap-2" aria-label="tabs">
            <Link href="/me/protocols" class="rounded-md border px-3 py-1 text-sm" :class="source === '' ? 'border-accent text-accent' : 'border-border text-text-2'">{{
                trans('protocols.all')
            }}</Link>
            <Link
                v-for="s in sources"
                :key="s.key"
                :href="`/me/protocols?source=${s.key}`"
                class="rounded-md border px-3 py-1 text-sm"
                :class="source === s.key ? 'border-accent text-accent' : 'border-border text-text-2'"
                >{{ trans(s.label) }}</Link
            >
        </nav>
        <p v-if="!groups.length" class="text-sm text-text-2">{{ trans('protocols.none') }}</p>
        <section v-for="g in groups" :key="g.key" class="space-y-2">
            <h2 class="text-lg font-medium">{{ trans(g.label) }}</h2>
            <ul v-if="g.rows.length" class="divide-y divide-border rounded-lg border border-border bg-card shadow-(--shadow)">
                <li v-for="r in g.rows" :key="r.href" class="flex flex-wrap items-baseline gap-x-3 px-4 py-2 text-sm">
                    <Link :href="r.href" class="font-medium hover:underline">{{ r.title }}</Link>
                    <span v-if="r.sub" class="text-text-2">{{ r.sub }}</span>
                </li>
            </ul>
            <p v-else class="text-sm text-text-2">{{ trans('protocols.empty') }}</p>
        </section>
    </div>
</template>
