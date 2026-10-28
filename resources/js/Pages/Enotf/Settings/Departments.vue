<script setup lang="ts">
/** /settings/pois/{poi}/departments (poi.manage): departments with availability, and the clinic access code (shown once). */
import { router, useForm } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { ref } from 'vue';
import DepartmentStatus from '@/components/enotf/DepartmentStatus.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { xsrfToken } from '@/lib/draftSave';

type Department = { id: number; name: string; sort_order: number; status: string; note: string | null; updated_at: string | null; updated_by: string | null };
const props = defineProps<{
    hospital: { id: number; name: string };
    departments: Department[];
    statuses: string[];
    access_code: { rotated_at: string | null; last_used_at: string | null } | null;
}>();

const base = `/settings/pois/${props.hospital.id}`;
const editing = ref<number | null>(null);
const form = useForm({ name: '', sort_order: 999, status: 'not_staffed', note: '' });

function edit(d: Department) {
    editing.value = d.id;
    Object.assign(form, { name: d.name, sort_order: d.sort_order, status: d.status, note: d.note ?? '' });
}
function reset() {
    editing.value = null;
    form.reset();
    form.clearErrors();
}
function submit() {
    const options = { preserveScroll: true, onSuccess: reset };
    if (editing.value === null) form.transform((d) => ({ name: d.name, sort_order: d.sort_order })).post(`${base}/departments`, options);
    else form.transform((d) => d).put(`${base}/departments/${editing.value}`, options);
}
function remove(d: Department) {
    if (window.confirm(trans('enotf.hospital.delete_confirm', { name: d.name }))) router.delete(`${base}/departments/${d.id}`, { preserveScroll: true });
}

// Access code: the plain code exists only in this response, once.
const code = ref<string | null>(null);
const codeMessage = ref('');
async function codeRequest(method: 'POST' | 'DELETE') {
    const res = await fetch(`${base}/access-code`, {
        method,
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrfToken() },
    }).catch(() => null);
    const body = (await res?.json().catch(() => null)) as { code?: string } | null;
    code.value = res?.ok && body?.code ? body.code : null;
    codeMessage.value = res?.ok ? '' : trans('enotf.hospital.code_failed');
    if (res?.ok) router.reload({ only: ['access_code'] });
}
function rotate() {
    if (!props.access_code || window.confirm(trans('enotf.hospital.code_rotate_confirm'))) void codeRequest('POST');
}
function revoke() {
    if (window.confirm(trans('enotf.hospital.code_revoke_confirm'))) void codeRequest('DELETE');
}
</script>

<template>
    <div class="mx-auto max-w-4xl space-y-4">
        <h1 class="text-2xl font-semibold">{{ trans('enotf.hospital.settings_title', { name: hospital.name }) }}</h1>
        <a href="/settings/pois" class="inline-flex min-h-11 items-center text-accent">{{ trans('enotf.hospital.back_to_pois') }}</a>

        <section class="space-y-2 rounded-md border border-border p-4" aria-labelledby="access-code-title">
            <h2 id="access-code-title" class="text-lg font-semibold">{{ trans('enotf.hospital.code_title') }}</h2>
            <p class="text-sm text-text-2">
                {{ access_code ? trans('enotf.hospital.code_set', { at: access_code.rotated_at ?? '', used: access_code.last_used_at ?? '–' }) : trans('enotf.hospital.code_none') }}
            </p>
            <div class="flex flex-wrap gap-2">
                <Button type="button" @click="rotate">{{ access_code ? trans('enotf.hospital.code_rotate') : trans('enotf.hospital.code_create') }}</Button>
                <Button v-if="access_code" type="button" variant="ghost" @click="revoke">{{ trans('enotf.hospital.code_revoke') }}</Button>
            </div>
            <div v-if="code" role="status" class="rounded-md border border-accent p-3">
                <p class="text-sm">{{ trans('enotf.hospital.code_once') }}</p>
                <p class="font-mono text-xl tracking-wider select-all">{{ code }}</p>
            </div>
            <p v-if="codeMessage" role="alert" class="text-sm text-danger">{{ codeMessage }}</p>
        </section>

        <form class="flex flex-wrap items-end gap-3 rounded-md border border-border p-4" @submit.prevent="submit">
            <label class="text-sm text-text-2">{{ trans('enotf.hospital.department') }} <Input v-model="form.name" required maxlength="100" class="w-56" /></label>
            <label class="text-sm text-text-2">{{ trans('enotf.settings.sort') }} <Input v-model.number="form.sort_order" type="number" min="0" max="9999" class="w-24" /></label>
            <template v-if="editing !== null">
                <label class="text-sm text-text-2"
                    >{{ trans('enotf.hospital.status_label') }}
                    <select v-model="form.status" class="block min-h-9 rounded-md border border-border bg-bg px-2">
                        <option v-for="s in statuses" :key="s" :value="s">{{ trans(`enotf.hospital.status.${s}`) }}</option>
                    </select>
                </label>
                <label class="text-sm text-text-2">{{ trans('enotf.hospital.note') }} <Input v-model="form.note" maxlength="255" class="w-56" /></label>
            </template>
            <Button type="submit" :disabled="form.processing">{{ trans('enotf.settings.save') }}</Button>
            <Button v-if="editing !== null" type="button" variant="ghost" @click="reset">{{ trans('enotf.settings.cancel') }}</Button>
            <p v-for="(m, k) in form.errors" :key="k" role="alert" class="w-full text-sm text-danger">{{ m }}</p>
        </form>

        <ul class="divide-y divide-border rounded-lg border border-border bg-card shadow-(--shadow)">
            <li v-for="d in departments" :key="d.id" class="flex flex-wrap items-center gap-3 px-3 py-2">
                <span class="font-medium">{{ d.name }}</span>
                <DepartmentStatus :status="d.status" />
                <span class="text-xs text-text-2">{{ [d.note, d.updated_at, d.updated_by].filter(Boolean).join(' · ') }}</span>
                <span class="ml-auto flex gap-1">
                    <Button variant="ghost" size="sm" @click="edit(d)">{{ trans('enotf.settings.edit') }}</Button>
                    <Button variant="ghost" size="sm" @click="remove(d)">{{ trans('enotf.settings.delete') }}</Button>
                </span>
            </li>
            <li v-if="!departments.length" class="px-3 py-6 text-center text-text-2">{{ trans('enotf.hospital.no_departments') }}</li>
        </ul>
    </div>
</template>
