<script setup lang="ts">
import { ref, watch } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

export type Person = { id: number | null; name: string };
export type IncidentData = {
    id: number;
    incident_number: string;
    location: string;
    reason: string | null;
    notes: string | null;
    status: string;
    started_at: string;
    lna: Person | null;
    orgl: Person | null;
    writable: boolean;
};

// Create (incident null) or edit. LNA/OrgL are picked from personnel files; the name is set by the server.
const props = defineProps<{ incident: IncidentData | null; statuses?: string[] }>();

const writable = props.incident?.writable ?? true;
const form = useForm({
    incident_number: props.incident?.incident_number ?? '',
    location: props.incident?.location ?? '',
    reason: props.incident?.reason ?? '',
    notes: props.incident?.notes ?? '',
    started_at: props.incident?.started_at ?? '',
    status: props.incident?.status ?? 'active',
    lna_personnel_id: props.incident?.lna?.id ?? (null as number | null),
    orgl_personnel_id: props.incident?.orgl?.id ?? (null as number | null),
});
const names = ref<{ lna: string | null; orgl: string | null }>({ lna: props.incident?.lna?.name ?? null, orgl: props.incident?.orgl?.name ?? null });

form.transform((d) => (writable ? { ...d, reason: d.reason || null, notes: d.notes || null, started_at: d.started_at || null, ...(props.incident ? {} : { status: undefined }) } : { status: d.status }));

function submit() {
    if (props.incident) form.put(`/mci/${props.incident.id}`);
    else form.post('/mci');
}

const error = (key: string) => (form.errors as Record<string, string | undefined>)[key];
const label = 'block text-sm font-medium';
const select = 'mt-1 block h-9 w-full rounded-md border border-border bg-card px-3 text-sm';

type Hit = { id: number; full_name: string; service_number: string };
function useSearch(role: 'lna' | 'orgl') {
    const q = ref('');
    const hits = ref<Hit[]>([]);
    let timer: ReturnType<typeof setTimeout> | undefined;
    watch(q, (value) => {
        clearTimeout(timer);
        if (value.trim() === '') {
            hits.value = [];
            return;
        }
        timer = setTimeout(async () => {
            try {
                const res = await fetch(`/mci/personnel?q=${encodeURIComponent(value.trim())}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                hits.value = res.ok ? ((await res.json()) as Hit[]) : [];
            } catch {
                hits.value = [];
            }
        }, 250);
    });
    function pick(h: Hit) {
        form[`${role}_personnel_id`] = h.id;
        names.value[role] = h.full_name;
        q.value = '';
        hits.value = [];
    }
    function clear() {
        form[`${role}_personnel_id`] = null;
        names.value[role] = null;
    }

    return { q, hits, pick, clear };
}
const people = { lna: useSearch('lna'), orgl: useSearch('orgl') };
</script>

<template>
    <form class="mx-auto w-full max-w-3xl space-y-5" @submit.prevent="submit">
        <h1 class="text-2xl font-semibold">{{ trans(incident ? 'manvboard.form.edit_title' : 'manvboard.form.new_title') }}</h1>
        <p v-if="!writable" class="rounded-md bg-(--fill-3) px-3 py-2 text-sm text-text-2" data-test="readonly">{{ trans('manvboard.form.readonly') }}</p>

        <div v-if="writable" class="grid gap-4 sm:grid-cols-2">
            <div>
                <label :class="label" for="mci-number">{{ trans('manvboard.form.number') }}</label>
                <Input id="mci-number" v-model="form.incident_number" required maxlength="50" class="mt-1" />
                <p v-if="error('incident_number')" class="mt-1 text-sm text-danger" role="alert">{{ error('incident_number') }}</p>
            </div>
            <div>
                <label :class="label" for="mci-location">{{ trans('manvboard.form.location') }}</label>
                <Input id="mci-location" v-model="form.location" required maxlength="255" class="mt-1" />
                <p v-if="error('location')" class="mt-1 text-sm text-danger" role="alert">{{ error('location') }}</p>
            </div>
            <div>
                <label :class="label" for="mci-started">{{ trans('manvboard.form.started') }}</label>
                <Input id="mci-started" v-model="form.started_at" type="datetime-local" class="mt-1" />
                <p v-if="error('started_at')" class="mt-1 text-sm text-danger" role="alert">{{ error('started_at') }}</p>
            </div>
            <div class="sm:col-span-2">
                <label :class="label" for="mci-reason">{{ trans('manvboard.form.reason') }}</label>
                <textarea id="mci-reason" v-model="form.reason" rows="3" class="mt-1 block w-full rounded-md border border-border bg-card px-3 py-2 text-sm" />
                <p v-if="error('reason')" class="mt-1 text-sm text-danger" role="alert">{{ error('reason') }}</p>
            </div>
            <div class="sm:col-span-2">
                <label :class="label" for="mci-notes">{{ trans('manvboard.form.notes') }}</label>
                <textarea id="mci-notes" v-model="form.notes" rows="3" class="mt-1 block w-full rounded-md border border-border bg-card px-3 py-2 text-sm" />
                <p v-if="error('notes')" class="mt-1 text-sm text-danger" role="alert">{{ error('notes') }}</p>
            </div>

            <fieldset v-for="role in (['lna', 'orgl'] as const)" :key="role" class="space-y-1">
                <legend :class="label">{{ trans(`manvboard.form.${role}`) }}</legend>
                <p v-if="names[role]" class="flex items-center gap-2 text-sm">
                    <span class="rounded-full bg-(--fill-3) px-2 py-0.5">{{ names[role] }}</span>
                    <Button type="button" variant="ghost" size="sm" @click="people[role].clear()">{{ trans('manvboard.form.clear') }}</Button>
                </p>
                <Input v-model="people[role].q.value" :placeholder="trans('manvboard.form.search')" :aria-label="trans(`manvboard.form.${role}`)" autocomplete="off" />
                <ul v-if="people[role].hits.value.length" class="rounded-md border border-border bg-card text-sm shadow-(--shadow)">
                    <li v-for="h in people[role].hits.value" :key="h.id">
                        <button type="button" class="block w-full px-3 py-1.5 text-left hover:bg-(--fill-3)" @click="people[role].pick(h)">{{ h.full_name }} <span class="text-text-2">· {{ h.service_number }}</span></button>
                    </li>
                </ul>
                <p v-if="error(`${role}_personnel_id`)" class="text-sm text-danger" role="alert">{{ error(`${role}_personnel_id`) }}</p>
            </fieldset>
        </div>

        <div v-if="incident">
            <label :class="label" for="mci-status">{{ trans('manvboard.form.status') }}</label>
            <select id="mci-status" v-model="form.status" :class="select">
                <option v-for="s in statuses ?? []" :key="s" :value="s">{{ trans(`manvboard.status.${s}`) }}</option>
            </select>
            <p v-if="error('status')" class="mt-1 text-sm text-danger" role="alert">{{ error('status') }}</p>
        </div>

        <div class="flex gap-2">
            <Button type="submit" :disabled="form.processing">{{ trans('manvboard.form.save') }}</Button>
            <Button as-child variant="ghost"><Link href="/mci">{{ trans('manvboard.form.cancel') }}</Link></Button>
        </div>
    </form>
</template>
