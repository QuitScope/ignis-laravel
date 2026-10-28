<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { ref } from 'vue';
import TabletLayout from '@/Layouts/TabletLayout.vue';

defineOptions({ layout: TabletLayout });

type Entry = {
    id: number;
    trip_date: string;
    departed_at: string;
    arrived_at: string | null;
    distance_km: number;
    reason: string | null;
    trip_type: string;
    driver_name: string;
    editable: boolean;
};

const props = defineProps<{
    vehicle: { identifier: string; name: string };
    formKey: string;
    today: string | null;
    types: string[];
    entries: Entry[];
}>();

const editing = ref<number | null>(null);
const form = useForm({
    key: props.formKey,
    trip_date: props.today ?? '',
    departed_at: '',
    arrived_at: '',
    distance_km: '',
    trip_type: 'einsatz',
    reason: '',
});

function edit(e: Entry) {
    editing.value = e.id;
    form.trip_date = e.trip_date;
    form.departed_at = e.departed_at;
    form.arrived_at = e.arrived_at ?? '';
    form.distance_km = String(e.distance_km);
    form.trip_type = e.trip_type;
    form.reason = e.reason ?? '';
}

function reset() {
    editing.value = null;
    form.reset();
    form.clearErrors();
    form.key = props.formKey;
}

function submit() {
    if (form.processing) return;
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            reset();
        },
    };
    if (editing.value === null) form.post('/firetab/logbook', options);
    else form.put(`/firetab/logbook/${editing.value}`, options);
}

const field = 'mt-1 block min-h-11 w-full rounded-md border border-border bg-bg px-3';
</script>

<template>
    <div class="mx-auto max-w-3xl space-y-4">
        <header class="flex flex-wrap items-center justify-between gap-2">
            <div>
                <h1 class="text-2xl font-semibold">{{ trans('logbook.title') }}</h1>
                <p class="text-text-2">{{ vehicle.identifier }} {{ vehicle.name }}</p>
            </div>
            <Link href="/firetab/list" class="inline-flex min-h-11 items-center rounded-md border border-border px-4">{{ trans('firetab.fms.back') }}</Link>
        </header>

        <form class="grid grid-cols-2 gap-3 rounded-md border border-border p-4" @submit.prevent="submit">
            <label class="col-span-2 sm:col-span-1">{{ trans('logbook.field.date') }}
                <input v-model="form.trip_date" type="date" required :class="field" />
                <span v-if="form.errors.trip_date" class="text-sm text-red-600">{{ form.errors.trip_date }}</span>
            </label>
            <label class="col-span-2 sm:col-span-1">{{ trans('logbook.field.type') }}
                <select v-model="form.trip_type" :class="field">
                    <option v-for="t in types" :key="t" :value="t">{{ trans(`logbook.type.${t}`) }}</option>
                </select>
            </label>
            <label>{{ trans('logbook.field.departed') }}
                <input v-model="form.departed_at" type="time" required :class="field" />
                <span v-if="form.errors.departed_at" class="text-sm text-red-600">{{ form.errors.departed_at }}</span>
            </label>
            <label>{{ trans('logbook.field.arrived') }}
                <input v-model="form.arrived_at" type="time" :class="field" />
                <span v-if="form.errors.arrived_at" class="text-sm text-red-600">{{ form.errors.arrived_at }}</span>
            </label>
            <label class="col-span-2">{{ trans('logbook.field.km') }}
                <input v-model="form.distance_km" type="number" step="0.1" min="0" inputmode="decimal" required :class="field" />
                <span v-if="form.errors.distance_km" class="text-sm text-red-600">{{ form.errors.distance_km }}</span>
            </label>
            <label class="col-span-2">{{ trans('logbook.field.reason') }}
                <textarea v-model="form.reason" rows="2" maxlength="5000" :class="field" />
            </label>
            <div class="col-span-2 flex gap-2">
                <button type="submit" :disabled="form.processing" class="min-h-11 rounded-md bg-accent px-6 text-white disabled:opacity-50">{{ trans('firetab.save') }}</button>
                <button v-if="editing !== null" type="button" class="min-h-11 rounded-md border border-border px-6" @click="reset">{{ trans('firetab.logbook.cancel') }}</button>
            </div>
        </form>

        <ul v-if="entries.length" class="divide-y divide-border rounded-md border border-border">
            <li v-for="e in entries" :key="e.id" class="flex flex-wrap items-center justify-between gap-2 px-4 py-3">
                <div>
                    <p class="font-semibold">{{ e.trip_date }} · {{ e.departed_at }}<template v-if="e.arrived_at"> – {{ e.arrived_at }}</template> · {{ e.distance_km }} km</p>
                    <p class="text-sm text-text-2">{{ trans(`logbook.type.${e.trip_type}`) }} · {{ e.driver_name }}<template v-if="e.reason"> · {{ e.reason }}</template></p>
                </div>
                <button v-if="e.editable" type="button" class="min-h-11 rounded-md border border-border px-4" @click="edit(e)">{{ trans('firetab.logbook.edit') }}</button>
            </li>
        </ul>
        <p v-else class="text-text-2">{{ trans('firetab.logbook.empty') }}</p>
    </div>
</template>
