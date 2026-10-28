<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { Button } from '@/components/ui/button';
import SubmissionDetail, { type Submission } from '@/components/forms/SubmissionDetail.vue';

defineProps<{ submission: Submission; can: { manage: boolean } }>();
</script>

<template>
    <div class="max-w-4xl space-y-4">
        <SubmissionDetail :submission="submission" />
        <div class="flex flex-wrap gap-2">
            <Button variant="secondary" as-child>
                <Link href="/me/applications">{{ trans('forms.select.mine') }}</Link>
            </Button>
            <Button v-if="can.manage" as-child>
                <Link :href="`/forms/admin/${submission.public_id}`">{{ trans('forms.show.manage') }}</Link>
            </Button>
        </div>
    </div>
</template>
