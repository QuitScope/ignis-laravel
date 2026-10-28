<script setup lang="ts">
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

export type Entry = {
    id: number;
    vehicle_id: number | null;
    vehicle_identifier: string;
    trip_date: string;
    departed_at: string;
    arrived_at: string | null;
    past_midnight: boolean;
    station_name: string | null;
    distance_km: number;
    reason: string | null;
    trip_type: string;
    driver_name: string;
    source: string;
};

// Create (entry null) or edit one trip; `open` is driven by the page. Vehicle, station and source are set by the server.
const props = defineProps<{ open: boolean; entry: Entry | null; vehicles: { id: number; label: string }[]; types: string[] }>();
const emit = defineEmits<{ close: [] }>();

const blank = () => ({ vehicle_id: '' as number | '', trip_date: '', departed_at: '', arrived_at: '', distance_km: '', reason: '', trip_type: '', driver_name: '' });
const form = useForm(blank());

watch(
    () => [props.open, props.entry] as const,
    ([open, e]) => {
        if (!open) return;
        form.clearErrors();
        form.defaults(
            e === null
                ? blank()
                : {
                      vehicle_id: e.vehicle_id ?? '',
                      trip_date: e.trip_date,
                      departed_at: e.departed_at,
                      arrived_at: e.arrived_at ?? '',
                      distance_km: String(e.distance_km),
                      reason: e.reason ?? '',
                      trip_type: e.trip_type,
                      driver_name: e.driver_name,
                  },
        ).reset();
    },
    { immediate: true },
);

function save() {
    const opts = { preserveScroll: true, onSuccess: () => emit('close') };
    if (props.entry === null) form.post('/logbook', opts);
    else form.put(`/logbook/${props.entry.id}`, opts);
}

const vehicleless = computed(() => props.entry !== null && props.entry.vehicle_id === null);
const field = 'w-full rounded-md border border-border bg-card px-3 py-2 text-sm';
</script>

<template>
    <Dialog :open="open" @update:open="(v) => !v && emit('close')">
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-xl">
            <DialogHeader>
                <DialogTitle>{{ trans(entry === null ? 'logbook.new' : 'logbook.edit') }}</DialogTitle>
            </DialogHeader>
            <form class="grid gap-3 sm:grid-cols-2" @submit.prevent="save">
                <div class="space-y-1 sm:col-span-2">
                    <Label for="lb-vehicle">{{ trans('logbook.field.vehicle') }}</Label>
                    <select id="lb-vehicle" v-model="form.vehicle_id" :required="!vehicleless" :class="field">
                        <option value="" :disabled="!vehicleless">{{ trans('logbook.field.choose') }}</option>
                        <option v-for="v in vehicles" :key="v.id" :value="v.id">{{ v.label }}</option>
                    </select>
                    <p class="text-xs text-text-2">{{ trans('logbook.station_hint') }}</p>
                    <p v-if="form.errors.vehicle_id" class="text-xs text-danger">{{ form.errors.vehicle_id }}</p>
                </div>
                <div class="space-y-1">
                    <Label for="lb-date">{{ trans('logbook.field.date') }}</Label>
                    <Input id="lb-date" v-model="form.trip_date" type="date" required />
                    <p v-if="form.errors.trip_date" class="text-xs text-danger">{{ form.errors.trip_date }}</p>
                </div>
                <div class="space-y-1">
                    <Label for="lb-km">{{ trans('logbook.field.km') }}</Label>
                    <Input id="lb-km" v-model="form.distance_km" type="number" min="0" step="0.1" required />
                    <p v-if="form.errors.distance_km" class="text-xs text-danger">{{ form.errors.distance_km }}</p>
                </div>
                <div class="space-y-1">
                    <Label for="lb-dep">{{ trans('logbook.field.departed') }}</Label>
                    <Input id="lb-dep" v-model="form.departed_at" type="time" required />
                    <p v-if="form.errors.departed_at" class="text-xs text-danger">{{ form.errors.departed_at }}</p>
                </div>
                <div class="space-y-1">
                    <Label for="lb-arr">{{ trans('logbook.field.arrived') }}</Label>
                    <Input id="lb-arr" v-model="form.arrived_at" type="time" />
                    <p v-if="form.arrived_at !== '' && form.departed_at !== '' && form.arrived_at < form.departed_at" class="text-xs text-text-2">{{ trans('logbook.past_midnight') }}</p>
                    <p v-if="form.errors.arrived_at" class="text-xs text-danger">{{ form.errors.arrived_at }}</p>
                </div>
                <div class="space-y-1">
                    <Label for="lb-type">{{ trans('logbook.field.type') }}</Label>
                    <select id="lb-type" v-model="form.trip_type" required :class="field">
                        <option value="" disabled>{{ trans('logbook.field.choose') }}</option>
                        <option v-for="t in types" :key="t" :value="t">{{ trans(`logbook.type.${t}`) }}</option>
                    </select>
                    <p v-if="form.errors.trip_type" class="text-xs text-danger">{{ form.errors.trip_type }}</p>
                </div>
                <div class="space-y-1">
                    <Label for="lb-driver">{{ trans('logbook.field.driver') }}</Label>
                    <Input id="lb-driver" v-model="form.driver_name" maxlength="255" required />
                    <p v-if="form.errors.driver_name" class="text-xs text-danger">{{ form.errors.driver_name }}</p>
                </div>
                <div class="space-y-1 sm:col-span-2">
                    <Label for="lb-reason">{{ trans('logbook.field.reason') }}</Label>
                    <textarea id="lb-reason" v-model="form.reason" rows="3" maxlength="5000" :class="field" />
                    <p v-if="form.errors.reason" class="text-xs text-danger">{{ form.errors.reason }}</p>
                </div>
                <DialogFooter class="sm:col-span-2">
                    <Button type="button" variant="ghost" @click="emit('close')">{{ trans('logbook.cancel') }}</Button>
                    <Button type="submit" :disabled="form.processing">{{ trans('logbook.save') }}</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
