<script setup lang="ts">
/** Clinic portal: the availability of the own hospital's departments (clinic device). Plain text only. */
import { trans } from 'laravel-vue-i18n';
import { ref } from 'vue';
import DepartmentStatus from '@/components/enotf/DepartmentStatus.vue';
import PortalNav from '@/components/enotf/PortalNav.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { xsrfToken } from '@/lib/draftSave';

defineOptions({ layout: GuestLayout });

type Department = { id: number; name: string; status: string; note: string | null; updated_at: string | null; updated_by: string | null };
const props = defineProps<{ hospital: { id: number; name: string }; departments: Department[]; statuses: string[] }>();

const rows = ref(props.departments.map((d) => ({ ...d, draft: { status: d.status, note: d.note ?? '' }, message: '' })));

async function save(row: (typeof rows.value)[number]) {
    row.message = '';
    const res = await fetch(`/enotf/schnittstelle/availability/${row.id}`, {
        method: 'PUT',
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrfToken() },
        body: JSON.stringify(row.draft),
    }).catch(() => null);
    if (res?.status === 403) {
        location.assign('/enotf/schnittstelle/login');
        return;
    }
    const body = (await res?.json().catch(() => null)) as Department | null;
    if (res?.ok && body) Object.assign(row, body, { message: trans('enotf.portal.saved') });
    else row.message = trans('enotf.portal.save_failed');
}
</script>

<template>
    <div class="mx-auto max-w-4xl space-y-4">
        <PortalNav :hospital="hospital.name" current="availability" />
        <p v-if="!rows.length" class="text-text-2">{{ trans('enotf.hospital.no_departments') }}</p>
        <section v-for="row in rows" :key="row.id" class="space-y-2 rounded-md border border-border p-3" :aria-labelledby="`dept-${row.id}`">
            <div class="flex flex-wrap items-center gap-3">
                <h2 :id="`dept-${row.id}`" class="text-lg font-semibold">{{ row.name }}</h2>
                <DepartmentStatus :status="row.status" />
                <span v-if="row.updated_at" class="text-sm text-text-2">{{ row.updated_at }} · {{ row.updated_by }}</span>
            </div>
            <fieldset class="flex flex-wrap gap-2">
                <legend class="sr-only">{{ trans('enotf.hospital.status_label') }}</legend>
                <label v-for="s in statuses" :key="s" class="inline-flex min-h-11 cursor-pointer items-center gap-2 rounded-md border border-border px-3">
                    <input v-model="row.draft.status" type="radio" :name="`status-${row.id}`" :value="s" />
                    {{ trans(`enotf.hospital.status.${s}`) }}
                </label>
            </fieldset>
            <div class="flex flex-wrap items-end gap-2">
                <label class="flex-1 text-sm"
                    >{{ trans('enotf.hospital.note') }}
                    <input v-model="row.draft.note" maxlength="255" class="min-h-11 w-full rounded-md border border-border bg-bg px-2" />
                </label>
                <button type="button" class="min-h-11 rounded-md bg-accent px-4 text-white" @click="save(row)">{{ trans('enotf.portal.save') }}</button>
            </div>
            <p role="status" class="text-sm text-text-2">{{ row.message }}</p>
        </section>
    </div>
</template>
