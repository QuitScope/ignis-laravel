<script setup lang="ts">
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { xsrfToken } from '@/lib/draftSave';

type Category = { id: number; parent_id: number | null; name: string; icon: string | null; sort_order: number; children_count: number; entries_count: number };
type Tag = { id: number; name: string; color: string; entries_count: number };

const props = defineProps<{ categories: Category[]; tags: Tag[]; defaultColor: string }>();

const rows = computed(() => {
    const byParent = new Map<number | null, Category[]>();
    const ids = new Set(props.categories.map((c) => c.id));
    for (const c of props.categories) {
        const key = c.parent_id !== null && ids.has(c.parent_id) ? c.parent_id : null;
        byParent.set(key, [...(byParent.get(key) ?? []), c]);
    }
    const out: { c: Category; depth: number }[] = [];
    const seen = new Set<number>();
    const walk = (parent: number | null, depth: number) => {
        for (const c of byParent.get(parent) ?? []) {
            if (seen.has(c.id)) continue;
            seen.add(c.id);
            out.push({ c, depth });
            walk(c.id, depth + 1);
        }
    };
    walk(null, 0);
    for (const c of props.categories) if (!seen.has(c.id)) out.push({ c, depth: 0 });

    return out;
});

function descendants(id: number): Set<number> {
    const found = new Set<number>();
    const grow = (p: number) => {
        for (const c of props.categories) {
            if (c.parent_id === p && !found.has(c.id)) {
                found.add(c.id);
                grow(c.id);
            }
        }
    };
    grow(id);

    return found;
}
const parentChoices = computed(() => {
    const blocked = catId.value === null ? new Set<number>() : new Set([catId.value, ...descendants(catId.value)]);

    return rows.value.filter(({ c }) => !blocked.has(c.id));
});

async function send(method: 'POST' | 'PUT' | 'DELETE', url: string, body?: unknown): Promise<string | null> {
    try {
        const res = await fetch(url, {
            method,
            credentials: 'same-origin',
            headers: { Accept: 'application/json', ...(body === undefined ? {} : { 'Content-Type': 'application/json' }), 'X-XSRF-TOKEN': xsrfToken() },
            body: body === undefined ? undefined : JSON.stringify(body),
        });
        if (res.ok) {
            router.reload({ only: ['categories', 'tags'] });

            return null;
        }
        if (res.status === 401 || res.status === 419) return trans('knowledgebase.error.session');
        const json = await res.json().catch(() => null);
        const first = json?.errors ? (Object.values(json.errors)[0] as string[])[0] : null;

        return json?.message && !json?.errors ? json.message : (first ?? trans('knowledgebase.error.save'));
    } catch {
        return trans('knowledgebase.error.save');
    }
}

// Category dialog
const catOpen = ref(false);
const catId = ref<number | null>(null);
const cat = ref({ name: '', parent_id: '' as number | '', icon: '', sort_order: '0' });
const catError = ref<string | null>(null);
function openCategory(c: Category | null) {
    catId.value = c?.id ?? null;
    cat.value = { name: c?.name ?? '', parent_id: c?.parent_id ?? '', icon: c?.icon ?? '', sort_order: String(c?.sort_order ?? 0) };
    catError.value = null;
    catOpen.value = true;
}
async function saveCategory() {
    const body = { name: cat.value.name, parent_id: cat.value.parent_id === '' ? null : cat.value.parent_id, icon: cat.value.icon === '' ? null : cat.value.icon, sort_order: Number(cat.value.sort_order) };
    catError.value = await send(catId.value === null ? 'POST' : 'PUT', catId.value === null ? '/lexicon/categories' : `/lexicon/categories/${catId.value}`, body);
    if (catError.value === null) catOpen.value = false;
}
const deleteError = ref<string | null>(null);
const deletingCat = ref<Category | null>(null);
async function destroyCategory() {
    if (deletingCat.value === null) return;
    deleteError.value = await send('DELETE', `/lexicon/categories/${deletingCat.value.id}`);
    if (deleteError.value === null) deletingCat.value = null;
}

