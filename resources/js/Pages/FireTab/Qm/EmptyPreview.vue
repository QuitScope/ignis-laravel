<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { Button } from '@/components/ui/button';

type Filter = { fields: string[]; days: string; state: string };
type Preview = {
    token: string;
    count: number;
    rows: { id: number; number: string | null; location: string | null; keyword: string | null; created_at: string | null; finalized: boolean }[];
};

const props = defineProps<{ fields: string[]; filter: Filter | null; ttl: number; preview: Preview | null }>();

const form = useForm({
    fields: props.filter?.fields ?? ['location'],
    days: props.filter?.days ?? '30',
    state: props.filter?.state ?? 'all',
});
const run = useForm({ fields: props.filter?.fields ?? [], days: props.filter?.days ?? '30', state: props.filter?.state ?? 'all', token: props.preview?.token ?? '' });

const select = 'h-9 rounded-md border border-border bg-card px-3 text-sm';
</script>

<template>
    <div class="mx-auto max-w-3xl space-y-4">
        <header class="flex flex-wrap items-center justify-between gap-2">
            <h1 class="text-2xl font-semibold">{{ trans('firetab.qm.empty.title') }}</h1>
            <Link href="/firetab/qm" class="text-accent">{{ trans('firetab.back') }}</Link>
        </header>
        <p class="text-text-2">{{ trans('firetab.qm.empty.lead') }}</p>

        <form class="space-y-3" @submit.prevent="form.post('/firetab/qm/empty')">
            <fieldset class="space-y-1">
                <label v-for="f in fields" :key="f" class="flex items-center gap-2">
                    <input v-model="form.fields" type="checkbox" :value="f" />
                    {{ trans(`firetab.qm.empty.field.${f}`) }}
                </label>
            </fieldset>
            <div class="flex flex-wrap gap-3">
                <label class="text-sm text-text-2"
                    >{{ trans('firetab.qm.empty.days') }}
                    <select v-model="form.days" :class="select">
                        <option v-for="d in ['7', '30', '90', '365', 'all']" :key="d" :value="d">{{ trans(`firetab.qm.empty.days.${d}`) }}</option>
                    </select>
                </label>
                <label class="text-sm text-text-2"
                    >{{ trans('firetab.qm.empty.state') }}
                    <select v-model="form.state" :class="select">
                        <option v-for="s in ['all', 'open', 'finalized']" :key="s" :value="s">{{ trans(`firetab.qm.empty.state.${s}`) }}</option>
                    </select>
                </label>
            </div>
            <p v-if="form.errors.fields" role="alert" class="text-sm text-danger">{{ form.errors.fields }}</p>
            <Button type="submit" :disabled="form.processing || !form.fields.length">{{ trans('firetab.qm.empty.preview') }}</Button>
        </form>

        <section v-if="preview" class="space-y-3">
            <p>{{ trans('firetab.qm.empty.found', { count: String(preview.count) }) }}</p>
            <ul v-if="preview.rows.length" class="divide-y divide-border rounded-md border border-border">
                <li v-for="r in preview.rows" :key="r.id" class="px-4 py-2 text-sm">
                    {{ r.number ?? '#' + r.id }} · {{ r.location ?? '–' }} · {{ r.keyword ?? '–' }} · {{ r.created_at }}
                </li>
            </ul>
            <p v-else class="text-text-2">{{ trans('firetab.qm.empty.none') }}</p>
            <p v-if="run.errors.token" role="alert" class="text-sm text-danger">{{ run.errors.token }}</p>
            <Button v-if="preview.count > 0" :disabled="run.processing" @click="run.post('/firetab/qm/empty')">{{ trans('firetab.qm.empty.execute') }}</Button>
        </section>
    </div>
</template>
