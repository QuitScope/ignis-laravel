<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { trans, transChoice } from 'laravel-vue-i18n';
import FaIcon from '@/components/FaIcon.vue';
import { faIcons } from '@/lib/faIcons';
import { fmsTone, since } from '@/lib/dashboardWidgets';
import Bezel from './Bezel.vue';

// "Fahrzeuge" (legacy vehicles.php): tick scale "einsatzbereit" and the five most recently changed vehicles with their
// FMS chip. Service 1/2 (rescue) shows the ambulance glyph, 3 (fire) the truck, the rest the car like the legacy.
type Row = {
    id: number;
    name: string;
    fms_status: string | null;
    fms_label: string | null;
    since: string | null;
    service: number;
    state: 'operable' | 'blocked' | 'inactive';
    blocking: number;
    station: string | null;
    href: string;
};

defineProps<{ data: { total: number; ready: number; ticks: number; on: number; rows: Row[] } }>();

const page = usePage<{ locale: string }>();
const now = new Date();
const glyph = (service: number) => (service === 1 || service === 2 ? faIcons['truck-medical'] : service === 3 ? faIcons.truck : faIcons.car);
const meta = (row: Row) =>
    [row.station, row.since ? trans('dashboard.since', { since: since(row.since, now, page.props.locale) }) : null].filter(Boolean).join(' · ');
</script>

<template>
    <Bezel icon="truck-medical" title-id="home-vehicles-title" list>
        <template #title>{{ trans('home.vehicles.title') }} <span class="ignis-count">{{ data.total }}</span></template>
        <template #actions>
            <Link href="/vehicles" class="ignis-btn ignis-btn--secondary ignis-btn--sm">{{ trans('home.vehicles.all') }}</Link>
        </template>
        <div class="ignis-meter" role="meter" :aria-valuenow="data.ready" aria-valuemin="0" :aria-valuemax="data.total" :aria-label="trans('home.vehicles.ready', { ready: String(data.ready), total: String(data.total) })">
            <div class="ignis-meter__head">
                <span>{{ trans('home.kpi.vehicles') }}</span><b class="ignis-meter__value">{{ trans('home.vehicles.of', { ready: String(data.ready), total: String(data.total) }) }}</b>
            </div>
            <div class="ignis-meter__scale" aria-hidden="true">
                <span v-for="i in data.ticks" :key="i" class="ignis-meter__tick" :class="{ 'is-on': i <= data.on }" />
            </div>
        </div>
        <div v-for="row in data.rows" :key="row.id" class="ignis-bezel__item ignis-entry">
            <span class="ignis-glyph ignis-glyph--sm"><FaIcon :icon="glyph(row.service)" /></span>
            <Link :href="row.href" class="ignis-entry__title ignis-mono">{{ row.name }}</Link>
            <span v-if="row.fms_status" class="ignis-chip ignis-chip--sm" :data-tone="fmsTone(row.fms_status)">
                <FaIcon v-if="row.fms_status === '6'" :icon="faIcons.ban" class="size-3" /><b>{{ row.fms_status }}</b> {{ row.fms_label }}
            </span>
            <span v-if="meta(row) || row.state === 'blocked'" class="ignis-entry__meta">
                <span v-if="meta(row)">{{ meta(row) }}</span>
                <span v-if="row.state === 'blocked'" class="ignis-entry__alert">{{ transChoice('vehicles.state.blocked', row.blocking) }}</span>
            </span>
        </div>
    </Bezel>
</template>
