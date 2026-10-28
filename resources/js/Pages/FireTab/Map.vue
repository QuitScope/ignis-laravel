<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import MapView from '@/components/firetab/map/MapView.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import TabletLayout from '@/Layouts/TabletLayout.vue';
import { useOfflineHint } from '@/lib/firetabOffline';
import type { MapMarker, MapZone } from '@/lib/leafletMap';

// Crew route: tablet layout; the crew-free QM route (read only) uses the desktop layout.
// eslint-disable-next-line @typescript-eslint/no-explicit-any
defineOptions({ layout: (h: any, page: any) => h(page.props.qm ? AppLayout : TabletLayout, () => page) });

const props = defineProps<{
    incident: { id: number; number: string | null; location: string | null; has_location: boolean };
    base: string;
    back: string;
    qm: boolean;
    can: { write: boolean };
    colors: string[];
    markers: MapMarker[];
    zones: MapZone[];
}>();

const offline = useOfflineHint();
</script>

<template>
    <div class="mx-auto max-w-6xl space-y-4">
        <header class="flex flex-wrap items-center justify-between gap-2">
            <div>
                <h1 class="text-2xl font-semibold">{{ trans('firetab.map.title') }} · {{ incident.number ?? '#' + incident.id }}</h1>
                <p class="text-text-2">{{ incident.location }}</p>
            </div>
            <Link :href="back" class="inline-flex min-h-11 items-center text-accent">{{ trans('firetab.back') }}</Link>
        </header>
        <p v-if="offline && !props.qm" role="status" class="rounded-md border border-warn bg-(--warn-soft) p-3 text-sm font-semibold">{{ trans('firetab.offline_hint') }}</p>
        <p v-if="!can.write" class="rounded-md border border-info bg-(--info-soft) p-3 text-sm">{{ trans('firetab.readonly') }}</p>
        <MapView
            :incident-id="incident.id"
            :base="base"
            :can-write="can.write"
            :has-location="incident.has_location"
            :colors="colors"
            :markers="markers"
            :zones="zones"
        />
    </div>
</template>
