<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

const props = defineProps<{ vehicles: { id: number; name: string; type: string }[]; selected: number | null; categories: string[]; canView: boolean }>();

const back = props.canView ? '/vehicles/defects' + (props.selected ? `?vehicle=${props.selected}` : '') : '/';

const form = useForm({
    vehicle: props.selected ?? ('' as number | ''),
    title: '',
    category: 'other',
    description: '',
    is_operable: true,
});
const submit = () => form.transform(({ vehicle: _vehicle, ...data }) => data).post(`/vehicles/${form.vehicle}/defects`);

const field = 'h-9 w-full rounded-md border border-border bg-card px-3 text-sm';
</script>

<template>
    <div class="max-w-3xl space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h1 class="text-2xl font-semibold">{{ trans('vehicles.defects.report') }}</h1>
            <Button as-child variant="ghost">
                <Link :href="back">{{ trans('vehicles.cancel') }}</Link>
            </Button>
        </div>

        <form class="grid gap-4 rounded-lg border border-border bg-card p-4 shadow-(--shadow) sm:grid-cols-2" @submit.prevent="submit">
            <div class="space-y-1">
                <Label for="vehicle">{{ trans('vehicles.defects.field.vehicle') }}</Label>
                <select id="vehicle" v-model="form.vehicle" required :class="field">
                    <option value="" disabled>{{ trans('vehicles.defects.choose_vehicle') }}</option>
                    <option v-for="v in vehicles" :key="v.id" :value="v.id">{{ v.name }} ({{ v.type }})</option>
                </select>
            </div>
            <div class="space-y-1">
                <Label for="category">{{ trans('vehicles.defects.field.category') }}</Label>
                <select id="category" v-model="form.category" :class="field">
                    <option v-for="c in categories" :key="c" :value="c">{{ trans(`vehicles.defect_category.${c}`) }}</option>
                </select>
            </div>
            <div class="space-y-1 sm:col-span-2">
                <Label for="title">{{ trans('vehicles.defects.field.title') }}</Label>
                <Input id="title" v-model="form.title" required maxlength="200" :aria-invalid="!!form.errors.title" />
                <p v-if="form.errors.title" class="text-xs text-danger">{{ form.errors.title }}</p>
            </div>
            <div class="space-y-1 sm:col-span-2">
                <Label for="description">{{ trans('vehicles.defects.field.description') }}</Label>
                <textarea id="description" v-model="form.description" rows="4" maxlength="5000" class="w-full rounded-md border border-border bg-card px-3 py-2 text-sm" />
                <p v-if="form.errors.description" class="text-xs text-danger">{{ form.errors.description }}</p>
            </div>
            <div class="space-y-1 sm:col-span-2">
                <label class="flex items-center gap-2 text-sm">
                    <input v-model="form.is_operable" type="checkbox" class="size-4" />
                    {{ trans('vehicles.defects.field.is_operable') }}
                </label>
                <p class="text-xs text-text-2">{{ trans('vehicles.defects.field.is_operable_hint') }}</p>
            </div>
            <div class="sm:col-span-2">
                <Button type="submit" :disabled="form.processing || form.vehicle === ''">{{ trans('vehicles.defects.submit') }}</Button>
            </div>
        </form>
    </div>
</template>
