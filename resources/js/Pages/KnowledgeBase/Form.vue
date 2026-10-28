<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import KbEditor from '@/components/knowledgebase/KbEditor.vue';

type Doc = Record<string, unknown> | null;
type Related = { id: number; title: string; type: string; is_archived?: boolean };

const props = defineProps<{
    entry: {
        id: number;
        type: string;
        title: string;
        subtitle: string | null;
        competency_level: string | null;
        category_id: number | null;
        active_ingredient: string | null;
        drug_class: string | null;
        content: Doc;
        sections: Record<string, Doc>;
        tags: number[];
        related: Related[];
    } | null;
    types: string[];
    competencyLevels: { key: string; label: string }[];
    sectionsByType: Record<string, string[]>;
    categories: { id: number; name: string; depth: number }[];
    tags: { id: number; name: string; color: string }[];
}>();

const editing = props.entry !== null;
const content = ref<Doc>(props.entry?.content ?? null);
const sections = ref<Record<string, Doc>>({ ...(props.entry?.sections ?? {}) });
const initial = { content: JSON.stringify(content.value), sections: Object.fromEntries(Object.entries(sections.value).map(([k, v]) => [k, JSON.stringify(v)])) };
const related = ref<Related[]>([...(props.entry?.related ?? [])]);
const form = useForm({
    type: props.entry?.type ?? 'general',
    title: props.entry?.title ?? '',
    subtitle: props.entry?.subtitle ?? '',
    competency_level: props.entry?.competency_level ?? '',
    category_id: props.entry?.category_id == null ? '' : String(props.entry.category_id),
    active_ingredient: props.entry?.active_ingredient ?? '',
    drug_class: props.entry?.drug_class ?? '',
    tags: [...(props.entry?.tags ?? [])],
});

const sectionKeys = computed(() => props.sectionsByType[form.type] ?? []);
const error = (key: string) => (form.errors as Record<string, string | undefined>)[key];
const firstError = (prefix: string) => Object.entries(form.errors).find(([k]) => k === prefix || k.startsWith(`${prefix}.`))?.[1];

form.transform((d) => ({
    ...(editing ? {} : { type: d.type }),
    title: d.title,
    subtitle: d.subtitle || null,
    competency_level: d.competency_level || null,
    category_id: d.category_id === '' ? null : Number(d.category_id),
    ...(d.type === 'medication' ? { active_ingredient: d.active_ingredient || null, drug_class: d.drug_class || null } : {}),
    ...(content.value === null || JSON.stringify(content.value) === initial.content ? {} : { content: JSON.stringify(content.value) }),
    sections: Object.fromEntries(
        (props.sectionsByType[d.type] ?? []).filter((k) => sections.value[k] != null && JSON.stringify(sections.value[k]) !== initial.sections[k]).map((k) => [k, JSON.stringify(sections.value[k])]),
    ),
    tags: d.tags,
    related: related.value.map((r) => r.id),
}));

function submit() {
    if (props.entry) form.put(`/lexicon/${props.entry.id}`);
    else form.post('/lexicon');
}

function toggleTag(id: number) {
    form.tags = form.tags.includes(id) ? form.tags.filter((t) => t !== id) : [...form.tags, id];
}

const search = ref('');
const hits = ref<Related[]>([]);
let timer: ReturnType<typeof setTimeout> | undefined;
watch(search, (value) => {
    clearTimeout(timer);
    if (value.trim() === '') {
        hits.value = [];
        return;
    }
    timer = setTimeout(async () => {
        const url = `/lexicon/lookup?q=${encodeURIComponent(value.trim())}${props.entry ? `&exclude=${props.entry.id}` : ''}`;
        try {
            const res = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            hits.value = res.ok ? ((await res.json()) as Related[]).filter((h) => !related.value.some((r) => r.id === h.id)) : [];
        } catch {
            hits.value = [];
        }
    }, 250);
});
function addRelated(r: Related) {
    related.value = [...related.value, r];
    hits.value = hits.value.filter((h) => h.id !== r.id);
    search.value = '';
}

const select = 'mt-1 block h-9 w-full rounded-md border border-border bg-card px-3 text-sm';
const label = 'block text-sm font-medium';
</script>

