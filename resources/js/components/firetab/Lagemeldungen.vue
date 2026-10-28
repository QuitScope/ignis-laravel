<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import type { IncidentVehicle } from '@/components/firetab/Fahrzeuge.vue';
import { Button } from '@/components/ui/button';

export type Sitrep = { id: number; at: string | null; text: string; reporter: string | null; source: string };

const props = defineProps<{
    id: number;
    sitreps: Sitrep[];
    vehicles: IncidentVehicle[];
    ownVehicleId: number | null;
    canEdit: boolean;
    now: { date: string; time: string };
}>();

const own = props.vehicles.find((v) => v.id === props.ownVehicleId) ?? props.vehicles[0];
const form = useForm({ date: props.now.date, time: props.now.time, text: '', incident_vehicle_id: own?.id ?? 0 });
const send = () => form.post(`/firetab/incidents/${props.id}/sitreps`, { preserveScroll: true, onSuccess: () => form.reset('text') });
const input =
    'block min-h-11 w-full rounded-md border border-border bg-bg-2 px-3 py-2 text-text focus-visible:outline-2 focus-visible:outline-accent';
</script>

<template>
    <div class="space-y-4">
        <h2 class="text-lg font-semibold">{{ trans('firetab.sitreps.title') }}</h2>

        <form v-if="canEdit" class="space-y-3 rounded-md border border-border p-3" @submit.prevent="send">
            <div class="grid gap-3 md:grid-cols-3">
                <div>
                    <label for="s-date" class="mb-1 block text-sm text-text-2">{{ trans('firetab.sitreps.date') }}</label>
                    <input id="s-date" v-model="form.date" type="date" :class="input" />
                </div>
                <div>
                    <label for="s-time" class="mb-1 block text-sm text-text-2">{{ trans('firetab.sitreps.time') }}</label>
                    <input id="s-time" v-model="form.time" type="time" :class="input" />
                </div>
                <div>
                    <label for="s-veh" class="mb-1 block text-sm text-text-2">{{ trans('firetab.sitreps.reporter') }}</label>
                    <select id="s-veh" v-model.number="form.incident_vehicle_id" :class="input">
                        <option v-for="v in vehicles" :key="v.id" :value="v.id">{{ v.radio_name ?? v.name }}</option>
                    </select>
                </div>
            </div>
            <div>
                <label for="s-text" class="mb-1 block text-sm text-text-2">{{ trans('firetab.sitreps.text') }}</label>
                <textarea id="s-text" v-model="form.text" rows="3" maxlength="2000" :class="input" />
            </div>
            <template v-for="e in ['date', 'time', 'text', 'incident_vehicle_id'] as const" :key="e">
                <p v-if="form.errors[e]" role="alert" class="text-sm text-danger">{{ form.errors[e] }}</p>
            </template>
            <Button type="submit" class="min-h-11" :disabled="form.processing">{{ trans('firetab.sitreps.add') }}</Button>
        </form>

        <ul v-if="sitreps.length" class="divide-y divide-border rounded-md border border-border">
            <li v-for="s in sitreps" :key="s.id" class="space-y-1 px-4 py-2">
                <p class="text-sm text-text-2">{{ s.at }} · {{ s.source === 'dispatch' ? trans('firetab.sitreps.dispatch') : s.reporter }}</p>
                <p class="whitespace-pre-wrap">{{ s.text }}</p>
            </li>
        </ul>
        <p v-else class="text-text-2">{{ trans('firetab.sitreps.empty') }}</p>
    </div>
</template>
