<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type Poi = { id: number; name: string; street: string | null; house_number: string | null; city: string; district: string | null; type: string | null; legacy_identifier: string | null; is_active: boolean };

defineProps<{ pois: Poi[]; types: string[]; can_manage: boolean; hospital_types: string[]; departments: boolean }>();

const editing = ref<number | null>(null);
const form = useForm({ name: '', street: '', house_number: '', city: '', district: '', type: '', legacy_identifier: '', is_active: true });

function edit(p: Poi) {
    editing.value = p.id;
    form.name = p.name;
    form.street = p.street ?? '';
    form.house_number = p.house_number ?? '';
    form.city = p.city;
    form.district = p.district ?? '';
    form.type = p.type ?? '';
    form.legacy_identifier = p.legacy_identifier ?? '';
    form.is_active = p.is_active;
}
function reset() {
    editing.value = null;
    form.reset();
    form.clearErrors();
}
function submit() {
    const options = { preserveScroll: true, onSuccess: reset };
    if (editing.value === null) form.post('/settings/pois', options);
    else form.put(`/settings/pois/${editing.value}`, options);
}
function remove(p: Poi) {
    if (window.confirm(trans('pois.delete_confirm', { name: p.name }))) router.delete(`/settings/pois/${p.id}`, { preserveScroll: true });
}
</script>

<template>
    <div class="mx-auto max-w-4xl space-y-4">
        <h1 class="text-2xl font-semibold">{{ trans('pois.title') }}</h1>

        <form v-if="can_manage" class="flex flex-wrap items-end gap-3 rounded-md border border-border p-4" @submit.prevent="submit">
            <label class="text-sm text-text-2">{{ trans('pois.name') }} <Input v-model="form.name" required maxlength="255" class="w-56" /></label>
            <label class="text-sm text-text-2">{{ trans('pois.street') }} <Input v-model="form.street" maxlength="255" class="w-44" /></label>
            <label class="text-sm text-text-2">{{ trans('pois.house_number') }} <Input v-model="form.house_number" maxlength="50" class="w-20" /></label>
            <label class="text-sm text-text-2">{{ trans('pois.city') }} <Input v-model="form.city" required maxlength="255" class="w-40" /></label>
            <label class="text-sm text-text-2">{{ trans('pois.district') }} <Input v-model="form.district" maxlength="255" class="w-36" /></label>
            <label class="text-sm text-text-2"
                >{{ trans('pois.type') }}
                <Input v-model="form.type" list="poi-types" maxlength="50" class="w-40" />
                <datalist id="poi-types">
                    <option v-for="t in types" :key="t" :value="t" />
                </datalist>
            </label>
            <label class="text-sm text-text-2">{{ trans('pois.identifier') }} <Input v-model="form.legacy_identifier" maxlength="100" class="w-32" /></label>
            <label class="flex items-center gap-2 text-sm"><input v-model="form.is_active" type="checkbox" />{{ trans('pois.active') }}</label>
            <Button type="submit" :disabled="form.processing">{{ trans('pois.save') }}</Button>
            <Button v-if="editing !== null" type="button" variant="ghost" @click="reset">{{ trans('pois.cancel') }}</Button>
            <p v-for="(m, k) in form.errors" :key="k" role="alert" class="w-full text-sm text-danger">{{ m }}</p>
        </form>

        <ul class="divide-y divide-border rounded-lg border border-border bg-card shadow-(--shadow)">
            <li v-for="p in pois" :key="p.id" class="flex flex-wrap items-center gap-3 px-3 py-2">
                <span class="font-medium" :class="{ 'text-text-2': !p.is_active }">{{ p.name }}</span>
                <span class="text-xs text-text-2">{{ [p.type, p.legacy_identifier, p.city].filter(Boolean).join(' · ') }}</span>
                <span v-if="can_manage" class="ml-auto flex gap-1">
                    <a
                        v-if="departments && p.type && hospital_types.includes(p.type)"
                        :href="`/settings/pois/${p.id}/departments`"
                        class="inline-flex min-h-8 items-center px-2 text-sm text-accent"
                        >{{ trans('pois.departments') }}</a
                    >
                    <Button variant="ghost" size="sm" @click="edit(p)">{{ trans('pois.edit') }}</Button>
                    <Button variant="ghost" size="sm" @click="remove(p)">{{ trans('pois.delete') }}</Button>
                </span>
            </li>
            <li v-if="!pois.length" class="px-3 py-6 text-center text-text-2">{{ trans('pois.none') }}</li>
        </ul>
    </div>
</template>