<template>
    <form class="mx-auto w-full max-w-4xl space-y-5" @submit.prevent="submit">
        <h1 class="text-2xl font-semibold">{{ trans(editing ? 'knowledgebase.form.edit_title' : 'knowledgebase.form.new_title') }}</h1>

        <div class="grid gap-4 sm:grid-cols-2">
            <div v-if="!editing">
                <label :class="label" for="kb-type">{{ trans('knowledgebase.form.type') }}</label>
                <select id="kb-type" v-model="form.type" :class="select">
                    <option v-for="t in types" :key="t" :value="t">{{ trans(`knowledgebase.type.${t}`) }}</option>
                </select>
                <p v-if="error('type')" class="mt-1 text-sm text-danger" role="alert">{{ error('type') }}</p>
            </div>
            <p v-else class="self-end text-sm text-text-2">{{ trans(`knowledgebase.type.${form.type}`) }} · {{ trans('knowledgebase.form.type_fixed') }}</p>

            <div>
                <label :class="label" for="kb-competency">{{ trans('knowledgebase.form.competency') }}</label>
                <select id="kb-competency" v-model="form.competency_level" :class="select">
                    <option value="">{{ trans('knowledgebase.form.none') }}</option>
                    <option v-for="c in competencyLevels" :key="c.key" :value="c.key">{{ c.label }}</option>
                </select>
                <p v-if="error('competency_level')" class="mt-1 text-sm text-danger" role="alert">{{ error('competency_level') }}</p>
            </div>

            <div class="sm:col-span-2">
                <label :class="label" for="kb-title">{{ trans('knowledgebase.form.title') }}</label>
                <Input id="kb-title" v-model="form.title" required maxlength="255" class="mt-1" :aria-invalid="!!error('title') || undefined" />
                <p v-if="error('title')" class="mt-1 text-sm text-danger" role="alert">{{ error('title') }}</p>
            </div>
            <div class="sm:col-span-2">
                <label :class="label" for="kb-subtitle">{{ trans('knowledgebase.form.subtitle') }}</label>
                <Input id="kb-subtitle" v-model="form.subtitle" maxlength="255" class="mt-1" />
                <p v-if="error('subtitle')" class="mt-1 text-sm text-danger" role="alert">{{ error('subtitle') }}</p>
            </div>

            <div>
                <label :class="label" for="kb-category">{{ trans('knowledgebase.form.category') }}</label>
                <select id="kb-category" v-model="form.category_id" :class="select">
                    <option value="">{{ trans('knowledgebase.form.none') }}</option>
                    <option v-for="c in categories" :key="c.id" :value="String(c.id)">{{ ' '.repeat(c.depth) }}{{ c.name }}</option>
                </select>
                <p v-if="error('category_id')" class="mt-1 text-sm text-danger" role="alert">{{ error('category_id') }}</p>
            </div>

            <template v-if="form.type === 'medication'">
                <div>
                    <label :class="label" for="kb-ingredient">{{ trans('knowledgebase.active_ingredient') }}</label>
                    <Input id="kb-ingredient" v-model="form.active_ingredient" maxlength="255" class="mt-1" />
                </div>
                <div>
                    <label :class="label" for="kb-class">{{ trans('knowledgebase.drug_class') }}</label>
                    <Input id="kb-class" v-model="form.drug_class" maxlength="255" class="mt-1" />
                </div>
            </template>
        </div>

        <fieldset>
            <legend :class="label">{{ trans('knowledgebase.form.tags') }}</legend>
            <div class="mt-1 flex flex-wrap gap-2">
                <button
                    v-for="t in tags"
                    :key="t.id"
                    type="button"
                    class="rounded-full border px-2 py-0.5 text-xs font-medium"
                    :class="form.tags.includes(t.id) ? 'text-white' : 'border-border text-text-2'"
                    :style="form.tags.includes(t.id) ? { backgroundColor: t.color, borderColor: t.color } : {}"
                    :aria-pressed="form.tags.includes(t.id)"
                    @click="toggleTag(t.id)"
                >
                    {{ t.name }}
                </button>
            </div>
            <p v-if="firstError('tags')" class="mt-1 text-sm text-danger" role="alert">{{ firstError('tags') }}</p>
        </fieldset>

        <div>
            <p :class="label">{{ trans('knowledgebase.content') }}</p>
            <KbEditor v-model="content" :label="trans('knowledgebase.content')" class="mt-1" />
            <p v-if="error('content')" class="mt-1 text-sm text-danger" role="alert">{{ error('content') }}</p>
        </div>

        <div v-for="key in sectionKeys" :key="`${form.type}-${key}`">
            <p :class="label">{{ trans(`knowledgebase.section.${key}`) }}</p>
            <KbEditor v-model="sections[key]" :label="trans(`knowledgebase.section.${key}`)" class="mt-1" />
            <p v-if="error(`sections.${key}`)" class="mt-1 text-sm text-danger" role="alert">{{ error(`sections.${key}`) }}</p>
        </div>

        <fieldset class="space-y-2">
            <legend :class="label">{{ trans('knowledgebase.form.related') }}</legend>
            <ul v-if="related.length" class="flex flex-wrap gap-2">
                <li v-for="r in related" :key="r.id" class="flex items-center gap-1 rounded-full bg-(--fill-3) px-2 py-0.5 text-sm">
                    {{ r.title }}
                    <span v-if="r.is_archived" class="text-xs text-warn">({{ trans('knowledgebase.archived') }})</span>
                    <button type="button" class="text-text-2 hover:text-danger" :aria-label="`${trans('knowledgebase.form.remove')}: ${r.title}`" @click="related = related.filter((x) => x.id !== r.id)">×</button>
                </li>
            </ul>
            <Input v-model="search" type="search" :aria-label="trans('knowledgebase.form.related_search')" :placeholder="trans('knowledgebase.form.related_search')" class="max-w-sm" />
            <ul v-if="hits.length" class="max-w-sm rounded-md border border-border bg-card">
                <li v-for="h in hits" :key="h.id">
                    <button type="button" class="block w-full px-3 py-1.5 text-left text-sm hover:bg-(--fill-3)" @click="addRelated(h)">{{ h.title }}</button>
                </li>
            </ul>
            <p v-if="firstError('related')" class="text-sm text-danger" role="alert">{{ firstError('related') }}</p>
        </fieldset>

        <div class="flex gap-2">
            <Button type="submit" :disabled="form.processing">{{ trans('knowledgebase.form.save') }}</Button>
            <Button as-child variant="ghost"><Link :href="entry ? `/lexicon/${entry.id}` : '/lexicon'">{{ trans('knowledgebase.form.cancel') }}</Link></Button>
        </div>
    </form>
</template>
