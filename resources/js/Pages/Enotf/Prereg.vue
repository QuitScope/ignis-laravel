<script setup lang="ts">
/** GET /enotf/p/{enr}/prereg: hand the patient over to a hospital: clinic code (shown once) and pre-registration. Crew only. */
import { router, useForm } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { ref } from 'vue';
import TabletLayout from '@/Layouts/TabletLayout.vue';
import { xsrfToken } from '@/lib/draftSave';

defineOptions({ layout: TabletLayout });

const props = defineProps<{
    protocol: { enr: string; vehicles: string[] };
    prereg_enabled: boolean;
    hospitals: { id: number; name: string }[];
    preregistrations: { id: number; hospital: string | null; status: string; priority: number; eta: string | null; note: string | null; created_at: string | null }[];
}>();

const base = `/enotf/p/${encodeURIComponent(props.protocol.enr)}`;

// Clinic code: the plain code exists only in this answer.
const code = ref<{ code: string; expires_at: string } | null>(null);
const codeError = ref('');
async function makeCode() {
    codeError.value = '';
    const res = await fetch(`${base}/clinic-code`, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrfToken() },
    }).catch(() => null);
    const body = (await res?.json().catch(() => null)) as { code?: string; expires_at?: string } | null;
    if (res?.ok && body?.code) code.value = { code: body.code, expires_at: body.expires_at ?? '' };
    else codeError.value = trans('enotf.clinic_code.failed');
}

const form = useForm({ poi_id: null as number | null, priority: 0, eta_minutes: 10 as number | '', note: '' });
const submit = () =>
    form
        .transform((d) => ({ ...d, eta_minutes: d.eta_minutes === '' ? null : d.eta_minutes }))
        .post(`${base}/prereg`, { preserveScroll: true, onSuccess: () => form.reset() });
const cancel = (id: number) => {
    if (window.confirm(trans('enotf.prereg.cancel_confirm'))) router.delete(`${base}/prereg/${id}`, { preserveScroll: true });
};
</script>

<template>
    <div class="mx-auto max-w-3xl space-y-4">
        <header class="flex flex-wrap items-center justify-between gap-2">
            <h1 class="text-2xl font-semibold">{{ trans('enotf.prereg.title', { enr: protocol.enr }) }}</h1>
            <a :href="base" class="inline-flex min-h-11 items-center text-accent">{{ trans('enotf.protocol.back') }}</a>
        </header>

        <section class="space-y-2 rounded-md border border-border p-3" aria-labelledby="clinic-code-title">
            <h2 id="clinic-code-title" class="text-lg font-semibold">{{ trans('enotf.clinic_code.title') }}</h2>
            <p class="text-sm text-text-2">{{ trans('enotf.clinic_code.lead') }}</p>
            <button type="button" class="min-h-11 rounded-md border border-border px-4" @click="makeCode">{{ trans('enotf.clinic_code.create') }}</button>
            <div v-if="code" role="status" class="rounded-md border border-accent p-3">
                <p class="font-mono text-2xl tracking-wider select-all">{{ code.code }}</p>
                <p class="text-sm text-text-2">{{ trans('enotf.clinic_code.valid_until', { at: code.expires_at }) }}</p>
            </div>
            <p v-if="codeError" role="alert" class="text-sm text-danger">{{ codeError }}</p>
        </section>

        <section v-if="prereg_enabled" class="space-y-3 rounded-md border border-border p-3" aria-labelledby="prereg-title">
            <h2 id="prereg-title" class="text-lg font-semibold">{{ trans('enotf.prereg.form_title') }}</h2>
            <form class="grid gap-3 sm:grid-cols-2" @submit.prevent="submit">
                <label class="text-sm"
                    >{{ trans('enotf.portal.hospital') }}
                    <select v-model="form.poi_id" required class="block min-h-11 w-full rounded-md border border-border bg-bg px-2">
                        <option :value="null" disabled>{{ trans('enotf.portal.pick_hospital') }}</option>
                        <option v-for="h in hospitals" :key="h.id" :value="h.id">{{ h.name }}</option>
                    </select>
                </label>
                <label class="text-sm"
                    >{{ trans('enotf.prereg.priority') }}
                    <select v-model.number="form.priority" class="block min-h-11 w-full rounded-md border border-border bg-bg px-2">
                        <option v-for="p in [0, 1, 2]" :key="p" :value="p">{{ trans(`enotf.prereg.priority_${p}`) }}</option>
                    </select>
                </label>
                <label class="text-sm"
                    >{{ trans('enotf.prereg.eta_minutes') }}
                    <input v-model.number="form.eta_minutes" type="number" min="0" max="240" inputmode="numeric" class="block min-h-11 w-full rounded-md border border-border bg-bg px-2" />
                </label>
                <label class="text-sm"
                    >{{ trans('enotf.prereg.note') }}
                    <input v-model="form.note" maxlength="255" class="block min-h-11 w-full rounded-md border border-border bg-bg px-2" />
                </label>
                <p class="text-xs text-text-2 sm:col-span-2">{{ trans('enotf.prereg.discord_hint') }}</p>
                <p v-for="(m, k) in form.errors" :key="k" role="alert" class="text-sm text-danger sm:col-span-2">{{ m }}</p>
                <div class="sm:col-span-2">
                    <button type="submit" class="min-h-11 rounded-md bg-accent px-4 text-white" :disabled="form.processing">{{ trans('enotf.prereg.send') }}</button>
                </div>
            </form>
            <ul v-if="preregistrations.length" class="divide-y divide-border">
                <li v-for="r in preregistrations" :key="r.id" class="flex flex-wrap items-center gap-3 py-2">
                    <span class="font-medium">{{ r.hospital }}</span>
                    <span class="text-sm">{{ trans(`enotf.prereg.status.${r.status}`) }}</span>
                    <span class="text-sm text-text-2">{{ [trans(`enotf.prereg.priority_${r.priority}`), r.eta, r.note].filter(Boolean).join(' · ') }}</span>
                    <button v-if="r.status === 'open' || r.status === 'seen'" type="button" class="ml-auto min-h-11 rounded-md border border-danger px-3 text-danger" @click="cancel(r.id)">
                        {{ trans('enotf.prereg.cancel') }}
                    </button>
                </li>
            </ul>
        </section>
        <p v-else class="text-text-2">{{ trans('enotf.prereg.disabled') }}</p>
    </div>
</template>
