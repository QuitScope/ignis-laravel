<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type Medication = { id: number; substance: string; brand: string | null; doses: string[]; sort: number; is_active: boolean };

defineProps<{ medications: Medication[] }>();

const editing = ref<number | null>(null);
const form = useForm({ substance: '', brand: '', doses: '', sort: 0, is_active: true });

function edit(m: Medication) {
    editing.value = m.id;
    form.substance = m.substance;
    form.brand = m.brand ?? '';
    form.doses = m.doses.join(', ');
    form.sort = m.sort;
    form.is_active = m.is_active;
}
function reset() {
    editing.value = null;
    form.reset();
    form.clearErrors();
}
function submit() {
    const options = { preserveScroll: true, onSuccess: reset };
    if (editing.value === null) form.post('/settings/medications', options);
    else form.put(`/settings/medications/${editing.value}`, options);
}
function remove(m: Medication) {
    if (window.confirm(trans('enotf.settings.delete_confirm', { name: m.substance }))) router.delete(`/settings/medications/${m.id}`, { preserveScroll: true });
}
</script>

<template>
    <div class="mx-auto max-w-4xl space-y-4">
        <h1 class="text-2xl font-semibold">{{ trans('enotf.medications.settings') }}</h1>

        <form class="flex flex-wrap items-end gap-3 rounded-md border border-border p-4" @submit.prevent="submit">
            <label class="text-sm text-text-2"
                >{{ trans('enotf.medications.substance') }}
                <Input v-model="form.substance" required maxlength="255" class="w-56" />
            </label>
            <label class="text-sm text-text-2"
                >{{ trans('enotf.medications.brand') }}
                <Input v-model="form.brand" maxlength="255" class="w-44" />
            </label>
            <label class="text-sm text-text-2"
                >{{ trans('enotf.medications.doses') }}
                <Input v-model="form.doses" maxlength="1000" class="w-64" :placeholder="trans('enotf.medications.doses_hint')" />
            </label>
            <label class="text-sm text-text-2"
                >{{ trans('enotf.settings.sort') }}
                <Input v-model.number="form.sort" type="number" class="w-24" />
            </label>
            <label class="flex items-center gap-2 text-sm"><input v-model="form.is_active" type="checkbox" />{{ trans('enotf.settings.active') }}</label>
            <Button type="submit" :disabled="form.processing">{{ trans('enotf.settings.save') }}</Button>
            <Button v-if="editing !== null" type="button" variant="ghost" @click="reset">{{ trans('enotf.settings.cancel') }}</Button>
            <p v-for="(m, k) in form.errors" :key="k" role="alert" class="w-full text-sm text-danger">{{ m }}</p>
        </form>

        <ul class="divide-y divide-border rounded-lg border border-border bg-card shadow-(--shadow)">
            <li v-for="m in medications" :key="m.id" class="flex flex-wrap items-center gap-3 px-3 py-2">
                <span class="font-medium" :class="{ 'text-text-2 line-through': !m.is_active }">{{ m.substance }}</span>
                <span v-if="m.brand" class="text-sm text-text-2">{{ m.brand }}</span>
                <span class="text-xs text-text-2">{{ m.doses.join(', ') }} · {{ m.sort }}</span>
                <span v-if="!m.is_active" class="text-xs text-text-2">{{ trans('enotf.medications.inactive') }}</span>
                <span class="ml-auto flex gap-1">
                    <Button variant="ghost" size="sm" @click="edit(m)">{{ trans('enotf.settings.edit') }}</Button>
                    <Button variant="ghost" size="sm" @click="remove(m)">{{ trans('enotf.settings.delete') }}</Button>
                </span>
            </li>
            <li v-if="!medications.length" class="px-3 py-6 text-center text-text-2">{{ trans('enotf.settings.none') }}</li>
        </ul>
    </div>
</template>
