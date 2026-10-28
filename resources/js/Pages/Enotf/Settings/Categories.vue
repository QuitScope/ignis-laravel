<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type Category = { id: number; name: string; sort_order: number; active: boolean; links: number };

defineProps<{ categories: Category[] }>();

const editing = ref<number | null>(null);
const form = useForm({ name: '', sort_order: 0, active: true });

function edit(c: Category) {
    editing.value = c.id;
    form.name = c.name;
    form.sort_order = c.sort_order;
    form.active = c.active;
}
function reset() {
    editing.value = null;
    form.reset();
    form.clearErrors();
}
function submit() {
    const options = { preserveScroll: true, onSuccess: reset };
    if (editing.value === null) form.post('/settings/enotf/categories', options);
    else form.put(`/settings/enotf/categories/${editing.value}`, options);
}
function remove(c: Category) {
    if (window.confirm(trans('enotf.settings.delete_confirm', { name: c.name }))) router.delete(`/settings/enotf/categories/${c.id}`, { preserveScroll: true });
}
</script>

<template>
    <div class="mx-auto max-w-3xl space-y-4">
        <h1 class="text-2xl font-semibold">{{ trans('enotf.settings.categories') }}</h1>

        <form class="flex flex-wrap items-end gap-3 rounded-md border border-border p-4" @submit.prevent="submit">
            <label class="text-sm text-text-2"
                >{{ trans('enotf.settings.name') }}
                <Input v-model="form.name" required maxlength="100" class="w-64" />
            </label>
            <label class="text-sm text-text-2"
                >{{ trans('enotf.settings.sort') }}
                <Input v-model.number="form.sort_order" type="number" class="w-24" />
            </label>
            <label class="flex items-center gap-2 text-sm"><input v-model="form.active" type="checkbox" />{{ trans('enotf.settings.active') }}</label>
            <Button type="submit" :disabled="form.processing">{{ trans('enotf.settings.save') }}</Button>
            <Button v-if="editing !== null" type="button" variant="ghost" @click="reset">{{ trans('enotf.settings.cancel') }}</Button>
            <p v-for="(m, k) in form.errors" :key="k" role="alert" class="w-full text-sm text-danger">{{ m }}</p>
        </form>
        <p v-if="($page.props.errors as Record<string, string>).category" role="alert" class="text-sm text-danger">{{ ($page.props.errors as Record<string, string>).category }}</p>

        <ul class="divide-y divide-border rounded-lg border border-border bg-card shadow-(--shadow)">
            <li v-for="c in categories" :key="c.id" class="flex flex-wrap items-center gap-3 px-3 py-2">
                <span class="font-medium" :class="{ 'text-text-2': !c.active }">{{ c.name }}</span>
                <span class="text-xs text-text-2">{{ trans('enotf.settings.links', { count: String(c.links) }) }} · {{ c.sort_order }}</span>
                <span class="ml-auto flex gap-1">
                    <Button variant="ghost" size="sm" @click="edit(c)">{{ trans('enotf.settings.edit') }}</Button>
                    <Button variant="ghost" size="sm" @click="remove(c)">{{ trans('enotf.settings.delete') }}</Button>
                </span>
            </li>
            <li v-if="!categories.length" class="px-3 py-6 text-center text-text-2">{{ trans('enotf.settings.none') }}</li>
        </ul>
    </div>
</template>
