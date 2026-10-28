<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import type { FaIconName } from '@/lib/faIcons';
import EmptyState from './EmptyState.vue';

// Legacy .ignis-card--table of the own lists: title with count, actions, table head and, without rows, an empty state
// ("no linked personnel record" or "nothing yet"). "Alle anzeigen" only when there is something to show.
defineProps<{
    titleId: string;
    title: string;
    total: number;
    href: string;
    columns: string[];
    empty: boolean;
    noProfile: boolean;
    emptyIcon: FaIconName;
    emptyTitle: string;
    emptyText: string;
    noProfileText: string;
}>();
</script>

<template>
    <section class="ignis-card ignis-card--table" :aria-labelledby="titleId">
        <div class="ignis-card__header">
            <h2 :id="titleId" class="ignis-card__title">{{ title }} <span class="ignis-count">{{ total }}</span></h2>
            <div v-if="total > 0 || $slots.actions" class="ignis-card__actions">
                <slot name="actions" />
                <Link v-if="total > 0" :href="href" class="ignis-btn ignis-btn--secondary ignis-btn--sm">{{ trans('dashboard.all') }}</Link>
            </div>
        </div>
        <div class="ignis-card__scroll">
            <table class="ignis-table">
                <thead>
                    <tr><th v-for="c in columns" :key="c" scope="col">{{ c }}</th></tr>
                </thead>
                <tbody v-if="!empty">
                    <slot />
                </tbody>
            </table>
        </div>
        <template v-if="empty">
            <EmptyState v-if="noProfile" icon="id-badge" tone="warn" :title="trans('dashboard.no_profile')" :text="noProfileText" />
            <EmptyState v-else :icon="emptyIcon" :title="emptyTitle" :text="emptyText" />
        </template>
    </section>
</template>
