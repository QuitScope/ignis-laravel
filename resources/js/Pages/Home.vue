<script setup lang="ts">
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import '@/components/dashboard';
import FaIcon from '@/components/FaIcon.vue';
import { faIcons } from '@/lib/faIcons';
import { dateLabel, visibleKeys, widgetComponent, type WidgetAreas } from '@/lib/dashboardWidgets';

// Legacy index.php: page head, setup checklist, KPI row, then the grid of main column and side column (320 px from
// 1100 px; without side widgets only the main column). The footer comes from the shell.
const props = defineProps<{ header: { date: string; actions: { label: string; href: string }[] }; layout: WidgetAreas; widgets: Record<string, unknown> }>();

const page = usePage<{ locale: string }>();
const today = computed(() => dateLabel(props.header.date, page.props.locale));
const keys = (area: keyof WidgetAreas) => visibleKeys(props.layout[area], props.widgets);
</script>

<template>
    <div>
        <header class="twplus-page-header">
            <div class="twplus-page-header__copy">
                <h1>{{ trans('dashboard.page_title') }}</h1>
                <p class="twplus-page-header__description">{{ today }}</p>
            </div>
            <div v-if="header.actions.length" class="twplus-page-header__actions">
                <Link v-for="a in header.actions" :key="a.href" :href="a.href" class="ignis-btn ignis-btn--secondary"><FaIcon :icon="faIcons.plus" />{{ trans(a.label) }}</Link>
            </div>
        </header>

        <component :is="widgetComponent(key)" v-for="key in keys('top')" :key="key" :data="widgets[key]" />

        <div v-if="keys('kpi').length" class="ignis-kpis">
            <component :is="widgetComponent(key)" v-for="key in keys('kpi')" :key="key" :data="widgets[key]" />
        </div>

        <div :class="keys('side').length ? 'ignis-dash' : 'ignis-dash ignis-dash--single'">
            <div class="ignis-dash__col">
                <component :is="widgetComponent(key)" v-for="key in keys('main')" :key="key" :data="widgets[key]" />
            </div>
            <div v-if="keys('side').length" class="ignis-dash__col ignis-dash__side">
                <component :is="widgetComponent(key)" v-for="key in keys('side')" :key="key" :data="widgets[key]" />
            </div>
        </div>
    </div>
</template>
