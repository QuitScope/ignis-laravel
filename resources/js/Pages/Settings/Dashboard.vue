<script setup lang="ts">
import { nextTick, ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import { trans, transChoice } from 'laravel-vue-i18n';
import { loadTileIcons, tileIcon } from '@/lib/tileIcons';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type QuickLink = { id: number; title: string; url: string; icon: string | null; sort_order: number };
type Category = { id: number; title: string; sort_order: number; links: QuickLink[] };

const props = defineProps<{ categories: Category[] }>();
void loadTileIcons();

const BASE = '/settings/dashboard';
const card = 'rounded-lg border border-border bg-card p-4 shadow-(--shadow)';
const field = 'h-9 w-full rounded-md border border-border bg-card px-3 text-sm';

// ----- category dialog -----
const categoryForm = useForm({ title: '', sort_order: '' as string | number });
const editingCategory = ref<Category | null>(null);
const categoryOpen = ref(false);
function openCategory(c: Category | null) {
    editingCategory.value = c;
    categoryForm.clearErrors();
    categoryForm.defaults({ title: c?.title ?? '', sort_order: c?.sort_order ?? '' }).reset();
    categoryOpen.value = true;
}
function saveCategory() {
    const options = { preserveScroll: true, onSuccess: () => (categoryOpen.value = false) };
    if (editingCategory.value) categoryForm.put(`${BASE}/categories/${editingCategory.value.id}`, options);
    else categoryForm.post(`${BASE}/categories`, options);
}

// ----- link dialog -----
const linkForm = useForm({ dashboard_category_id: 0, title: '', url: '', icon: '', sort_order: '' as string | number });
const editingLink = ref<QuickLink | null>(null);
const linkOpen = ref(false);
function openLink(categoryId: number, l: QuickLink | null) {
    editingLink.value = l;
    linkForm.clearErrors();
    linkForm.defaults({ dashboard_category_id: categoryId, title: l?.title ?? '', url: l?.url ?? '', icon: l?.icon ?? '', sort_order: l?.sort_order ?? '' }).reset();
    linkOpen.value = true;
}
function saveLink() {
    const options = { preserveScroll: true, onSuccess: () => (linkOpen.value = false) };
    if (editingLink.value) linkForm.put(`${BASE}/links/${editingLink.value.id}`, options);
    else linkForm.post(`${BASE}/links`, options);
}

// ----- delete (the category dialog names the number of links that go with it) -----
const deletingCategory = ref<Category | null>(null);
const deletingLink = ref<QuickLink | null>(null);
const destroyCategory = () =>
    deletingCategory.value && router.delete(`${BASE}/categories/${deletingCategory.value.id}`, { preserveScroll: true, onFinish: () => (deletingCategory.value = null) });
const destroyLink = () =>
    deletingLink.value && router.delete(`${BASE}/links/${deletingLink.value.id}`, { preserveScroll: true, onFinish: () => (deletingLink.value = null) });

// ----- reordering: the whole list is sent in its new order; one request at a time -----
const dragged = ref<{ kind: 'category' | 'link'; id: number } | null>(null);
const reordering = ref(false);
function send(path: string, data: { ids: number[]; category_id?: number }, focus?: string) {
    if (reordering.value) return;
    reordering.value = true;
    router.post(`${BASE}${path}`, data, {
        preserveScroll: true,
        preserveState: true,
        onFinish: async () => {
            reordering.value = false;
            if (!focus) return;
            await nextTick();
            const other = focus.endsWith('-up') ? focus.replace(/-up$/, '-down') : focus.replace(/-down$/, '-up');
            document.querySelector<HTMLElement>(`[data-move="${focus}"]:not([disabled]), [data-move="${other}"]:not([disabled])`)?.focus();
        },
    });
}

function placed(ids: number[], id: number, before: number | null) {
    const rest = ids.filter((i) => i !== id);
    const at = before === null ? -1 : rest.indexOf(before);
    rest.splice(at < 0 ? rest.length : at, 0, id);
    return rest;
}

function moveCategory(index: number, by: -1 | 1) {
    const ids = props.categories.map((c) => c.id);
    const to = index + by;
    if (to < 0 || to >= ids.length) return;
    [ids[index], ids[to]] = [ids[to], ids[index]];
    send('/categories/reorder', { ids }, `category-${ids[to]}-${by < 0 ? 'up' : 'down'}`);
}

function moveLink(cat: Category, index: number, by: -1 | 1) {
    const ids = cat.links.map((l) => l.id);
    const to = index + by;
    if (to < 0 || to >= ids.length) return;
    [ids[index], ids[to]] = [ids[to], ids[index]];
    send('/links/reorder', { category_id: cat.id, ids }, `link-${ids[to]}-${by < 0 ? 'up' : 'down'}`);
}

function dropLink(e: DragEvent, cat: Category, before: number | null) {
    const d = dragged.value;
    if (d?.kind !== 'link') return;
    e.stopPropagation();
    dragged.value = null;
    if (d.id === before) return;
    send('/links/reorder', { category_id: cat.id, ids: placed(cat.links.map((l) => l.id), d.id, before) });
}
function dropCategory(before: number) {
    const d = dragged.value;
    dragged.value = null;
    if (!d || d.kind !== 'category' || d.id === before) return;
    send('/categories/reorder', { ids: placed(props.categories.map((c) => c.id), d.id, before) });
}
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold">{{ trans('dashboard.settings.title') }}</h1>
                <p class="mt-1 text-sm text-text-2">{{ trans('dashboard.settings.lead') }}</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <Link href="/dashboard" class="inline-flex h-9 items-center rounded-md border border-border bg-card px-3 text-sm hover:bg-(--fill-3)">{{ trans('dashboard.settings.view') }}</Link>
                <Button @click="openCategory(null)">{{ trans('dashboard.category.new') }}</Button>
            </div>
        </div>

        <p v-if="!categories.length" class="text-sm text-text-2">{{ trans('dashboard.settings.empty') }}</p>

        <article
            v-for="(cat, ci) in categories"
            :key="cat.id"
            :class="card"
            :data-category="cat.id"
            draggable="true"
            @dragstart.self="dragged = { kind: 'category', id: cat.id }"
            @dragend="dragged = null"
            @dragover.prevent
            @drop.prevent="dropCategory(cat.id)"
        >
            <header class="mb-2 flex flex-wrap items-start justify-between gap-2">
                <div class="flex items-center gap-2">
                    <span class="cursor-grab select-none text-text-2" :title="trans('dashboard.drag')" aria-hidden="true">⠿</span>
                    <h2 class="font-medium">{{ cat.title }}</h2>
                </div>
                <div class="flex flex-wrap gap-1">
                    <Button size="sm" variant="ghost" :disabled="reordering || ci === 0" :data-move="`category-${cat.id}-up`" :aria-label="trans('dashboard.move_up', { title: cat.title })" @click="moveCategory(ci, -1)">↑</Button>
                    <Button size="sm" variant="ghost" :disabled="reordering || ci === categories.length - 1" :data-move="`category-${cat.id}-down`" :aria-label="trans('dashboard.move_down', { title: cat.title })" @click="moveCategory(ci, 1)">↓</Button>
                    <Button size="sm" variant="ghost" @click="openLink(cat.id, null)">{{ trans('dashboard.link.new') }}</Button>
                    <Button size="sm" variant="ghost" :aria-label="trans('dashboard.edit_item', { title: cat.title })" @click="openCategory(cat)">{{ trans('dashboard.edit') }}</Button>
                    <Button size="sm" variant="ghost" :aria-label="trans('dashboard.delete_item', { title: cat.title })" @click="deletingCategory = cat">{{ trans('dashboard.delete') }}</Button>
                </div>
            </header>
            <ul class="min-h-8 divide-y divide-border text-sm" @dragover.prevent @drop.prevent="dropLink($event, cat, null)">
                <li
                    v-for="(link, i) in cat.links"
                    :key="link.id"
                    class="flex items-center gap-2 py-1.5"
                    draggable="true"
                    @dragstart.stop="dragged = { kind: 'link', id: link.id }"
                    @dragend="dragged = null"
                    @dragover.prevent
                    @drop.prevent="dropLink($event, cat, link.id)"
                >
                    <span class="cursor-grab select-none text-text-2" :title="trans('dashboard.drag')" aria-hidden="true">⠿</span>
                    <component :is="tileIcon(link.icon)" class="size-4 shrink-0 text-text-2" aria-hidden="true" />
                    <span class="min-w-0 flex-1">
                        <span class="block break-words">{{ link.title }}</span>
                        <span class="block break-all text-xs text-text-2">{{ link.url }}</span>
                    </span>
                    <Button size="sm" variant="ghost" :disabled="reordering || i === 0" :data-move="`link-${link.id}-up`" :aria-label="trans('dashboard.move_up', { title: link.title })" @click="moveLink(cat, i, -1)">↑</Button>
                    <Button size="sm" variant="ghost" :disabled="reordering || i === cat.links.length - 1" :data-move="`link-${link.id}-down`" :aria-label="trans('dashboard.move_down', { title: link.title })" @click="moveLink(cat, i, 1)">↓</Button>
                    <Button size="sm" variant="ghost" :aria-label="trans('dashboard.edit_item', { title: link.title })" @click="openLink(cat.id, link)">{{ trans('dashboard.edit') }}</Button>
                    <Button size="sm" variant="ghost" :aria-label="trans('dashboard.delete_item', { title: link.title })" @click="deletingLink = link">{{ trans('dashboard.delete') }}</Button>
                </li>
                <li v-if="!cat.links.length" class="py-2 text-text-2">{{ trans('dashboard.link.empty') }}</li>
            </ul>
        </article>

        <Dialog v-model:open="categoryOpen">
            <DialogContent class="max-h-[90vh] overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>{{ trans(editingCategory ? 'dashboard.category.edit' : 'dashboard.category.new') }}</DialogTitle>
                    <DialogDescription class="sr-only">{{ trans('dashboard.category.new') }}</DialogDescription>
                </DialogHeader>
                <form class="space-y-3" @submit.prevent="saveCategory">
                    <div class="space-y-1">
                        <Label for="dash-cat-title">{{ trans('dashboard.field.title') }} *</Label>
                        <Input id="dash-cat-title" v-model="categoryForm.title" required maxlength="255" :aria-invalid="!!categoryForm.errors.title" />
                        <p v-if="categoryForm.errors.title" class="text-sm text-danger">{{ categoryForm.errors.title }}</p>
                    </div>
                    <div class="space-y-1">
                        <Label for="dash-cat-sort">{{ trans('dashboard.field.sort_order') }}</Label>
                        <Input id="dash-cat-sort" v-model="categoryForm.sort_order" type="number" min="0" max="9999" step="1" :aria-invalid="!!categoryForm.errors.sort_order" />
                        <p v-if="categoryForm.errors.sort_order" class="text-sm text-danger">{{ categoryForm.errors.sort_order }}</p>
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="ghost" @click="categoryOpen = false">{{ trans('dashboard.cancel') }}</Button>
                        <Button type="submit" :disabled="categoryForm.processing">{{ trans('dashboard.save') }}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="linkOpen">
            <DialogContent class="max-h-[90vh] overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>{{ trans(editingLink ? 'dashboard.link.edit' : 'dashboard.link.new') }}</DialogTitle>
                    <DialogDescription class="sr-only">{{ trans('dashboard.link.new') }}</DialogDescription>
                </DialogHeader>
                <form class="space-y-3" @submit.prevent="saveLink">
                    <div class="space-y-1">
                        <Label for="dash-link-category">{{ trans('dashboard.field.category') }} *</Label>
                        <select id="dash-link-category" v-model.number="linkForm.dashboard_category_id" :class="field" required>
                            <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.title }}</option>
                        </select>
                        <p v-if="linkForm.errors.dashboard_category_id" class="text-sm text-danger">{{ linkForm.errors.dashboard_category_id }}</p>
                    </div>
                    <div class="space-y-1">
                        <Label for="dash-link-title">{{ trans('dashboard.field.title') }} *</Label>
                        <Input id="dash-link-title" v-model="linkForm.title" required maxlength="255" :aria-invalid="!!linkForm.errors.title" />
                        <p v-if="linkForm.errors.title" class="text-sm text-danger">{{ linkForm.errors.title }}</p>
                    </div>
                    <div class="space-y-1">
                        <Label for="dash-link-url">{{ trans('dashboard.field.url') }} *</Label>
                        <Input id="dash-link-url" v-model="linkForm.url" required maxlength="255" placeholder="/vehicles" :aria-invalid="!!linkForm.errors.url" />
                        <p class="text-xs text-text-2">{{ trans('dashboard.hint.url') }}</p>
                        <p v-if="linkForm.errors.url" class="text-sm text-danger">{{ linkForm.errors.url }}</p>
                    </div>
                    <div class="space-y-1">
                        <Label for="dash-link-icon">{{ trans('dashboard.field.icon') }}</Label>
                        <div class="flex items-center gap-2">
                            <Input id="dash-link-icon" v-model="linkForm.icon" maxlength="100" placeholder="fa-solid fa-truck" :aria-invalid="!!linkForm.errors.icon" />
                            <component :is="tileIcon(linkForm.icon)" class="size-5 shrink-0 text-text-2" aria-hidden="true" />
                        </div>
                        <p class="text-xs text-text-2">{{ trans('dashboard.hint.icon') }}</p>
                        <p v-if="linkForm.errors.icon" class="text-sm text-danger">{{ linkForm.errors.icon }}</p>
                    </div>
                    <div class="space-y-1">
                        <Label for="dash-link-sort">{{ trans('dashboard.field.sort_order') }}</Label>
                        <Input id="dash-link-sort" v-model="linkForm.sort_order" type="number" min="0" max="9999" step="1" :aria-invalid="!!linkForm.errors.sort_order" />
                        <p v-if="linkForm.errors.sort_order" class="text-sm text-danger">{{ linkForm.errors.sort_order }}</p>
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="ghost" @click="linkOpen = false">{{ trans('dashboard.cancel') }}</Button>
                        <Button type="submit" :disabled="linkForm.processing">{{ trans('dashboard.save') }}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog :open="deletingCategory !== null" @update:open="(v) => !v && (deletingCategory = null)">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{{ trans('dashboard.category.delete') }}</DialogTitle>
                    <DialogDescription class="text-foreground">
                        {{ transChoice('dashboard.category.delete_confirm', deletingCategory?.links.length ?? 0, { title: deletingCategory?.title ?? '', count: String(deletingCategory?.links.length ?? 0) }) }}
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button variant="ghost" @click="deletingCategory = null">{{ trans('dashboard.cancel') }}</Button>
                    <Button variant="destructive" @click="destroyCategory">{{ trans('dashboard.delete') }}</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <Dialog :open="deletingLink !== null" @update:open="(v) => !v && (deletingLink = null)">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{{ trans('dashboard.link.delete') }}</DialogTitle>
                    <DialogDescription class="text-foreground">{{ trans('dashboard.link.delete_confirm', { title: deletingLink?.title ?? '' }) }}</DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button variant="ghost" @click="deletingLink = null">{{ trans('dashboard.cancel') }}</Button>
                    <Button variant="destructive" @click="destroyLink">{{ trans('dashboard.delete') }}</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
