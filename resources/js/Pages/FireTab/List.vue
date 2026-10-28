<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { Button } from '@/components/ui/button';
import TabletLayout from '@/Layouts/TabletLayout.vue';
import { usePulse } from '@/lib/realtime';

defineOptions({ layout: TabletLayout });

const props = defineProps<{
    logbook: boolean;
    vehicle: { id: number; identifier: string; name: string } | null;
    incidents: {
        id: number;
        number: string | null;
        location: string | null;
        keyword: string | null;
        started_at: string | null;
        vehicles_count: number;
        sitreps_count: number;
    }[];
}>();

usePulse(() => (props.vehicle ? `firetab.vehicle.${props.vehicle.id}` : null), ['incidents'], () => router.reload({ only: ['incidents'] }), { pollMs: 30000 });
</script>

<template>
    <div class="mx-auto max-w-3xl space-y-4">
        <header class="flex flex-wrap items-center justify-between gap-2">
            <div>
                <h1 class="text-2xl font-semibold">{{ trans('firetab.list.title') }}</h1>
                <p v-if="vehicle" class="text-text-2">{{ vehicle.identifier }} {{ vehicle.name }}</p>
            </div>
            <div class="flex gap-2">
                <Link href="/firetab/status" class="inline-flex min-h-11 items-center rounded-md border border-border px-4">{{ trans('firetab.fms.title') }}</Link>
                <Link v-if="logbook" href="/firetab/logbook" class="inline-flex min-h-11 items-center rounded-md border border-border px-4">{{ trans('firetab.logbook.nav') }}</Link>
                <Link href="/firetab/incidents/create" class="inline-flex min-h-11 items-center rounded-md bg-accent px-4 text-white">{{ trans('firetab.list.new') }}</Link>
                <Button variant="secondary" class="min-h-11" @click="router.post('/firetab/logout')">{{ trans('firetab.logout') }}</Button>
            </div>
        </header>

        <ul v-if="incidents.length" class="divide-y divide-border rounded-md border border-border">
            <li v-for="i in incidents" :key="i.id">
                <Link :href="`/firetab/incidents/${i.id}`" class="block min-h-11 px-4 py-3 hover:bg-bg-2">
                    <p class="font-semibold">{{ i.number ?? trans('firetab.list.no_number') }} · {{ i.keyword }}</p>
                    <p>{{ i.location }}</p>
                    <p class="text-sm text-text-2">
                        {{ i.started_at }} · {{ trans('firetab.list.vehicles') }} {{ i.vehicles_count }} · {{ trans('firetab.list.sitreps') }} {{ i.sitreps_count }}
                    </p>
                </Link>
            </li>
        </ul>
        <p v-else class="text-text-2">{{ trans('firetab.list.empty') }}</p>
    </div>
</template>
