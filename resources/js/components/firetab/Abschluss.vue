<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { Button } from '@/components/ui/button';

const props = defineProps<{ id: number; missing: string[]; finalized: boolean; canEdit: boolean }>();
const form = useForm({});

function finalize() {
    if (window.confirm(trans('firetab.finish.confirm'))) form.post(`/firetab/incidents/${props.id}/finalize`, { preserveScroll: true });
}
</script>

<template>
    <div class="space-y-3">
        <h2 class="text-lg font-semibold">{{ trans('firetab.finish.title') }}</h2>
        <p v-if="finalized" class="rounded-md border border-success p-3">{{ trans('firetab.finish.done') }}</p>
        <template v-else>
            <p v-if="!missing.length" class="rounded-md border border-success p-3">{{ trans('firetab.finish.ready') }}</p>
            <div v-else class="rounded-md border border-warning p-3">
                <p>{{ trans('firetab.finish.missing') }}</p>
                <ul class="mt-1 list-disc pl-5">
                    <li v-for="m in missing" :key="m">{{ trans(`firetab.finish.field.${m}`) }}</li>
                </ul>
            </div>
            <p v-if="Object.keys(form.errors).length" role="alert" class="text-sm text-danger">{{ Object.values(form.errors).join(' ') }}</p>
            <Button v-if="canEdit" class="min-h-11" :disabled="form.processing || missing.length > 0" @click="finalize">{{ trans('firetab.finish.submit') }}</Button>
        </template>
    </div>
</template>
