<script setup lang="ts">
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import FaIcon from '@/components/FaIcon.vue';
import { faIcons } from '@/lib/faIcons';
import { since } from '@/lib/dashboardWidgets';

// "eNOTF-Protokolle offen" (legacy kpis.php): red while anything is open, otherwise calm with a check.
const props = defineProps<{ data: { open: number; oldest_at?: string | null; week: number; week_released: number; href: string } }>();

const page = usePage<{ locale: string }>();
const oldest = computed(() => (props.data.oldest_at ? since(props.data.oldest_at, new Date(), page.props.locale) : null));
</script>

<template>
    <Link :href="data.href" class="ignis-kpi" :data-tone="data.open > 0 ? 'danger' : 'ok'">
        <div class="ignis-kpi__top">
            <span class="ignis-kpi__label">
                <FaIcon :icon="faIcons[data.open > 0 ? 'triangle-exclamation' : 'circle-check']" />{{ trans('dashboard.kpi.enotf_open') }}
            </span>
        </div>
        <div class="ignis-kpi__value">{{ data.open }}</div>
        <div class="ignis-kpi__sub">{{ oldest ? trans('dashboard.kpi.oldest', { since: oldest }) : trans('dashboard.kpi.all_released') }}</div>
        <FaIcon :icon="faIcons['arrow-right']" class="ignis-kpi__arrow" />
    </Link>
</template>
