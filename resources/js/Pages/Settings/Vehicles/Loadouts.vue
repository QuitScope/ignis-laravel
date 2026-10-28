<script setup lang="ts">
import { computed, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { trans, transChoice } from 'laravel-vue-i18n';
import { loadoutDialogDefaults } from '@/lib/loadout';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Item = { id: number; title: string; quantity: number };
type Category = { id: number; title: string; compartment: number; vehicle_type: string | null; sort_order: number; items: Item[] };

const props = defineProps<{ categories: Category[]; types: string[]; can: { manage: boolean } }>();

const BASE = '/settings/vehicles/loadouts';
const compartments = [0, 1, 2];
const filter = ref('');
const visible = (c: Category) => filter.value === '' || c.vehicle_type === null || c.vehicle_type === filter.value;
const byCompartment = (n: number) => props.categories.filter((c) => c.compartment === n && visible(c));
const field = 'h-9 w-full rounded-md border border-border bg-card px-3 text-sm';

// ----- category and item dialogs -----
const categoryForm = useForm({ title: '', compartment: 0, vehicle_type: '', sort_order: 0 });
const editingCategory = ref<Category | null>(null);
const categoryOpen = ref(false);
function openCategory(c: Category | null) {
    editingCategory.value = c;
    categoryForm.clearErrors();
    categoryForm.defaults(loadoutDialogDefaults(c, filter.value)).reset();
    categoryOpen.value = true;
}
function saveCategory() {
    const options = { preserveScroll: true, onSuccess: () => (categoryOpen.value = false) };
    if (editingCategory.value) categoryForm.put(`${BASE}/categories/${editingCategory.value.id}`, options);
    else categoryForm.post(`${BASE}/categories`, options);
}

const itemForm = useForm({ title: '', quantity: 1, category_id: 0 });
const editingItem = ref<Item | null>(null);
const itemOpen = ref(false);
function openItem(categoryId: number, item: Item | null) {
    editingItem.value = item;
    itemForm.clearErrors();
    itemForm.defaults({ title: item?.title ?? '', quantity: item?.quantity ?? 1, category_id: categoryId }).reset();
    itemOpen.value = true;
}
function saveItem() {
    const options = { preserveScroll: true, onSuccess: () => (itemOpen.value = false) };
    if (editingItem.value) itemForm.put(`${BASE}/items/${editingItem.value.id}`, options);
    else itemForm.transform(({ category_id: _c, ...d }) => d).post(`${BASE}/categories/${itemForm.category_id}/items`, options);
}

const deletingCategory = ref<Category | null>(null);
const deletingItem = ref<Item | null>(null);
const destroyCategory = () =>
    deletingCategory.value && router.delete(`${BASE}/categories/${deletingCategory.value.id}`, { preserveScroll: true, onFinish: () => (deletingCategory.value = null) });
const destroyItem = () =>
    deletingItem.value && router.delete(`${BASE}/items/${deletingItem.value.id}`, { preserveScroll: true, onFinish: () => (deletingItem.value = null) });

// ----- inline quantity -----
function setQuantity(item: Item, ev: Event) {
    const input = ev.target as HTMLInputElement;
    const value = Number(input.value);
    if (input.value === '' || !Number.isInteger(value) || value < 0 || value > 100000) {
        input.value = String(item.quantity);
        return;
    }
    if (value !== item.quantity)
        router.patch(`${BASE}/items/${item.id}`, { quantity: value }, { preserveScroll: true, onError: () => (input.value = String(item.quantity)) });
}

// ----- reordering: the whole item list of the target category is sent in its new order -----
const dragged = ref<number | null>(null);
const reordering = ref(false);
const reorder = (categoryId: number, ids: number[]) => {
    if (reordering.value) return;
    reordering.value = true;
    router.post(`${BASE}/items/reorder`, { category_id: categoryId, ids }, { preserveScroll: true, preserveState: true, onFinish: () => (reordering.value = false) });
};

function move(cat: Category, index: number, by: -1 | 1) {
    const ids = cat.items.map((i) => i.id);
    const to = index + by;
    if (to < 0 || to >= ids.length) return;
    [ids[index], ids[to]] = [ids[to], ids[index]];
    reorder(cat.id, ids);
}

function drop(cat: Category, before: number | null) {
    const id = dragged.value;
    dragged.value = null;
    if (id === null || id === before) return;
    const ids = cat.items.map((i) => i.id).filter((i) => i !== id);
    const at = before === null ? -1 : ids.indexOf(before);
    ids.splice(at < 0 ? ids.length : at, 0, id);
    reorder(cat.id, ids);
}

const categoryOptions = computed(() => props.categories.map((c) => ({ id: c.id, label: `${c.title} (${trans(`loadouts.compartment.${c.compartment}`)}${c.vehicle_type ? ' – ' + c.vehicle_type : ''})` })));
const card = 'rounded-lg border border-border bg-card p-4 shadow-(--shadow)';
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold">{{ trans('loadouts.title') }}</h1>
                <p class="mt-1 text-sm text-text-2">{{ trans('loadouts.lead') }}</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <Label for="loadout-filter" class="sr-only">{{ trans('loadouts.filter.type') }}</Label>
                <select id="loadout-filter" v-model="filter" class="h-9 rounded-md border border-border bg-card px-3 text-sm">
                    <option value="">{{ trans('loadouts.filter.all') }}</option>
                    <option v-for="t in types" :key="t" :value="t">{{ t }}</option>
                </select>
                <Button v-if="can.manage" @click="openCategory(null)">{{ trans('loadouts.category.new') }}</Button>
            </div>
        </div>

        <p v-if="!categories.length" class="text-sm text-text-2">{{ trans('loadouts.empty') }}</p>

        <template v-for="n in compartments" :key="n">
            <section v-if="byCompartment(n).length" class="space-y-3">
                <h2 class="text-lg font-medium">{{ trans(`loadouts.compartment.${n}`) }}</h2>
                <div class="grid gap-3 lg:grid-cols-2">
                    <article v-for="cat in byCompartment(n)" :key="cat.id" :class="card" :data-category="cat.id">
                        <header class="mb-2 flex flex-wrap items-start justify-between gap-2">
                            <div>
                                <h3 class="font-medium">{{ cat.title }}</h3>
                                <p class="text-xs text-text-2">{{ cat.vehicle_type ?? trans('loadouts.all_types') }}</p>
                            </div>
                            <div v-if="can.manage" class="flex gap-1">
                                <Button size="sm" variant="ghost" @click="openItem(cat.id, null)">{{ trans('loadouts.item.add') }}</Button>
                                <Button size="sm" variant="ghost" @click="openCategory(cat)">{{ trans('loadouts.edit') }}</Button>
                                <Button size="sm" variant="ghost" @click="deletingCategory = cat">{{ trans('loadouts.delete') }}</Button>
                            </div>
                        </header>
                        <ul
                            class="min-h-8 divide-y divide-border text-sm"
                            @dragover.prevent
                            @drop.prevent="can.manage && drop(cat, null)"
                        >
                            <li
                                v-for="(item, i) in cat.items"
                                :key="item.id"
                                class="flex items-center gap-2 py-1.5"
                                :draggable="can.manage"
                                @dragstart="dragged = item.id"
                                @dragend="dragged = null"
                                @dragover.prevent
                                @drop.prevent.stop="can.manage && drop(cat, item.id)"
                            >
                                <span v-if="can.manage" class="cursor-grab select-none text-text-2" :title="trans('loadouts.drag')" aria-hidden="true">⠿</span>
                                <span class="min-w-0 flex-1 break-words">{{ item.title }}</span>
                                <template v-if="can.manage">
                                    <input
                                        type="number"
                                        min="0"
                                        max="100000"
                                        step="1"
                                        :value="item.quantity"
                                        :aria-label="trans('loadouts.quantity_of', { title: item.title })"
                                        class="h-8 w-20 rounded-md border border-border bg-card px-2 text-right text-sm tabular-nums"
                                        @change="setQuantity(item, $event)"
                                    />
                                    <Button size="sm" variant="ghost" :disabled="reordering || i === 0" :aria-label="trans('loadouts.move_up', { title: item.title })" @click="move(cat, i, -1)">↑</Button>
                                    <Button size="sm" variant="ghost" :disabled="reordering || i === cat.items.length - 1" :aria-label="trans('loadouts.move_down', { title: item.title })" @click="move(cat, i, 1)">↓</Button>
                                    <Button size="sm" variant="ghost" @click="openItem(cat.id, item)">{{ trans('loadouts.edit') }}</Button>
                                    <Button size="sm" variant="ghost" @click="deletingItem = item">{{ trans('loadouts.delete') }}</Button>
                                </template>
                                <span v-else class="tabular-nums">{{ item.quantity }}×</span>
                            </li>
                            <li v-if="!cat.items.length" class="py-2 text-text-2">{{ trans('loadouts.item.empty') }}</li>
                        </ul>
                    </article>
                </div>
            </section>
        </template>

        <Dialog v-model:open="categoryOpen">
            <DialogContent class="max-h-[90vh] overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>{{ trans(editingCategory ? 'loadouts.category.edit' : 'loadouts.category.new') }}</DialogTitle>
                    <DialogDescription>{{ trans('loadouts.vehicle_type_hint') }}</DialogDescription>
                </DialogHeader>
                <form class="space-y-3" @submit.prevent="saveCategory">
                    <div class="space-y-1">
                        <Label for="cat-title">{{ trans('loadouts.field.title') }} *</Label>
                        <Input id="cat-title" v-model="categoryForm.title" required maxlength="255" :aria-invalid="!!categoryForm.errors.title" />
                        <p v-if="categoryForm.errors.title" class="text-sm text-danger">{{ categoryForm.errors.title }}</p>
                    </div>
                    <div class="space-y-1">
                        <Label for="cat-compartment">{{ trans('loadouts.field.compartment') }}</Label>
                        <select id="cat-compartment" v-model.number="categoryForm.compartment" :class="field">
                            <option v-for="n in compartments" :key="n" :value="n">{{ trans(`loadouts.compartment.${n}`) }}</option>
                        </select>
                        <p v-if="categoryForm.errors.compartment" class="text-sm text-danger">{{ categoryForm.errors.compartment }}</p>
                    </div>
                    <div class="space-y-1">
                        <Label for="cat-type">{{ trans('loadouts.field.vehicle_type') }}</Label>
                        <Input id="cat-type" v-model="categoryForm.vehicle_type" list="loadout-types" maxlength="255" placeholder="RTW, NEF, KTW" :aria-invalid="!!categoryForm.errors.vehicle_type" />
                        <datalist id="loadout-types"><option v-for="t in types" :key="t" :value="t" /></datalist>
                        <p v-if="categoryForm.errors.vehicle_type" class="text-sm text-danger">{{ categoryForm.errors.vehicle_type }}</p>
                    </div>
                    <div class="space-y-1">
                        <Label for="cat-sort">{{ trans('loadouts.field.sort_order') }}</Label>
                        <Input id="cat-sort" v-model.number="categoryForm.sort_order" type="number" step="1" :aria-invalid="!!categoryForm.errors.sort_order" />
                        <p v-if="categoryForm.errors.sort_order" class="text-sm text-danger">{{ categoryForm.errors.sort_order }}</p>
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="ghost" @click="categoryOpen = false">{{ trans('loadouts.cancel') }}</Button>
                        <Button type="submit" :disabled="categoryForm.processing">{{ trans('loadouts.save') }}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="itemOpen">
            <DialogContent class="max-h-[90vh] overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>{{ trans(editingItem ? 'loadouts.item.edit' : 'loadouts.item.add') }}</DialogTitle>
                    <DialogDescription class="sr-only">{{ trans('loadouts.item.add') }}</DialogDescription>
                </DialogHeader>
                <form class="space-y-3" @submit.prevent="saveItem">
                    <div v-if="editingItem" class="space-y-1">
                        <Label for="item-category">{{ trans('loadouts.field.category') }}</Label>
                        <select id="item-category" v-model.number="itemForm.category_id" :class="field">
                            <option v-for="o in categoryOptions" :key="o.id" :value="o.id">{{ o.label }}</option>
                        </select>
                        <p v-if="itemForm.errors.category_id" class="text-sm text-danger">{{ itemForm.errors.category_id }}</p>
                    </div>
                    <div class="space-y-1">
                        <Label for="item-title">{{ trans('loadouts.field.title') }} *</Label>
                        <Input id="item-title" v-model="itemForm.title" required maxlength="255" :aria-invalid="!!itemForm.errors.title" />
                        <p v-if="itemForm.errors.title" class="text-sm text-danger">{{ itemForm.errors.title }}</p>
                    </div>
                    <div class="space-y-1">
                        <Label for="item-quantity">{{ trans('loadouts.field.quantity') }} *</Label>
                        <Input id="item-quantity" v-model.number="itemForm.quantity" type="number" min="0" max="100000" step="1" required :aria-invalid="!!itemForm.errors.quantity" />
                        <p v-if="itemForm.errors.quantity" class="text-sm text-danger">{{ itemForm.errors.quantity }}</p>
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="ghost" @click="itemOpen = false">{{ trans('loadouts.cancel') }}</Button>
                        <Button type="submit" :disabled="itemForm.processing">{{ trans('loadouts.save') }}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog :open="deletingCategory !== null" @update:open="(v) => !v && (deletingCategory = null)">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{{ trans('loadouts.category.delete') }}</DialogTitle>
                    <DialogDescription class="text-foreground">
                        {{ transChoice('loadouts.category.delete_confirm', deletingCategory?.items.length ?? 0, { title: deletingCategory?.title ?? '', count: String(deletingCategory?.items.length ?? 0) }) }}
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button variant="ghost" @click="deletingCategory = null">{{ trans('loadouts.cancel') }}</Button>
                    <Button variant="destructive" @click="destroyCategory">{{ trans('loadouts.delete') }}</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <Dialog :open="deletingItem !== null" @update:open="(v) => !v && (deletingItem = null)">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{{ trans('loadouts.item.delete') }}</DialogTitle>
                    <DialogDescription class="text-foreground">{{ trans('loadouts.item.delete_confirm', { title: deletingItem?.title ?? '' }) }}</DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button variant="ghost" @click="deletingItem = null">{{ trans('loadouts.cancel') }}</Button>
                    <Button variant="destructive" @click="destroyItem">{{ trans('loadouts.delete') }}</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
