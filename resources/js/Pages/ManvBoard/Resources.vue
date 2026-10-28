<script setup lang="ts">
import { ref } from 'vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type Resource = {
    id: number;
    type: string;
    label: string;
    callsign: string | null;
    vehicle_type: string | null;
    location: string | null;
    status: string;
    crew: string | null;
    notes: string | null;
    departed: boolean;
    on_site: number;
};
type Stock = { id: number; name: string; identifier: string; type: string };

const props = defineProps<{
    incident: { id: number; incident_number: string; status: string };
    resources: Resource[];
    stock: Stock[];
    search: string;
    statuses: string[];
    types: string[];
    can: { write: boolean };
}>();

const base = `/mci/${props.incident.id}/resources`;
const errors = () => usePage().props.errors as Record<string, string | undefined>;
const select = 'mt-1 block h-9 w-full rounded-md border border-border bg-card px-3 text-sm';
const label = 'block text-sm font-medium';

const q = ref(props.search);
const find = () => router.get(base, q.value ? { search: q.value } : {}, { preserveState: true, preserveScroll: true, replace: true });
const addStock = (v: Stock) => router.post(base, { vehicle_id: v.id }, { preserveScroll: true });

const quick = useForm({ type: 'vehicle', label: '', callsign: '', vehicle_type: '', location: '', status: 'available', crew: '', notes: '' });
const quickErr = (k: string) => (quick.errors as Record<string, string | undefined>)[k];
function addQuick() {
    quick.transform((d) => ({ ...d, callsign: d.callsign || null, vehicle_type: d.vehicle_type || null, location: d.location || null, crew: d.crew || null, notes: d.notes || null }))
        .post(base, { preserveScroll: true, onSuccess: () => quick.reset() });
}

const editing = ref<number | null>(null);
const edit = useForm({ label: '', callsign: '', vehicle_type: '', location: '', status: 'available', crew: '', notes: '' });
const editErr = (k: string) => (edit.errors as Record<string, string | undefined>)[k];
function open(r: Resource) {
    edit.clearErrors();
    edit.defaults({ label: r.label, callsign: r.callsign ?? '', vehicle_type: r.vehicle_type ?? '', location: r.location ?? '', status: r.status, crew: r.crew ?? '', notes: r.notes ?? '' }).reset();
    editing.value = r.id;
}
function save(r: Resource) {
    edit.transform((d) => ({ ...d, callsign: d.callsign || null, vehicle_type: d.vehicle_type || null, location: d.location || null, crew: d.crew || null, notes: d.notes || null }))
        .put(`${base}/${r.id}`, { preserveScroll: true, onSuccess: () => (editing.value = null) });
}
function remove(r: Resource) {
    if (confirm(trans('manvboard.resources.delete_confirm', { label: r.label }))) router.delete(`${base}/${r.id}`, { preserveScroll: true });
}
</script>