// Tag dialog
const tagOpen = ref(false);
const tagId = ref<number | null>(null);
const tag = ref({ name: '', color: props.defaultColor });
const tagError = ref<string | null>(null);
function openTag(t: Tag | null) {
    tagId.value = t?.id ?? null;
    tag.value = { name: t?.name ?? '', color: t?.color ?? props.defaultColor };
    tagError.value = null;
    tagOpen.value = true;
}
async function saveTag() {
    tagError.value = await send(tagId.value === null ? 'POST' : 'PUT', tagId.value === null ? '/lexicon/tags' : `/lexicon/tags/${tagId.value}`, tag.value);
    if (tagError.value === null) tagOpen.value = false;
}
const deletingTag = ref<Tag | null>(null);
async function destroyTag() {
    if (deletingTag.value === null) return;
    deleteError.value = await send('DELETE', `/lexicon/tags/${deletingTag.value.id}`);
    if (deleteError.value === null) deletingTag.value = null;
}

const field = 'w-full rounded-md border border-border bg-card px-3 py-2 text-sm';
</script>

<template>
    <div class="space-y-8">
        <h1 class="text-2xl font-semibold">{{ trans('knowledgebase.taxonomy.title') }}</h1>

        <section class="space-y-3">
            <div class="flex items-center justify-between gap-3">
                <h2 class="text-lg font-medium">{{ trans('knowledgebase.taxonomy.categories') }}</h2>
                <Button @click="openCategory(null)">{{ trans('knowledgebase.taxonomy.new_category') }}</Button>
            </div>
            <ul class="divide-y divide-border rounded-lg border border-border bg-card shadow-(--shadow)" data-test="categories">
                <li v-for="{ c, depth } in rows" :key="c.id" class="flex flex-wrap items-center gap-2 px-3 py-2" :style="{ paddingLeft: `${0.75 + depth * 1.5}rem` }">
                    <span class="font-medium">{{ c.name }}</span>
                    <span v-if="c.icon" class="rounded-full bg-(--fill-3) px-2 py-0.5 text-xs text-text-2">{{ c.icon }}</span>
                    <span class="text-xs text-text-2">{{ trans('knowledgebase.taxonomy.entries', { count: String(c.entries_count) }) }} · #{{ c.sort_order }}</span>
                    <span class="ml-auto whitespace-nowrap">
                        <Button variant="ghost" size="sm" @click="openCategory(c)">{{ trans('knowledgebase.taxonomy.edit') }}</Button>
                        <Button variant="ghost" size="sm" @click="((deleteError = null), (deletingCat = c))">{{ trans('knowledgebase.taxonomy.delete') }}</Button>
                    </span>
                </li>
                <li v-if="rows.length === 0" class="px-3 py-6 text-center text-sm text-text-2">{{ trans('knowledgebase.taxonomy.empty_categories') }}</li>
            </ul>
        </section>

        <section class="space-y-3">
            <div class="flex items-center justify-between gap-3">
                <h2 class="text-lg font-medium">{{ trans('knowledgebase.taxonomy.tags') }}</h2>
                <Button @click="openTag(null)">{{ trans('knowledgebase.taxonomy.new_tag') }}</Button>
            </div>
            <ul class="divide-y divide-border rounded-lg border border-border bg-card shadow-(--shadow)" data-test="tags">
                <li v-for="t in tags" :key="t.id" class="flex flex-wrap items-center gap-2 px-3 py-2">
                    <span class="inline-block size-4 rounded-full border border-border" :style="{ backgroundColor: t.color }" aria-hidden="true" />
                    <span class="font-medium">{{ t.name }}</span>
                    <span class="text-xs text-text-2">{{ trans('knowledgebase.taxonomy.entries', { count: String(t.entries_count) }) }}</span>
                    <span class="ml-auto whitespace-nowrap">
                        <Button variant="ghost" size="sm" @click="openTag(t)">{{ trans('knowledgebase.taxonomy.edit') }}</Button>
                        <Button variant="ghost" size="sm" @click="((deleteError = null), (deletingTag = t))">{{ trans('knowledgebase.taxonomy.delete') }}</Button>
                    </span>
                </li>
                <li v-if="tags.length === 0" class="px-3 py-6 text-center text-sm text-text-2">{{ trans('knowledgebase.taxonomy.empty_tags') }}</li>
            </ul>
        </section>

        <Dialog :open="catOpen" @update:open="(v) => !v && (catOpen = false)">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{{ trans(catId === null ? 'knowledgebase.taxonomy.new_category' : 'knowledgebase.taxonomy.edit') }}</DialogTitle>
                    <DialogDescription class="sr-only">{{ trans('knowledgebase.taxonomy.title') }}</DialogDescription>
                </DialogHeader>
                <form class="space-y-3" @submit.prevent="saveCategory">
                    <div class="space-y-1">
                        <Label for="kb-cat-name">{{ trans('knowledgebase.taxonomy.field.name') }}</Label>
                        <Input id="kb-cat-name" v-model="cat.name" maxlength="100" required />
                    </div>
                    <div class="space-y-1">
                        <Label for="kb-cat-parent">{{ trans('knowledgebase.taxonomy.field.parent') }}</Label>
                        <select id="kb-cat-parent" v-model="cat.parent_id" :class="field">
                            <option value="">{{ trans('knowledgebase.taxonomy.field.no_parent') }}</option>
                            <option v-for="{ c, depth } in parentChoices" :key="c.id" :value="c.id">{{ '– '.repeat(depth) }}{{ c.name }}</option>
                        </select>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div class="space-y-1">
                            <Label for="kb-cat-icon">{{ trans('knowledgebase.taxonomy.field.icon') }}</Label>
                            <Input id="kb-cat-icon" v-model="cat.icon" maxlength="50" />
                        </div>
                        <div class="space-y-1">
                            <Label for="kb-cat-sort">{{ trans('knowledgebase.taxonomy.field.sort') }}</Label>
                            <Input id="kb-cat-sort" v-model="cat.sort_order" type="number" min="0" max="65535" />
                        </div>
                    </div>
                    <p v-if="catError" class="text-sm text-destructive" role="alert">{{ catError }}</p>
                    <DialogFooter>
                        <Button type="button" variant="ghost" @click="catOpen = false">{{ trans('knowledgebase.taxonomy.cancel') }}</Button>
                        <Button type="submit">{{ trans('knowledgebase.taxonomy.save') }}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog :open="tagOpen" @update:open="(v) => !v && (tagOpen = false)">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{{ trans(tagId === null ? 'knowledgebase.taxonomy.new_tag' : 'knowledgebase.taxonomy.edit') }}</DialogTitle>
                    <DialogDescription class="sr-only">{{ trans('knowledgebase.taxonomy.title') }}</DialogDescription>
                </DialogHeader>
                <form class="space-y-3" @submit.prevent="saveTag">
                    <div class="space-y-1">
                        <Label for="kb-tag-name">{{ trans('knowledgebase.taxonomy.field.name') }}</Label>
                        <Input id="kb-tag-name" v-model="tag.name" maxlength="50" required />
                    </div>
                    <div class="space-y-1">
                        <Label for="kb-tag-color">{{ trans('knowledgebase.taxonomy.field.color') }}</Label>
                        <input id="kb-tag-color" v-model="tag.color" type="color" class="h-9 w-16 cursor-pointer rounded-md border border-border bg-card p-1" />
                    </div>
                    <p v-if="tagError" class="text-sm text-destructive" role="alert">{{ tagError }}</p>
                    <DialogFooter>
                        <Button type="button" variant="ghost" @click="tagOpen = false">{{ trans('knowledgebase.taxonomy.cancel') }}</Button>
                        <Button type="submit">{{ trans('knowledgebase.taxonomy.save') }}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog :open="deletingCat !== null" @update:open="(v) => !v && (deletingCat = null)">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{{ trans('knowledgebase.taxonomy.delete_category') }}</DialogTitle>
                </DialogHeader>
                <DialogDescription v-if="deletingCat">
                    {{ trans('knowledgebase.taxonomy.delete_category_confirm', { name: deletingCat.name, children: String(deletingCat.children_count), entries: String(deletingCat.entries_count) }) }}
                </DialogDescription>
                <p v-if="deleteError" class="text-sm text-destructive" role="alert">{{ deleteError }}</p>
                <DialogFooter>
                    <Button variant="ghost" @click="deletingCat = null">{{ trans('knowledgebase.taxonomy.cancel') }}</Button>
                    <Button variant="destructive" @click="destroyCategory">{{ trans('knowledgebase.taxonomy.delete') }}</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <Dialog :open="deletingTag !== null" @update:open="(v) => !v && (deletingTag = null)">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{{ trans('knowledgebase.taxonomy.delete_tag') }}</DialogTitle>
                </DialogHeader>
                <DialogDescription v-if="deletingTag">{{ trans('knowledgebase.taxonomy.delete_tag_confirm', { name: deletingTag.name, entries: String(deletingTag.entries_count) }) }}</DialogDescription>
                <p v-if="deleteError" class="text-sm text-destructive" role="alert">{{ deleteError }}</p>
                <DialogFooter>
                    <Button variant="ghost" @click="deletingTag = null">{{ trans('knowledgebase.taxonomy.cancel') }}</Button>
                    <Button variant="destructive" @click="destroyTag">{{ trans('knowledgebase.taxonomy.delete') }}</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
