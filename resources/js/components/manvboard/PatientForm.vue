<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

export type Vehicle = { id: number; label: string; vehicle_type: string | null; location: string | null };
export type Hospital = { id: number; name: string; city: string | null };
export type PatientData = {
    id: number;
    last_name: string | null;
    first_name: string | null;
    birth_date: string | null;
    sex: string;
    triage: string | null;
    manv_resource_id: number | null;
    destination: string | null;
    destination_poi_id: number | null;
    injuries: string | null;
    notes: string | null;
};

// Create (patient null) or edit. The destination select encodes a hospital as `poi:<id>`; the server sets its name.
const props = defineProps<{
    incidentId: number;
    patient: PatientData | null;
    vehicles: Vehicle[];
    hospitals: Hospital[];
    triages: string[];
    sexes: string[];
    noTransport: string;
    readonly: boolean;
    frozen?: boolean;
}>();

const p = props.patient;
const freeText = p?.destination && p.destination_poi_id === null && p.destination !== props.noTransport ? p.destination : null;
const form = useForm({
    last_name: p?.last_name ?? '',
    first_name: p?.first_name ?? '',
    birth_date: p?.birth_date ?? '',
    sex: p?.sex ?? 'unknown',
    triage: p?.triage ?? '',
    manv_resource_id: p?.manv_resource_id ?? ('' as number | ''),
    target: p?.destination_poi_id ? `poi:${p.destination_poi_id}` : p?.destination === props.noTransport ? 'none' : freeText !== null ? 'text' : '',
    injuries: p?.injuries ?? '',
    notes: p?.notes ?? '',
});

form.transform(({ target, ...d }) => ({
    ...d,
    last_name: d.last_name || null,
    first_name: d.first_name || null,
    birth_date: d.birth_date || null,
    triage: d.triage || null,
    manv_resource_id: d.manv_resource_id || null,
    injuries: d.injuries || null,
    notes: d.notes || null,
    destination_poi_id: target.startsWith('poi:') ? Number(target.slice(4)) : null,
    destination: target === 'none' ? props.noTransport : target === 'text' ? freeText : null,
}));

function submit() {
    if (props.patient) form.put(`/mci/${props.incidentId}/patients/${props.patient.id}`, { preserveScroll: true });
    else form.post(`/mci/${props.incidentId}/patients`);
}

const error = (key: string) => (form.errors as Record<string, string | undefined>)[key];
const label = 'block text-sm font-medium';
const select = 'mt-1 block h-9 w-full rounded-md border border-border bg-card px-3 text-sm';
const area = 'mt-1 block w-full rounded-md border border-border bg-card px-3 py-2 text-sm';
</script>

