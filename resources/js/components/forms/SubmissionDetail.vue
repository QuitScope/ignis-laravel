<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import StatusBadge from '@/components/forms/StatusBadge.vue';
import { formIcon } from '@/lib/formIcons';

export type Answer = { key: string; label: string; type: string; width: 'full' | 'half'; value: string | boolean; display: string };
export type Submission = {
    public_id: string;
    type: string;
    icon: string | null;
    applicant_name: string;
    applicant_rank: string | null;
    status: string;
    submitted_at: string | null;
    decided_at: string | null;
    decided_by_name: string | null;
    decision_note: string | null;
    answers: Answer[];
};

// Everything is plain text ({{ }}); line breaks of long answers and the remark are kept by CSS.
defineProps<{ submission: Submission }>();

const when = (iso: string | null) => (iso ? new Date(iso).toLocaleString('de-DE', { dateStyle: 'medium', timeStyle: 'short' }) : '–');
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-center gap-3">
            <component :is="formIcon(submission.icon)" class="size-6 shrink-0 text-accent" aria-hidden="true" />
            <h1 class="text-2xl font-semibold">{{ submission.type }} · {{ trans('forms.show.title', { id: submission.public_id }) }}</h1>
            <StatusBadge :status="submission.status" />
        </div>

        <dl class="grid gap-3 rounded-lg border border-border bg-card p-5 text-sm shadow-(--shadow) sm:grid-cols-3">
            <div>
                <dt class="text-text-2">{{ trans('forms.show.applicant') }}</dt>
                <dd>{{ submission.applicant_name }}</dd>
            </div>
            <div>
                <dt class="text-text-2">{{ trans('forms.show.rank') }}</dt>
                <dd>{{ submission.applicant_rank || '–' }}</dd>
            </div>
            <div>
                <dt class="text-text-2">{{ trans('forms.show.submitted') }}</dt>
                <dd>{{ when(submission.submitted_at) }}</dd>
            </div>
        </dl>

        <section class="rounded-lg border border-border bg-card p-5 shadow-(--shadow)" aria-labelledby="answers-title">
            <h2 id="answers-title" class="text-lg font-medium">{{ trans('forms.show.answers') }}</h2>
            <dl class="mt-3 grid gap-3 text-sm sm:grid-cols-2">
                <div v-for="(a, i) in submission.answers" :key="`${a.key}-${i}`" :class="a.width === 'half' ? '' : 'sm:col-span-2'">
                    <dt class="text-text-2">{{ a.label }}</dt>
                    <dd class="break-words whitespace-pre-line">{{ a.display === '' ? '–' : a.display }}</dd>
                </div>
            </dl>
        </section>

        <section v-if="submission.decided_by_name || submission.decision_note" class="rounded-lg border border-border bg-card p-5 shadow-(--shadow)" aria-labelledby="decision-title">
            <h2 id="decision-title" class="text-lg font-medium">{{ trans('forms.show.decision') }}</h2>
            <dl class="mt-3 grid gap-3 text-sm sm:grid-cols-2">
                <div>
                    <dt class="text-text-2">{{ trans('forms.show.decided_by') }}</dt>
                    <dd>{{ submission.decided_by_name || '–' }}</dd>
                </div>
                <div>
                    <dt class="text-text-2">{{ trans('forms.show.decided_at') }}</dt>
                    <dd>{{ when(submission.decided_at) }}</dd>
                </div>
                <div v-if="submission.decision_note" class="sm:col-span-2">
                    <dt class="text-text-2">{{ trans('forms.show.note') }}</dt>
                    <dd class="break-words whitespace-pre-line">{{ submission.decision_note }}</dd>
                </div>
            </dl>
        </section>
    </div>
</template>
