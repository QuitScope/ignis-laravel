<script setup lang="ts">
import { Link, useForm, usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import TabletLayout from '@/Layouts/TabletLayout.vue';

defineOptions({ layout: TabletLayout });

defineProps<{
    vehicle: { identifier: string; name: string; type: string; license_plate: string | null };
    defects: { id: number; title: string; status: string; blocking: boolean; created_at: string | null }[];
    categories: string[];
    logbook: boolean;
}>();

const page = usePage<{ flash: { status: string | null } }>();
const form = useForm({ title: '', description: '', category: 'other', is_operable: true });
const field = 'mt-1 block min-h-11 w-full rounded-md border border-border bg-bg px-3';

function submit() {
    if (form.processing) return;
    form.post('/enotf/fahrzeuginfo/defects', { preserveScroll: true, onSuccess: () => form.reset() });
}
</script>

<template>
    <div class="mx-auto max-w-3xl space-y-4">
        <header class="flex flex-wrap items-center justify-between gap-2">
            <div>
                <h1 class="text-2xl font-semibold">{{ trans('enotf.vehicleinfo.title') }}</h1>
                <p class="text-text-2">{{ vehicle.identifier }} {{ vehicle.name }} · {{ vehicle.type }}<template v-if="vehicle.license_plate"> · {{ vehicle.license_plate }}</template></p>
            </div>
            <div class="flex gap-2">
                <Link v-if="logbook" href="/enotf/fahrtenbuch" class="inline-flex min-h-11 items-center rounded-md border border-border px-4">{{ trans('enotf.logbook.link') }}</Link>
                <Link href="/enotf/overview" class="inline-flex min-h-11 items-center rounded-md border border-border px-4">{{ trans('enotf.back') }}</Link>
            </div>
        </header>

        <p v-if="page.props.flash?.status" role="status" class="rounded-md bg-(--ok-soft) px-3 py-2 text-sm text-ok">{{ trans(page.props.flash.status) }}</p>

        <section class="space-y-2">
            <h2 class="text-lg font-medium">{{ trans('enotf.vehicleinfo.defects') }}</h2>
            <ul v-if="defects.length" class="divide-y divide-border rounded-md border border-border">
                <li v-for="d in defects" :key="d.id" class="px-4 py-2 text-sm">
                    <span class="font-medium">{{ d.title }}</span>
                    <span v-if="d.blocking" class="ml-2 text-xs text-danger">{{ trans('vehicles.defects.blocking') }}</span>
                    <span class="ml-2 text-xs text-text-2">{{ d.created_at }}</span>
                </li>
            </ul>
            <p v-else class="text-text-2">{{ trans('enotf.vehicleinfo.no_defects') }}</p>
        </section>

        <form class="space-y-3 rounded-md border border-border p-4" @submit.prevent="submit">
            <h2 class="text-lg font-medium">{{ trans('enotf.vehicleinfo.report') }}</h2>
            <label class="block">{{ trans('vehicles.defects.field.title') }}
                <input v-model="form.title" required maxlength="200" :class="field" />
                <span v-if="form.errors.title" class="text-sm text-danger">{{ form.errors.title }}</span>
            </label>
            <label class="block">{{ trans('vehicles.defects.field.category') }}
                <select v-model="form.category" :class="field">
                    <option v-for="c in categories" :key="c" :value="c">{{ c }}</option>
                </select>
            </label>
            <label class="block">{{ trans('vehicles.defects.field.description') }}
                <textarea v-model="form.description" rows="3" maxlength="5000" :class="field" />
            </label>
            <label class="flex items-center gap-2"><input v-model="form.is_operable" type="checkbox" />{{ trans('vehicles.defects.field.is_operable') }}</label>
            <button type="submit" :disabled="form.processing" class="min-h-11 rounded-md bg-accent px-6 text-white disabled:opacity-50">{{ trans('enotf.vehicleinfo.send') }}</button>
        </form>
    </div>
</template>
