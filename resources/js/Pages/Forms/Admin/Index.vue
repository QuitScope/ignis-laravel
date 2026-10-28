<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import SubmissionList, { type Paginated } from '@/components/forms/SubmissionList.vue';

defineProps<{ submissions: Paginated; filters: { status: string; q: string }; statuses: string[] }>();
</script>

<template>
    <div class="space-y-4">
        <h1 class="text-2xl font-semibold">{{ trans('forms.admin.title') }}</h1>
        <SubmissionList action="/forms/admin" :submissions="submissions" :filters="filters" :statuses="statuses" admin />
    </div>
</template>
