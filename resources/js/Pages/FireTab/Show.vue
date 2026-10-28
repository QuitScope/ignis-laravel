<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import Board from '@/components/firetab/asu/Board.vue';
import Abschluss from '@/components/firetab/Abschluss.vue';
import AsuProtocols, { type AsuProtocolRow, type AsuRegistrationRow } from '@/components/firetab/AsuProtocols.vue';
import Bericht from '@/components/firetab/Bericht.vue';
import Fahrzeuge, { type IncidentVehicle } from '@/components/firetab/Fahrzeuge.vue';
import Lagemeldungen, { type Sitrep } from '@/components/firetab/Lagemeldungen.vue';
import Log, { type LogRow } from '@/components/firetab/Log.vue';
import Stammdaten, { type Master } from '@/components/firetab/Stammdaten.vue';
import TabletLayout from '@/Layouts/TabletLayout.vue';
import { useOfflineHint } from '@/lib/firetabOffline';
import { usePulse } from '@/lib/realtime';

defineOptions({ layout: TabletLayout });

const props = defineProps<{
    tab: string;
    tabs: string[];
    incident: Master & { id: number; started_at: string | null; finalized: boolean; archived: boolean };
    vehicles: IncidentVehicle[];
    sitreps: Sitrep[];
    protocols: AsuProtocolRow[];
    registration: AsuRegistrationRow[];
    log: LogRow[];
    missing: string[];
    can: { write: boolean };
    ownVehicleId: number | null;
    asu: { url: string; config: { consumption: number; start_bar: number } };
}>();

usePulse(`firetab.incident.${props.incident.id}`, ['incident'], () => router.reload({ only: ['incident', 'vehicles', 'sitreps', 'protocols', 'registration', 'log', 'missing', 'can'] }), { pollMs: 0 });

const offline = useOfflineHint();

const pad = (n: number) => String(n).padStart(2, '0');
const d = new Date();
const now = { date: `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`, time: `${pad(d.getHours())}:${pad(d.getMinutes())}` };
</script>

<template>
    <div class="mx-auto max-w-4xl space-y-4">
        <header class="flex flex-wrap items-center justify-between gap-2">
            <div>
                <h1 class="text-2xl font-semibold">{{ incident.number ?? trans('firetab.list.no_number') }} · {{ incident.keyword }}</h1>
                <p class="text-text-2">{{ incident.location }} · {{ incident.started_at }}</p>
            </div>
            <Link href="/firetab/list" class="inline-flex min-h-11 items-center text-accent">{{ trans('firetab.back') }}</Link>
        </header>

        <p v-if="offline" role="status" class="rounded-md border border-warn bg-(--warn-soft) p-3 text-sm font-semibold">{{ trans('firetab.offline_hint') }}</p>
        <p v-if="!can.write" class="rounded-md border border-info bg-(--info-soft) p-3 text-sm">{{ trans('firetab.readonly') }}</p>

        <nav class="flex flex-wrap gap-2" aria-label="tabs">
            <Link
                v-for="t in tabs"
                :key="t"
                :href="`/firetab/incidents/${incident.id}?tab=${t}`"
                :aria-current="t === tab ? 'page' : undefined"
                class="inline-flex min-h-11 items-center rounded-md border px-3"
                :class="t === tab ? 'border-accent text-accent' : 'border-border text-text-2'"
            >
                {{ trans(`firetab.tab.${t}`) }}
            </Link>
        </nav>

        <Stammdaten
            v-if="tab === 'stammdaten'"
            :initial="incident"
            :url="`/firetab/incidents/${incident.id}`"
            method="put"
            :can-edit="can.write"
            :submit="trans('firetab.save')"
        />
        <div v-else-if="tab === 'bericht'" class="space-y-6">
            <Bericht :id="incident.id" :notes="incident.notes ?? null" :can-edit="can.write" />
            <AsuProtocols :protocols="protocols" :registration="registration" />
        </div>
        <Fahrzeuge v-else-if="tab === 'fahrzeuge'" :id="incident.id" :vehicles="vehicles" :can-edit="can.write" />
        <Lagemeldungen
            v-else-if="tab === 'lagemeldungen'"
            :id="incident.id"
            :sitreps="sitreps"
            :vehicles="vehicles"
            :own-vehicle-id="ownVehicleId"
            :can-edit="can.write"
            :now="now"
        />
        <Board v-else-if="tab === 'atemschutz'" :incident-id="incident.id" :url="asu.url" :config="asu.config" :can-write="can.write" />
        <p v-else-if="tab === 'lagekarte'">
            <Link :href="`/firetab/incidents/${incident.id}/map`" class="inline-flex min-h-11 items-center rounded-md border border-accent px-3 text-accent">{{ trans('firetab.map.open') }}</Link>
        </p>
        <Abschluss v-else-if="tab === 'abschluss'" :id="incident.id" :missing="missing" :finalized="incident.finalized" :can-edit="can.write" />
        <Log v-else :log="log" />
    </div>
</template>
