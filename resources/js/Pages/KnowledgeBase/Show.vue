<script setup lang="ts">
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { Button } from '@/components/ui/button';
import KbProse from '@/components/knowledgebase/KbProse.vue';
import { kbLayout } from '@/components/knowledgebase/kbLayout';

defineOptions({ layout: kbLayout });

type Chip = { label: string; color: string; text: string; desc: string };

const props = defineProps<{
    entry: {
        id: number;
        type: string;
        title: string;
        subtitle: string | null;
        competency: Chip | null;
        active_ingredient: string | null;
        drug_class: string | null;
        is_pinned: boolean;
        is_archived: boolean;
        hide_editor: boolean | null;
        updated_at: string | null;
        creator: string | null;
        editor: string | null;
    };
    // Server-rendered by KbContentRenderer::html(); shown through KbProse only.
    contentHtml: string;
    sectionsHtml: { key: string; html: string }[];
    categoryPath: { id: number; name: string }[];
    tags: { id: number; name: string; color: string }[];
    related: { id: number; title: string; type: string }[];
    can: { edit: boolean; archive: boolean; hideEditor: boolean } | null;
    noindex: boolean;
}>();

const busy = ref(false);
const patch = (action: string, value: boolean) =>
    router.patch(`/lexicon/${props.entry.id}/${action}`, { value, return: 'show' }, { preserveScroll: true, onStart: () => (busy.value = true), onFinish: () => (busy.value = false) });
const date = (iso: string | null) => (iso ? new Date(iso).toLocaleDateString() : '');
const chip = 'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium';
</script>

<template>
    <article class="mx-auto w-full max-w-4xl space-y-4">
        <nav class="flex flex-wrap items-center gap-2 text-sm text-text-2" aria-label="breadcrumb">
            <Link href="/lexicon" class="hover:underline">{{ trans('knowledgebase.back') }}</Link>
            <template v-for="c in categoryPath" :key="c.id">
                <span aria-hidden="true">/</span>
                <Link :href="`/lexicon?category=${c.id}`" class="hover:underline">{{ c.name }}</Link>
            </template>
        </nav>

        <header class="space-y-2">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <h1 class="text-2xl font-semibold">{{ entry.title }}</h1>
                <div v-if="can" class="flex flex-wrap gap-2">
                    <Button v-if="can.edit" as-child size="sm"><Link :href="`/lexicon/${entry.id}/edit`">{{ trans('knowledgebase.edit') }}</Link></Button>
                    <Button v-if="can.edit" size="sm" variant="outline" :disabled="busy" @click="patch('pin', !entry.is_pinned)">{{ trans(entry.is_pinned ? 'knowledgebase.unpin' : 'knowledgebase.pin') }}</Button>
                    <Button v-if="can.archive" size="sm" variant="outline" :disabled="busy" @click="patch('archive', !entry.is_archived)">{{ trans(entry.is_archived ? 'knowledgebase.restore' : 'knowledgebase.archive') }}</Button>
                    <Button v-if="can.hideEditor" size="sm" variant="outline" :disabled="busy" @click="patch('hide-editor', !entry.hide_editor)">{{ trans(entry.hide_editor ? 'knowledgebase.show_editor' : 'knowledgebase.hide_editor') }}</Button>
                </div>
            </div>
            <p v-if="entry.subtitle" class="text-text-2">{{ entry.subtitle }}</p>
            <div class="flex flex-wrap items-center gap-2">
                <span :class="[chip, 'bg-(--fill-3) text-text-2']">{{ trans(`knowledgebase.type.${entry.type}`) }}</span>
                <span v-if="entry.is_pinned" :class="[chip, 'bg-(--accent-soft) text-accent']">{{ trans('knowledgebase.pinned') }}</span>
                <span v-if="entry.is_archived" :class="[chip, 'bg-(--warn-soft) text-warn']">{{ trans('knowledgebase.archived') }}</span>
                <span v-if="entry.competency" :class="chip" :style="{ backgroundColor: entry.competency.color, color: entry.competency.text }" :title="entry.competency.desc">{{ entry.competency.label }}</span>
                <Link v-for="t in tags" :key="t.id" :href="`/lexicon?tag=${t.id}`" :class="[chip, 'text-white']" :style="{ backgroundColor: t.color }">{{ t.name }}</Link>
            </div>
            <dl v-if="entry.active_ingredient || entry.drug_class" class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-sm">
                <template v-if="entry.active_ingredient">
                    <dt class="text-text-2">{{ trans('knowledgebase.active_ingredient') }}</dt>
                    <dd>{{ entry.active_ingredient }}</dd>
                </template>
                <template v-if="entry.drug_class">
                    <dt class="text-text-2">{{ trans('knowledgebase.drug_class') }}</dt>
                    <dd>{{ entry.drug_class }}</dd>
                </template>
            </dl>
        </header>

        <section v-if="contentHtml" class="rounded-lg border border-border bg-card p-4 shadow-(--shadow)">
            <KbProse :html="contentHtml" />
        </section>
        <section v-for="s in sectionsHtml" :key="s.key" class="rounded-lg border border-border bg-card p-4 shadow-(--shadow)">
            <h2 class="mb-2 text-lg font-semibold">{{ trans(`knowledgebase.section.${s.key}`) }}</h2>
            <KbProse :html="s.html" />
        </section>

        <section v-if="related.length" class="space-y-2">
            <h2 class="text-lg font-semibold">{{ trans('knowledgebase.related') }}</h2>
            <ul class="list-disc pl-5">
                <li v-for="r in related" :key="r.id"><Link :href="`/lexicon/${r.id}`" class="text-accent hover:underline">{{ r.title }}</Link></li>
            </ul>
        </section>

        <footer class="space-y-1 border-t border-border pt-3 text-sm text-text-2">
            <p v-if="entry.creator">{{ trans('knowledgebase.created_by', { name: entry.creator }) }}</p>
            <p v-if="entry.editor">{{ trans('knowledgebase.edited_by', { name: entry.editor }) }}</p>
            <p v-if="entry.updated_at">{{ trans('knowledgebase.updated_at', { date: date(entry.updated_at) }) }}</p>
        </footer>
    </article>
</template>
