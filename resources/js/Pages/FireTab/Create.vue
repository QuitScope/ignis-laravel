<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import Stammdaten from '@/components/firetab/Stammdaten.vue';
import TabletLayout from '@/Layouts/TabletLayout.vue';

defineOptions({ layout: TabletLayout });

const props = defineProps<{ defaults: { started_date: string; started_time: string }; leader: { id: number; name: string } | null }>();

const initial = {
    number: null,
    location: null,
    keyword: null,
    caller_name: null,
    caller_contact: null,
    owner_name: null,
    owner_contact: null,
    started_date: props.defaults.started_date,
    started_time: props.defaults.started_time,
    leader_personnel_id: props.leader?.id ?? null,
    leader_name: props.leader?.name ?? null,
};
</script>

<template>
    <div class="mx-auto max-w-3xl space-y-4">
        <header class="flex items-center justify-between gap-2">
            <h1 class="text-2xl font-semibold">{{ trans('firetab.create.title') }}</h1>
            <Link href="/firetab/list" class="inline-flex min-h-11 items-center text-accent">{{ trans('firetab.back') }}</Link>
        </header>
        <Stammdaten :initial="initial" url="/firetab/incidents" method="post" :can-edit="true" :submit="trans('firetab.create.submit')" />
    </div>
</template>
