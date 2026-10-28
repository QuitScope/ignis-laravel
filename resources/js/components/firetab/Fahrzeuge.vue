<script setup lang="ts">
import { router, useForm, usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import SearchPick from '@/components/firetab/SearchPick.vue';
import { Button } from '@/components/ui/button';

export type IncidentVehicle = { id: number; name: string | null; identifier: string | null; radio_name: string | null; is_external: boolean };

const props = defineProps<{ id: number; vehicles: IncidentVehicle[]; canEdit: boolean }>();

const page = usePage<{ errors: Record<string, string> }>();
const external = useForm({ radio_name: '' });
const addSystem = (vehicleId: number) => router.post(`/firetab/incidents/${props.id}/vehicles`, { vehicle_id: vehicleId }, { preserveScroll: true });
const addExternal = () => external.post(`/firetab/incidents/${props.id}/vehicles`, { preserveScroll: true, onSuccess: () => external.reset() });
const remove = (v: IncidentVehicle) => router.delete(`/firetab/incidents/${props.id}/vehicles/${v.id}`, { preserveScroll: true });
const input =
    'block min-h-11 w-full rounded-md border border-border bg-bg-2 px-3 py-2 text-text focus-visible:outline-2 focus-visible:outline-accent';
</script>

<template>
    <div class="space-y-4">
        <h2 class="text-lg font-semibold">{{ trans('firetab.vehicles.title') }}</h2>
        <ul v-if="vehicles.length" class="divide-y divide-border rounded-md border border-border">
            <li v-for="v in vehicles" :key="v.id" class="flex min-h-11 items-center gap-3 px-4 py-2">
                <span v-if="v.identifier" class="font-mono text-sm text-text-2">{{ v.identifier }}</span>
                <span class="flex-1">{{ v.radio_name ?? v.name }}</span>
                <span v-if="v.is_external" class="text-sm text-text-3">{{ trans('firetab.vehicles.external') }}</span>
                <Button v-if="canEdit" type="button" variant="secondary" class="min-h-11" @click="remove(v)">{{ trans('firetab.vehicles.remove') }}</Button>
            </li>
        </ul>
        <p v-else class="text-text-2">{{ trans('firetab.vehicles.none') }}</p>

        <template v-if="canEdit">
            <SearchPick url="/firetab/vehicles/search" :placeholder="trans('firetab.vehicles.search')" @pick="(h) => addSystem(h.id)" />
            <form class="flex gap-2" @submit.prevent="addExternal">
                <label class="sr-only" for="ext">{{ trans('firetab.vehicles.radio_name') }}</label>
                <input id="ext" v-model="external.radio_name" type="text" maxlength="255" :placeholder="trans('firetab.vehicles.radio_name')" :class="input" />
                <Button type="submit" class="min-h-11" :disabled="external.processing">{{ trans('firetab.vehicles.add_external') }}</Button>
            </form>
            <p v-if="external.errors.radio_name" role="alert" class="text-sm text-danger">{{ external.errors.radio_name }}</p>
            <p v-if="(page.props.errors.vehicle_id ?? page.props.errors.vehicle)" role="alert" class="text-sm text-danger">{{ (page.props.errors.vehicle_id ?? page.props.errors.vehicle) }}</p>
        </template>
    </div>
</template>