<template>
    <form class="space-y-5" @submit.prevent="submit">
        <fieldset :disabled="readonly" class="space-y-5">
            <section class="rounded-lg border border-border bg-card p-4 shadow-(--shadow)">
                <h2 class="mb-3 font-semibold">{{ trans('manvboard.patient.personal') }}</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label :class="label" for="pt-last">{{ trans('manvboard.patient.last_name') }}</label>
                        <Input id="pt-last" v-model="form.last_name" maxlength="255" class="mt-1" />
                        <p v-if="error('last_name')" class="mt-1 text-sm text-danger" role="alert">{{ error('last_name') }}</p>
                    </div>
                    <div>
                        <label :class="label" for="pt-first">{{ trans('manvboard.patient.first_name') }}</label>
                        <Input id="pt-first" v-model="form.first_name" maxlength="255" class="mt-1" />
                        <p v-if="error('first_name')" class="mt-1 text-sm text-danger" role="alert">{{ error('first_name') }}</p>
                    </div>
                    <div>
                        <label :class="label" for="pt-birth">{{ trans('manvboard.patient.birth_date') }}</label>
                        <Input id="pt-birth" v-model="form.birth_date" type="date" class="mt-1" />
                        <p v-if="error('birth_date')" class="mt-1 text-sm text-danger" role="alert">{{ error('birth_date') }}</p>
                    </div>
                    <div>
                        <label :class="label" for="pt-sex">{{ trans('manvboard.patient.sex') }}</label>
                        <select id="pt-sex" v-model="form.sex" :class="select">
                            <option v-for="s in sexes" :key="s" :value="s">{{ trans(`manvboard.sex.${s}`) }}</option>
                        </select>
                        <p v-if="error('sex')" class="mt-1 text-sm text-danger" role="alert">{{ error('sex') }}</p>
                    </div>
                </div>
            </section>

            <section v-if="!patient" class="rounded-lg border border-border bg-card p-4 shadow-(--shadow)">
                <label :class="label" for="pt-triage">{{ trans('manvboard.patient.triage') }}</label>
                <select id="pt-triage" v-model="form.triage" :class="select" required>
                    <option value="">{{ trans('manvboard.patient.triage_choose') }}</option>
                    <option v-for="t in triages" :key="t" :value="t">{{ trans(`manvboard.triage.${t}`) }} – {{ trans(`manvboard.triage_name.${t}`) }}</option>
                </select>
                <p v-if="error('triage')" class="mt-1 text-sm text-danger" role="alert">{{ error('triage') }}</p>
            </section>

            <section class="rounded-lg border border-border bg-card p-4 shadow-(--shadow)">
                <h2 class="mb-3 font-semibold">{{ trans('manvboard.patient.transport') }}</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label :class="label" for="pt-vehicle">{{ trans('manvboard.patient.vehicle') }}</label>
                        <select id="pt-vehicle" v-model="form.manv_resource_id" :class="select" :disabled="frozen">
                            <option value="">{{ trans('manvboard.patient.vehicle_none') }}</option>
                            <option v-for="v in vehicles" :key="v.id" :value="v.id">{{ v.label }}<template v-if="v.vehicle_type"> ({{ v.vehicle_type }})</template></option>
                        </select>
                        <p v-if="error('manv_resource_id')" class="mt-1 text-sm text-danger" role="alert">{{ error('manv_resource_id') }}</p>
                    </div>
                    <div>
                        <label :class="label" for="pt-target">{{ trans('manvboard.patient.destination') }}</label>
                        <select id="pt-target" v-model="form.target" :class="select" :disabled="frozen">
                            <option value="">{{ trans('manvboard.patient.destination_none') }}</option>
                            <option value="none">{{ trans('manvboard.patient.no_transport') }}</option>
                            <option v-if="freeText !== null" value="text">{{ freeText }}</option>
                            <option v-for="h in hospitals" :key="h.id" :value="`poi:${h.id}`">{{ h.name }}<template v-if="h.city"> ({{ h.city }})</template></option>
                        </select>
                        <p v-if="error('destination_poi_id') || error('destination')" class="mt-1 text-sm text-danger" role="alert">
                            {{ error('destination_poi_id') ?? error('destination') }}
                        </p>
                    </div>
                </div>
            </section>

            <section class="rounded-lg border border-border bg-card p-4 shadow-(--shadow)">
                <label :class="label" for="pt-injuries">{{ trans('manvboard.patient.injuries') }}</label>
                <textarea id="pt-injuries" v-model="form.injuries" rows="3" :class="area" />
                <p v-if="error('injuries')" class="mt-1 text-sm text-danger" role="alert">{{ error('injuries') }}</p>
                <label :class="[label, 'mt-4']" for="pt-notes">{{ trans('manvboard.patient.notes') }}</label>
                <textarea id="pt-notes" v-model="form.notes" rows="2" :class="area" />
                <p v-if="error('notes')" class="mt-1 text-sm text-danger" role="alert">{{ error('notes') }}</p>
            </section>
        </fieldset>

        <div class="flex gap-2">
            <Button v-if="!readonly" type="submit" :disabled="form.processing">{{ trans('manvboard.form.save') }}</Button>
            <Button as-child variant="ghost"><Link :href="`/mci/${incidentId}`">{{ trans('manvboard.patient.back') }}</Link></Button>
        </div>
    </form>
</template>
