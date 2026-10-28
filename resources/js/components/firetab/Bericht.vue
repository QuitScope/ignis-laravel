<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { Button } from '@/components/ui/button';

const props = defineProps<{ id: number; notes: string | null; canEdit: boolean }>();
const form = useForm({ notes: props.notes ?? '' });
</script>

<template>
    <form class="space-y-3" @submit.prevent="form.put(`/firetab/incidents/${id}/report`, { preserveScroll: true })">
        <label for="report" class="block text-sm text-text-2">{{ trans('firetab.report.title') }}</label>
        <textarea
            id="report"
            v-model="form.notes"
            rows="12"
            maxlength="20000"
            :disabled="!canEdit"
            class="block w-full rounded-md border border-border bg-bg-2 px-3 py-2 text-text disabled:opacity-60 focus-visible:outline-2 focus-visible:outline-accent"
        />
        <p v-if="form.errors.notes" role="alert" class="text-sm text-danger">{{ form.errors.notes }}</p>
        <Button v-if="canEdit" type="submit" class="min-h-11" :disabled="form.processing">{{ trans('firetab.save') }}</Button>
    </form>
</template>
