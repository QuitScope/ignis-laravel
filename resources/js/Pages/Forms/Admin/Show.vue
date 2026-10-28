<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import SubmissionDetail, { type Submission } from '@/components/forms/SubmissionDetail.vue';

const props = defineProps<{ submission: Submission; statuses: string[]; can: { decide: boolean }; own: boolean }>();

const form = useForm({ status: props.submission.status, decision_note: props.submission.decision_note ?? '' });
const save = () => form.put(`/forms/admin/${props.submission.public_id}`, { preserveScroll: true });
const field = 'w-full rounded-md border border-border bg-card px-3 py-2 text-sm';
</script>

<template>
    <div class="max-w-4xl space-y-4">
        <Link href="/forms/admin" class="text-sm text-accent hover:underline">{{ trans('forms.decide.back') }}</Link>
        <SubmissionDetail :submission="submission" />

        <form v-if="can.decide" class="space-y-3 rounded-lg border border-border bg-card p-5 shadow-(--shadow)" @submit.prevent="save">
            <h2 class="text-lg font-medium">{{ trans('forms.decide.title') }}</h2>
            <div class="space-y-1">
                <Label for="decide-status">{{ trans('forms.decide.status') }}</Label>
                <select id="decide-status" v-model="form.status" :class="field" required>
                    <option v-for="s in statuses" :key="s" :value="s">{{ trans(`forms.status.${s}`) }}</option>
                </select>
                <p v-if="form.errors.status" class="text-xs text-danger" role="alert">{{ form.errors.status }}</p>
            </div>
            <div class="space-y-1">
                <Label for="decide-note">{{ trans('forms.decide.note') }}</Label>
                <textarea id="decide-note" v-model="form.decision_note" rows="4" maxlength="5000" :class="field" />
                <p v-if="form.errors.decision_note" class="text-xs text-danger" role="alert">{{ form.errors.decision_note }}</p>
            </div>
            <div class="flex justify-end">
                <Button type="submit" :disabled="form.processing">{{ trans('forms.decide.save') }}</Button>
            </div>
        </form>
        <p v-else-if="own" class="rounded-md border border-border bg-card p-3 text-sm text-text-2" role="status">{{ trans('forms.decide.own') }}</p>
    </div>
</template>
