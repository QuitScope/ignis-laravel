<script setup lang="ts">
import { ref, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import Pagination from '@/components/Pagination.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { kbLayout } from '@/components/knowledgebase/kbLayout';

defineOptions({ layout: kbLayout });

type Chip = { label: string; color: string; text: string };
type Entry = {
    id: number;
    type: string;
    title: string;
    subtitle: string | null;
    competency: Chip | null;
    category: string | null;
    tags: { id: number; name: string; color: string }[];
    is_pinned: boolean;
    is_archived: boolean;
};
type Filters = { type: string; category: number; tag: number; q: string; archived: boolean };

const props = defineProps<{
    entries: { data: Entry[]; from: number | null; to: number | null; total: number; prev_page_url: string | null; next_page_url: string | null };
    filters: Filters;
    types: string[];
    categories: { id: number; name: string; depth: number }[];
    tags: { id: number; name: string; color: string }[];
    can: { edit: boolean; archive: boolean };
    noindex: boolean;
}>();

const q = ref(props.filters.q);
const type = ref(props.filters.type);
const category = ref(props.filters.category === 0 ? '' : String(props.filters.category));
const tag = ref(props.filters.tag === 0 ? '' : String(props.filters.tag));
const archived = ref(props.filters.archived);

function load() {
    const all = { q: q.value.trim(), type: type.value, category: category.value, tag: tag.value, archived: archived.value ? '1' : '' };
    router.get('/lexicon', Object.fromEntries(Object.entries(all).filter(([, v]) => v !== '')), { preserveState: true, preserveScroll: true, replace: true });
}
let timer: ReturnType<typeof setTimeout> | undefined;
watch([q, type, category, tag, archived], (now, old) => {
    clearTimeout(timer);
    if (now[0] !== old[0] && now.slice(1).every((v, i) => v === old[i + 1])) timer = setTimeout(load, 300);
    else load();
});
function reset() {
    q.value = type.value = category.value = tag.value = '';
    archived.value = false;
}

const select = 'h-9 rounded-md border border-border bg-card px-3 text-sm';
const chip = 'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium';
</script>

<template>
    <div class="mx-auto w-full max-w-5xl space-y-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <h1 class="text-2xl font-semibold">{{ trans('knowledgebase.nav') }}</h1>
            <div class="flex flex-wrap gap-2">
                <Button v-if="can.edit" as-child variant="outline"><Link href="/lexicon/taxonomy">{{ trans('knowledgebase.taxonomy_link') }}</Link></Button>
                <Button v-if="can.edit" as-child><Link href="/lexicon/create">{{ trans('knowledgebase.new') }}</Link></Button>
                <Button v-if="noindex" as-child variant="outline"><Link href="/login">{{ trans('knowledgebase.login') }}</Link></Button>
            </div>
        </div>

        <form class="flex flex-wrap items-end gap-3" role="search" @submit.prevent="load">
            <Input v-model="q" type="search" class="w-64" :aria-label="trans('knowledgebase.filter.search')" :placeholder="trans('knowledgebase.filter.search')" />
            <select v-model="type" :class="select" :aria-label="trans('knowledgebase.filter.type')">
                <option value="">{{ trans('knowledgebase.filter.type') }}: {{ trans('knowledgebase.filter.all') }}</option>
                <option v-for="t in types" :key="t" :value="t">{{ trans(`knowledgebase.type.${t}`) }}</option>
            </select>
            <select v-model="category" :class="select" :aria-label="trans('knowledgebase.filter.category')">
                <option value="">{{ trans('knowledgebase.filter.category') }}: {{ trans('knowledgebase.filter.all') }}</option>
                <option v-for="c in categories" :key="c.id" :value="String(c.id)">{{ ' '.repeat(c.depth) }}{{ c.name }}</option>
            </select>
            <select v-model="tag" :class="select" :aria-label="trans('knowledgebase.filter.tag')">
                <option value="">{{ trans('knowledgebase.filter.tag') }}: {{ trans('knowledgebase.filter.all') }}</option>
                <option v-for="t in tags" :key="t.id" :value="String(t.id)">{{ t.name }}</option>
            </select>
            <label v-if="can.archive" class="flex items-center gap-2 text-sm text-text-2"><input v-model="archived" type="checkbox" />{{ trans('knowledgebase.filter.archived') }}</label>
            <Button type="button" variant="ghost" size="sm" @click="reset">{{ trans('knowledgebase.filter.reset') }}</Button>
        </form>

        <ul class="space-y-2">
            <li v-for="e in entries.data" :key="e.id" class="rounded-lg border border-border bg-card p-4 shadow-(--shadow)">
                <div class="flex flex-wrap items-center gap-2">
                    <Link :href="`/lexicon/${e.id}`" class="text-lg font-semibold text-accent hover:underline">{{ e.title }}</Link>
                    <span v-if="e.is_pinned" :class="[chip, 'bg-(--accent-soft) text-accent']">{{ trans('knowledgebase.pinned') }}</span>
                    <span v-if="e.is_archived" :class="[chip, 'bg-(--warn-soft) text-warn']">{{ trans('knowledgebase.archived') }}</span>
                    <span :class="[chip, 'bg-(--fill-3) text-text-2']">{{ trans(`knowledgebase.type.${e.type}`) }}</span>
                    <span v-if="e.competency" :class="chip" :style="{ backgroundColor: e.competency.color, color: e.competency.text }">{{ e.competency.label }}</span>
                </div>
                <p v-if="e.subtitle" class="mt-1 text-sm text-text-2">{{ e.subtitle }}</p>
                <div v-if="e.category || e.tags.length" class="mt-2 flex flex-wrap items-center gap-2 text-xs text-text-2">
                    <span v-if="e.category">{{ e.category }}</span>
                    <span v-for="t in e.tags" :key="t.id" :class="[chip, 'text-white']" :style="{ backgroundColor: t.color }">{{ t.name }}</span>
                </div>
            </li>
            <li v-if="entries.data.length === 0" class="rounded-lg border border-border bg-card p-6 text-center text-text-2">{{ trans('knowledgebase.empty') }}</li>
        </ul>

        <Pagination :from="entries.from" :to="entries.to" :total="entries.total" :prev-url="entries.prev_page_url" :next-url="entries.next_page_url" />
    </div>
</template>
