<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { Button } from '@/components/ui/button';
import SubmissionList, { type Paginated } from '@/components/forms/SubmissionList.vue';

defineProps<{ submissions: Paginated; filters: { status: string; q: string }; statuses: string[] }>();
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold">{{ trans('forms.mine.title') }}</h1>
                <p class="mt-1 text-sm text-text-2">{{ trans('forms.mine.intro') }}</p>
            </div>
            <Button as-child>
                <Link href="/forms">{{ trans('forms.mine.new') }}</Link>
            </Button>
        </div>
        <SubmissionList action="/me/applications" :submissions="submissions" :filters="filters" :statuses="statuses" :admin="false">
            <template #empty>{{ trans('forms.mine.empty') }}</template>
        </SubmissionList>
    </div>
</template>
