<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import TabletLayout from '@/Layouts/TabletLayout.vue';

defineOptions({ layout: TabletLayout });
const page = usePage<{ flash: { status: string | null } }>();
</script>

<template>
    <div class="mx-auto max-w-xl space-y-4">
        <h1 class="text-2xl font-semibold">
            {{ trans('enotf.loggedout.title') }}
        </h1>
        <p v-if="page.props.flash?.status" role="alert" class="rounded-md border border-danger bg-(--danger-soft) p-3 text-sm">{{ trans(page.props.flash.status) }}</p>
        <p class="text-text-2">{{ trans('enotf.loggedout.lead') }}</p>
        <Link href="/enotf/login" class="inline-flex min-h-11 items-center rounded-md bg-accent px-4 text-white">{{ trans('enotf.loggedout.again') }}</Link>
    </div>
</template>