<template>
    <div class="space-y-5">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <p class="text-sm text-text-2">{{ incident.incident_number }}</p>
                <h1 class="text-2xl font-semibold">{{ trans('manvboard.resources.title') }}</h1>
            </div>
            <Button as-child variant="ghost"><Link :href="`/mci/${incident.id}`">{{ trans('manvboard.resources.to_board') }}</Link></Button>
        </div>

        <p v-if="!can.write" class="rounded-md bg-(--fill-3) px-3 py-2 text-sm text-text-2">{{ trans('manvboard.resources.readonly') }}</p>
        <p v-if="errors().delete" class="rounded-md bg-danger/10 px-3 py-2 text-sm text-danger" role="alert">{{ errors().delete }}</p>
        <p v-if="errors().vehicle_id || errors().label" class="rounded-md bg-danger/10 px-3 py-2 text-sm text-danger" role="alert">{{ errors().vehicle_id ?? errors().label }}</p>

        <div v-if="can.write" class="grid gap-4 lg:grid-cols-2">
            <section class="rounded-lg border border-border bg-card p-4 shadow-(--shadow)">
                <h2 class="mb-3 font-semibold">{{ trans('manvboard.resources.stock') }}</h2>
                <form class="flex gap-2" @submit.prevent="find">
                    <Input v-model="q" type="search" maxlength="100" :aria-label="trans('manvboard.resources.stock_search')" :placeholder="trans('manvboard.resources.stock_search')" />
                    <Button type="submit" variant="secondary">{{ trans('manvboard.resources.search') }}</Button>
                </form>
                <ul v-if="stock.length" class="mt-3 divide-y divide-border">
                    <li v-for="v in stock" :key="v.id" class="flex items-center justify-between gap-2 py-2 text-sm">
                        <span>{{ v.name }} <span class="text-text-2">· {{ v.identifier }} · {{ v.type }}</span></span>
                        <Button size="sm" @click="addStock(v)">{{ trans('manvboard.resources.add') }}</Button>
                    </li>
                </ul>
                <p v-else class="mt-3 text-sm text-text-2">{{ trans('manvboard.resources.stock_empty') }}</p>
            </section>

            <form class="rounded-lg border border-border bg-card p-4 shadow-(--shadow)" @submit.prevent="addQuick">
                <h2 class="mb-3 font-semibold">{{ trans('manvboard.resources.quick') }}</h2>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label :class="label" for="q-label">{{ trans('manvboard.resources.label') }}</label>
                        <Input id="q-label" v-model="quick.label" maxlength="255" required class="mt-1" />
                        <p v-if="quickErr('label')" class="mt-1 text-sm text-danger" role="alert">{{ quickErr('label') }}</p>
                    </div>
                    <div>
                        <label :class="label" for="q-type">{{ trans('manvboard.resources.type') }}</label>
                        <select id="q-type" v-model="quick.type" :class="select">
                            <option v-for="t in types" :key="t" :value="t">{{ trans(`manvboard.resource_type.${t}`) }}</option>
                        </select>
                        <p v-if="quickErr('type')" class="mt-1 text-sm text-danger" role="alert">{{ quickErr('type') }}</p>
                    </div>
                    <div>
                        <label :class="label" for="q-loc">{{ trans('manvboard.resources.location') }}</label>
                        <Input id="q-loc" v-model="quick.location" maxlength="255" class="mt-1" />
                    </div>
                    <div>
                        <label :class="label" for="q-crew">{{ trans('manvboard.resources.crew') }}</label>
                        <Input id="q-crew" v-model="quick.crew" maxlength="255" class="mt-1" />
                    </div>
                </div>
                <Button type="submit" class="mt-3" :disabled="quick.processing">{{ trans('manvboard.resources.add') }}</Button>
            </form>
        </div>

        <h2 class="text-lg font-semibold">{{ trans('manvboard.resources.list') }}</h2>
        <p v-if="!resources.length" class="text-sm text-text-2">{{ trans('manvboard.resources.empty') }}</p>
        <ul class="space-y-2">
            <li v-for="r in resources" :key="r.id" class="rounded-lg border border-border bg-card p-3 text-sm">
                <template v-if="editing !== r.id">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <p class="font-semibold">{{ r.label }}<span v-if="r.departed" class="ml-2 text-xs font-normal text-text-2">({{ trans('manvboard.resources.departed') }})</span></p>
                            <p class="text-text-2">
                                {{ trans(`manvboard.resource_type.${r.type}`) }} · {{ trans(`manvboard.resource_status.${r.status}`) }} ·
                                {{ trans('manvboard.resources.on_site', { count: String(r.on_site) }) }}
                                <template v-if="r.callsign"> · {{ r.callsign }}</template><template v-if="r.vehicle_type"> · {{ r.vehicle_type }}</template>
                                <template v-if="r.location"> · {{ r.location }}</template><template v-if="r.crew"> · {{ r.crew }}</template>
                            </p>
                            <p v-if="r.notes" class="text-text-2">{{ r.notes }}</p>
                        </div>
                        <div v-if="can.write" class="flex gap-2">
                            <Button size="sm" variant="secondary" @click="open(r)">{{ trans('manvboard.edit') }}</Button>
                            <Button size="sm" variant="ghost" @click="remove(r)">{{ trans('manvboard.resources.delete') }}</Button>
                        </div>
                    </div>
                </template>
                <form v-else class="space-y-3" @submit.prevent="save(r)">
                    <div class="grid gap-3 sm:grid-cols-3">
                        <div>
                            <label :class="label" :for="`e-label-${r.id}`">{{ trans('manvboard.resources.label') }}</label>
                            <Input :id="`e-label-${r.id}`" v-model="edit.label" maxlength="255" required class="mt-1" />
                            <p v-if="editErr('label')" class="mt-1 text-sm text-danger" role="alert">{{ editErr('label') }}</p>
                        </div>
                        <div>
                            <label :class="label" :for="`e-call-${r.id}`">{{ trans('manvboard.resources.callsign') }}</label>
                            <Input :id="`e-call-${r.id}`" v-model="edit.callsign" maxlength="255" class="mt-1" />
                        </div>
                        <div>
                            <label :class="label" :for="`e-vt-${r.id}`">{{ trans('manvboard.resources.vehicle_type') }}</label>
                            <Input :id="`e-vt-${r.id}`" v-model="edit.vehicle_type" maxlength="255" class="mt-1" />
                        </div>
                        <div>
                            <label :class="label" :for="`e-st-${r.id}`">{{ trans('manvboard.resources.status') }}</label>
                            <select :id="`e-st-${r.id}`" v-model="edit.status" :class="select">
                                <option v-for="s in statuses" :key="s" :value="s">{{ trans(`manvboard.resource_status.${s}`) }}</option>
                            </select>
                            <p v-if="editErr('status')" class="mt-1 text-sm text-danger" role="alert">{{ editErr('status') }}</p>
                        </div>
                        <div>
                            <label :class="label" :for="`e-loc-${r.id}`">{{ trans('manvboard.resources.location') }}</label>
                            <Input :id="`e-loc-${r.id}`" v-model="edit.location" maxlength="255" class="mt-1" />
                        </div>
                        <div>
                            <label :class="label" :for="`e-crew-${r.id}`">{{ trans('manvboard.resources.crew') }}</label>
                            <Input :id="`e-crew-${r.id}`" v-model="edit.crew" maxlength="255" class="mt-1" />
                        </div>
                    </div>
                    <div>
                        <label :class="label" :for="`e-notes-${r.id}`">{{ trans('manvboard.resources.notes') }}</label>
                        <textarea :id="`e-notes-${r.id}`" v-model="edit.notes" rows="2" class="mt-1 block w-full rounded-md border border-border bg-card px-3 py-2 text-sm" />
                    </div>
                    <div class="flex gap-2">
                        <Button type="submit" size="sm" :disabled="edit.processing">{{ trans('manvboard.form.save') }}</Button>
                        <Button type="button" size="sm" variant="ghost" @click="editing = null">{{ trans('manvboard.form.cancel') }}</Button>
                    </div>
                </form>
            </li>
        </ul>
    </div>
</template>
