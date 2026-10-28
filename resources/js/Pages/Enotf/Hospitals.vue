<script setup lang="ts">
/** GET /enotf/hospitals: department availability of every hospital, read only for the crew. */
import { trans } from 'laravel-vue-i18n';
import DepartmentStatus from '@/components/enotf/DepartmentStatus.vue';
import TabletLayout from '@/Layouts/TabletLayout.vue';

defineOptions({ layout: TabletLayout });

defineProps<{
    hospitals: {
        id: number;
        name: string;
        city: string;
        departments: { id: number; name: string; status: string; note: string | null; updated_at: string | null }[];
    }[];
}>();
</script>

<template>
    <div class="mx-auto max-w-4xl space-y-4">
        <header class="flex flex-wrap items-center justify-between gap-2">
            <h1 class="text-2xl font-semibold">{{ trans('enotf.hospital.title') }}</h1>
            <a href="/enotf/overview" class="inline-flex min-h-11 items-center text-accent">{{ trans('enotf.protocol.back') }}</a>
        </header>
        <p v-if="!hospitals.length" class="text-text-2">{{ trans('enotf.hospital.none') }}</p>
        <section v-for="h in hospitals" :key="h.id" class="rounded-md border border-border p-3" :aria-labelledby="`hosp-${h.id}`">
            <h2 :id="`hosp-${h.id}`" class="text-lg font-semibold">
                {{ h.name }} <span class="text-sm font-normal text-text-2">{{ h.city }}</span>
            </h2>
            <p v-if="!h.departments.length" class="text-sm text-text-2">{{ trans('enotf.hospital.no_departments') }}</p>
            <ul v-else class="divide-y divide-border">
                <li v-for="d in h.departments" :key="d.id" class="flex flex-wrap items-center gap-3 py-2">
                    <span class="min-w-40 font-medium">{{ d.name }}</span>
                    <DepartmentStatus :status="d.status" />
                    <span class="text-sm text-text-2">{{ [d.note, d.updated_at].filter(Boolean).join(' · ') }}</span>
                </li>
            </ul>
        </section>
    </div>
</template>
