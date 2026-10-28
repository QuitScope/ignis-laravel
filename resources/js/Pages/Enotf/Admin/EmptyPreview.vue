<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { Button } from '@/components/ui/button';

type Filter = { fields: string[]; days: string; state: string };
type Preview = {
    token: string;
    count: number;
    rows: { id: number; enr: string; created_at: string | null; vehicles: string[] }[];
};

const props = defineProps<{ fields: string[]; filter: Filter | null; ttl: number; preview: Preview | null }>();

const form = useForm({
    fields: props.filter?.fields ?? ['patient_last_name'],
    days: props.filter?.days ?? '30',
    state: props.filter?.state ?? 'open',
});
const run = useForm({ fields: props.filter?.fields ?? [], days: props.filter?.days ?? '30', state: props.filter?.state ?? 'open', token: props.preview?.token ?? '' });

const select = 'h-9 rounded-md border border-border bg-card px-3 text-sm';
</script>

<template>
    <div class="mx-auto max-w-3xl space-y-4">
        <header class="flex flex-wrap items-center justify-between gap-2">
            <h1 class="text-2xl font-semibold">{{ trans('enotf.admin.empty.title') }}</h1>
            <Link href="/enotf/admin" class="text-accent">{{ trans('enotf.admin.back') }}</Link>
        </header>
        <p class="text-text-2">{{ trans('enotf.admin.empty.lead') }}</p>

        <form class="space-y-3" @submit.prevent="form.post('/enotf/admin/empty')">
            <fieldset class="space-y-1">
                <label v-for="f in fields" :key="f" class="flex items-center gap-2">
                    <input v-model="form.fields" type="checkbox" :value="f" />
                    {{ trans(`enotf.admin.empty.field.${f}`) }}
                </label>
            </fieldset>
            <div class="flex flex-wrap gap-3">
                <label class="text-sm text-text-2"
                    >{{ trans('enotf.admin.empty.days') }}
                    <select v-model="form.days" :class="select">
                        <option v-for="d in ['7', '30', '90', '365', 'all']" :key="d" :value="d">{{ trans(`enotf.admin.empty.days.${d}`) }}</option>
                    </select>
                </label>
                <label class="text-sm text-text-2"
                    >{{ trans('enotf.admin.empty.state') }}
                    <select v-model="form.state" :class="select">
                        <option v-for="s in ['open', 'released', 'withdrawn']" :key="s" :value="s">{{ trans(`enotf.admin.status.${s}`) }}</option>
                    </select>
                </label>
            </div>
            <p v-if="form.errors.fields" role="alert" class="text-sm text-danger">{{ form.errors.fields }}</p>
            <Button type="submit" :disabled="form.processing || !form.fields.length">{{ trans('enotf.admin.empty.preview') }}</Button>
        </form>

        <section v-if="preview" class="space-y-3">
            <p>{{ trans('enotf.admin.empty.found', { count: String(preview.count) }) }}</p>
            <ul v-if="preview.rows.length" class="divide-y divide-border rounded-md border border-border">
                <li v-for="r in preview.rows" :key="r.id" class="px-4 py-2 text-sm">{{ r.enr }} · {{ r.vehicles.join(', ') || '–' }} · {{ r.created_at }}</li>
            </ul>
            <p v-else class="text-text-2">{{ trans('enotf.admin.empty.none') }}</p>
            <p v-if="run.errors.token" role="alert" class="text-sm text-danger">{{ run.errors.token }}</p>
            <Button v-if="preview.count > 0" :disabled="run.processing" @click="run.post('/enotf/admin/empty')">{{ trans('enotf.admin.empty.execute') }}</Button>
        </section>
    </div>
</template>
