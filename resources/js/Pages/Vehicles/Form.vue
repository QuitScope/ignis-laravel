<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import TacticalSymbolForm from '@/components/vehicles/TacticalSymbolForm.vue';
import { emptySymbol, type SymbolTemplate } from '@/lib/tacticalSymbol';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Vehicle = {
    id: number;
    name: string;
    type: string;
    identifier: string;
    license_plate: string | null;
    service: number;
    sort_order: number;
    is_active: boolean;
    station_poi_id: number | null;
    allowed_jobs: string;
    tactical_symbol: Record<string, unknown> | null;
    tactical_symbol_name: string | null;
};

const props = defineProps<{ vehicle: Vehicle | null; stations: { id: number; label: string }[]; templates: SymbolTemplate[]; canView: boolean }>();

const back = props.canView ? (props.vehicle ? `/vehicles/${props.vehicle.id}` : '/vehicles') : '/';

const v = props.vehicle;
const form = useForm({
    name: v?.name ?? '',
    type: v?.type ?? '',
    identifier: v?.identifier ?? '',
    license_plate: v?.license_plate ?? '',
    service: v?.service ?? 0,
    sort_order: v?.sort_order ?? 0,
    is_active: v?.is_active ?? true,
    station_poi_id: v?.station_poi_id ?? ('' as number | ''),
    allowed_jobs: v?.allowed_jobs ?? '',
    tactical_symbol: emptySymbol(v?.tactical_symbol),
    tactical_symbol_name: v?.tactical_symbol_name ?? '',
});
const submit = () => (v ? form.put(`/vehicles/${v.id}`) : form.post('/vehicles'));

const field = 'h-9 w-full rounded-md border border-border bg-card px-3 text-sm';
const texts = [
    ['name', 255],
    ['type', 255],
    ['identifier', 255],
    ['license_plate', 255],
] as const;
</script>

<template>
    <div class="max-w-3xl space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h1 class="text-2xl font-semibold">{{ v ? trans('vehicles.edit_title', { name: v.name }) : trans('vehicles.new') }}</h1>
            <Button as-child variant="ghost">
                <Link :href="back">{{ trans('vehicles.cancel') }}</Link>
            </Button>
        </div>

        <form class="grid gap-4 rounded-lg border border-border bg-card p-4 shadow-(--shadow) sm:grid-cols-2" @submit.prevent="submit">
            <div v-for="[key, max] in texts" :key="key" class="space-y-1">
                <Label :for="key">{{ trans(`vehicles.field.${key}`) }}</Label>
                <Input :id="key" v-model="form[key]" :maxlength="max" :required="key !== 'license_plate'" />
                <p v-if="form.errors[key]" class="text-sm text-danger" role="alert">{{ form.errors[key] }}</p>
            </div>
            <div class="space-y-1">
                <Label for="service">{{ trans('vehicles.field.service') }}</Label>
                <select id="service" v-model.number="form.service" :class="field" required>
                    <option v-for="s in [0, 1, 2, 3]" :key="s" :value="s">{{ trans(`vehicles.service.${s}`) }}</option>
                </select>
                <p v-if="form.errors.service" class="text-sm text-danger" role="alert">{{ form.errors.service }}</p>
            </div>
            <div class="space-y-1">
                <Label for="sort_order">{{ trans('vehicles.field.sort_order') }}</Label>
                <Input id="sort_order" v-model.number="form.sort_order" type="number" step="1" />
                <p v-if="form.errors.sort_order" class="text-sm text-danger" role="alert">{{ form.errors.sort_order }}</p>
            </div>
            <div class="space-y-1">
                <Label for="station_poi_id">{{ trans('vehicles.field.station') }}</Label>
                <select id="station_poi_id" v-model="form.station_poi_id" :class="field">
                    <option value="">{{ trans('vehicles.no_station') }}</option>
                    <option v-for="s in stations" :key="s.id" :value="s.id">{{ s.label }}</option>
                </select>
                <p v-if="form.errors.station_poi_id" class="text-sm text-danger" role="alert">{{ form.errors.station_poi_id }}</p>
            </div>
            <div class="space-y-1 sm:col-span-2">
                <Label for="allowed_jobs">{{ trans('vehicles.field.allowed_jobs') }}</Label>
                <textarea id="allowed_jobs" v-model="form.allowed_jobs" rows="2" maxlength="500" class="w-full rounded-md border border-border bg-card px-3 py-2 text-sm" />
                <p class="text-xs text-text-2">{{ trans('vehicles.allowed_jobs_hint') }}</p>
                <p v-if="form.errors.allowed_jobs" class="text-sm text-danger" role="alert">{{ form.errors.allowed_jobs }}</p>
            </div>
            <div class="space-y-1 sm:col-span-2">
                <label class="flex items-center gap-2 text-sm">
                    <input v-model="form.is_active" type="checkbox" class="size-4" />
                    {{ trans('vehicles.field.is_active') }}
                </label>
                <p class="text-xs text-text-2">{{ trans('vehicles.field.is_active_hint') }}</p>
            </div>
            <div class="sm:col-span-2">
                <TacticalSymbolForm
                    v-model="form.tactical_symbol"
                    v-model:name="form.tactical_symbol_name"
                    prefix="tactical_symbol"
                    :errors="form.errors"
                    :name-error="form.errors.tactical_symbol_name"
                    :templates="templates"
                />
                <p v-if="form.errors.tactical_symbol" class="text-sm text-danger" role="alert">{{ form.errors.tactical_symbol }}</p>
            </div>
            <div class="sm:col-span-2">
                <Button type="submit" :disabled="form.processing">{{ trans('vehicles.save') }}</Button>
            </div>
        </form>
    </div>
</template>
