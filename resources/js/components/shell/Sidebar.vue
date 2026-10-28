<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import FaIcon from '@/components/FaIcon.vue';
import { faIcons } from '@/lib/faIcons';
import { navBadge, type NavCounters, type NavGroup } from '@/lib/navigation';

// Sidebar of the shell (legacy assets/components/navbar-sidebar.php, skin core): groups from lib/navigation.ts, the active
// entry as aria-current, tablet apps in a new tab, counters, version line. Collapsed (desktop): icons with a title only.
defineProps<{ groups: NavGroup[]; active: string | null; counters: NavCounters | undefined; collapsed?: boolean; version: string }>();
</script>

<template>
    <aside class="ignis-sidebar">
        <nav class="ignis-sidebar__nav" :aria-label="trans('nav.main')">
            <template v-for="group in groups" :key="group.key">
                <div v-if="group.heading" class="ignis-sidebar__group">{{ trans(group.heading) }}</div>
                <template v-for="item in group.items" :key="item.href">
                    <a
                        v-if="item.external"
                        :href="item.href"
                        target="_blank"
                        rel="noopener"
                        class="ignis-sidebar__link"
                        :title="collapsed ? trans(item.label) : undefined"
                        :aria-label="`${trans(item.label)} (${trans('nav.external')})`"
                    >
                        <FaIcon :icon="faIcons[item.icon]" class="ignis-sidebar__icon" />
                        <span class="ignis-sidebar__label">{{ trans(item.label) }}</span>
                        <FaIcon :icon="faIcons['arrow-up-right-from-square']" class="ignis-sidebar__external" />
                    </a>
                    <Link
                        v-else
                        :href="item.href"
                        class="ignis-sidebar__link"
                        :aria-current="item.href === active ? 'page' : undefined"
                        :title="collapsed ? trans(item.label) : undefined"
                    >
                        <FaIcon :icon="faIcons[item.icon]" class="ignis-sidebar__icon" />
                        <span class="ignis-sidebar__label">{{ trans(item.label) }}</span>
                        <template v-if="navBadge(item, counters)">
                            <span class="ignis-sidebar__count" aria-hidden="true">{{ navBadge(item, counters) }}</span>
                            <span class="sr-only">{{ trans('app.nav.counter', { count: navBadge(item, counters) ?? '' }) }}</span>
                        </template>
                    </Link>
                </template>
            </template>
        </nav>
        <div class="ignis-sidebar__version">ıgnıs {{ version }}</div>
    </aside>
</template>
