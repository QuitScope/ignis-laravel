<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import FaIcon from '@/components/FaIcon.vue';
import { faIcons } from '@/lib/faIcons';

// "Fahrzeuge einsatzbereit" (legacy kpis.php): ready = FMS 1/2; the sub line names the vehicles without a status, else
// those in status 6. Open blocking defects have their own hint card.
defineProps<{ data: { ready: number; total: number; down: number; unknown: number; blocked: number } }>();
</script>

<template>
    <Link href="/vehicles" class="ignis-kpi">
        <div class="ignis-kpi__top">
            <span class="ignis-kpi__label"><FaIcon :icon="faIcons['truck-medical']" />{{ trans('home.kpi.vehicles') }}</span>
        </div>
        <div class="ignis-kpi__value">{{ data.ready }} <span class="ignis-unit">{{ trans('home.kpi.of', { total: String(data.total) }) }}</span></div>
        <div class="ignis-kpi__sub">
            {{ data.unknown > 0 ? trans('home.kpi.unknown', { count: String(data.unknown) }) : trans('home.kpi.down', { count: String(data.down) }) }}
        </div>
        <FaIcon :icon="faIcons['arrow-right']" class="ignis-kpi__arrow" />
    </Link>
</template>
