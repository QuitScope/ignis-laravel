<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type Link = { id: number; category_id: number; title: string; url: string; icon: string | null; sort_order: number; col_width: string; active: boolean };

const props = defineProps<{ links: Link[]; categories: { id: number; name: string }[]; icons: string[]; widths: string[] }>();

const editing = ref<number | null>(null);
const blank = () => ({ enotf_category_id: props.categories[0]?.id ?? 0, title: '', url: '', icon: '', sort_order: 0, col_width: 'col-6', active: true });
const form = useForm(blank());

function edit(l: Link) {
    editing.value = l.id;
    form.defaults({ enotf_category_id: l.category_id, title: l.title, url: l.url, icon: l.icon ?? '', sort_order: l.sort_order, col_width: l.col_width, active: l.active }).reset();
}
function reset() {
    editing.value = null;
    form.defaults(blank()).reset();
    form.clearErrors();
}
function submit() {
    const options = { preserveScroll: true, onSuccess: reset };
    form.transform((d) => ({ ...d, icon: d.icon === '' ? null : d.icon }));
    if (editing.value === null) form.post('/settings/enotf/quicklinks', options);
    else form.put(`/settings/enotf/quicklinks/${editing.value}`, options);
}
function remove(l: Link) {
    if (window.confirm(trans('enotf.settings.delete_confirm', { name: l.title }))) router.delete(`/settings/enotf/quicklinks/${l.id}`, { preserveScroll: true });
}
const categoryName = (id: number) => props.categories.find((c) => c.id === id)?.name ?? '';
const select = 'h-9 rounded-md border border-border bg-card px-3 text-sm';
</script>

<template>
    <div class="mx-auto max-w-4xl space-y-4">
        <h1 class="text-2xl font-semibold">{{ trans('enotf.settings.quicklinks') }}</h1>

        <form class="grid gap-3 rounded-md border border-border p-4 sm:grid-cols-2" @submit.prevent="submit">
            <label class="text-sm text-text-2"
                >{{ trans('enotf.settings.category') }}
                <select v-model="form.enotf_category_id" :class="select" class="block w-full">
                    <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
                </select>
            </label>
            <label class="text-sm text-text-2"
                >{{ trans('enotf.settings.title') }}
                <Input v-model="form.title" required maxlength="255" />
            </label>
            <label class="text-sm text-text-2 sm:col-span-2"
                >{{ trans('enotf.settings.url') }}
                <Input v-model="form.url" required maxlength="500" placeholder="/enotf/overview" />
                <span class="text-xs">{{ trans('enotf.settings.url_hint') }}</span>
            </label>
            <label class="text-sm text-text-2"
                >{{ trans('enotf.settings.icon') }}
                <select v-model="form.icon" :class="select" class="block w-full">
                    <option value="">–</option>
                    <option v-for="i in icons" :key="i" :value="i">{{ i }}</option>
                </select>
            </label>
            <label class="text-sm text-text-2"
                >{{ trans('enotf.settings.width') }}
                <select v-model="form.col_width" :class="select" class="block w-full">
                    <option v-for="w in widths" :key="w" :value="w">{{ w }}</option>
                </select>
            </label>
            <label class="text-sm text-text-2"
                >{{ trans('enotf.settings.sort') }}
                <Input v-model.number="form.sort_order" type="number" />
            </label>
            <label class="flex items-center gap-2 self-end text-sm"><input v-model="form.active" type="checkbox" />{{ trans('enotf.settings.active') }}</label>
            <p v-for="(m, k) in form.errors" :key="k" role="alert" class="text-sm text-danger sm:col-span-2">{{ m }}</p>
            <div class="flex gap-2 sm:col-span-2">
                <Button type="submit" :disabled="form.processing || !categories.length">{{ trans('enotf.settings.save') }}</Button>
                <Button v-if="editing !== null" type="button" variant="ghost" @click="reset">{{ trans('enotf.settings.cancel') }}</Button>
            </div>
        </form>

        <ul class="divide-y divide-border rounded-lg border border-border bg-card shadow-(--shadow)">
            <li v-for="l in links" :key="l.id" class="flex flex-wrap items-center gap-3 px-3 py-2">
                <span class="font-medium" :class="{ 'text-text-2': !l.active }">{{ l.title }}</span>
                <span class="text-xs text-text-2">{{ categoryName(l.category_id) }} · {{ l.url }}</span>
                <span class="ml-auto flex gap-1">
                    <Button variant="ghost" size="sm" @click="edit(l)">{{ trans('enotf.settings.edit') }}</Button>
                    <Button variant="ghost" size="sm" @click="remove(l)">{{ trans('enotf.settings.delete') }}</Button>
                </span>
            </li>
            <li v-if="!links.length" class="px-3 py-6 text-center text-text-2">{{ trans('enotf.settings.none') }}</li>
        </ul>
    </div>
</template>
