<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import BrandMark from '@/components/BrandMark.vue';
import NotificationBell from '@/components/notifications/Bell.vue';
import SearchField from '@/components/search/SearchField.vue';
import AccountMenu, { type AccountUser } from '@/components/shell/AccountMenu.vue';
import NewMenu from '@/components/shell/NewMenu.vue';
import type { NavCounters, NavItem } from '@/lib/navigation';
import type { PaletteAction } from '@/lib/searchPalette';

// Topbar of the shell (legacy assets/components/topbar.php, skin core): menu toggle and lockup, search in the
// middle, "+ Neu", bell and account menu on the right. Look in resources/css/skin.css (.ignis-topbar*).
// navExpanded/navControls: state and id of the sidebar (desktop) or drawer (below 900 px) the hamburger toggles.
defineProps<{ user: AccountUser | null; actions: PaletteAction[]; quick: NavItem[]; navExpanded: boolean; navControls: string }>();
const emit = defineEmits<{ 'toggle-nav': []; counters: [NavCounters] }>();
const page = usePage<{ app: { name: string } }>();
</script>

<template>
    <header class="ignis-topbar">
        <div class="ignis-topbar__identity">
            <button type="button" class="ignis-topbar__toggle" :aria-label="trans('app.topbar.toggle_nav')" :title="trans('app.topbar.toggle_nav')" :aria-expanded="navExpanded" :aria-controls="navControls" @click="emit('toggle-nav')">
                <span class="ignis-burger" aria-hidden="true"><span /><span /><span /></span>
            </button>
            <Link href="/" class="ignis-topbar__mark" :aria-label="page.props.app.name"><BrandMark /></Link>
        </div>
        <template v-if="user">
            <SearchField :actions="actions" />
            <div class="ignis-topbar__actions">
                <NewMenu :actions="quick" />
                <NotificationBell @counters="(c: NavCounters) => emit('counters', c)" />
                <AccountMenu :user="user" />
            </div>
        </template>
    </header>
</template>
