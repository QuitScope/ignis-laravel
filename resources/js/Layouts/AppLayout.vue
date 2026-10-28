<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { trans } from 'laravel-vue-i18n';
import AnnouncementBanner from '@/components/announcements/Banner.vue';
import AppFooter from '@/components/shell/AppFooter.vue';
import MobileDrawer from '@/components/shell/MobileDrawer.vue';
import Sidebar from '@/components/shell/Sidebar.vue';
import Topbar from '@/components/shell/Topbar.vue';
import { activeHref, navGroups, quickActions, settingsGroups, type ModuleSwitches, type NavCounters } from '@/lib/navigation';
import { modalOpen, type PaletteAction } from '@/lib/searchPalette';
import { useFiveMIdentify } from '@/lib/fivemIdentify';

useFiveMIdentify();

type AuthUser = {
    id: number;
    name: string | null;
    username: string;
    role: { name: string; color: string } | null;
    is_super_admin: boolean;
    permissions: string[];
    personnel: { id: number; full_name: string } | null;
};

const page = usePage<{
    app: { name: string; version: string; modules?: ModuleSwitches };
    nav?: { counters?: NavCounters };
    system?: { pending_migrations?: string[] };
    auth: { user: AuthUser | null };
    flash: { status: string | null; status_params?: Record<string, string | number> | null };
}>();

const statusParams = computed(() => Object.fromEntries(Object.entries(page.props.flash?.status_params ?? {}).map(([k, v]) => [k, String(v)])));

const modules = computed(() => page.props.app.modules ?? []);
const groups = computed(() => navGroups(page.props.auth.user, modules.value));
const active = computed(() => activeHref(groups.value, page.url));
const version = computed(() => (/^\d/.test(page.props.app.version) ? `v${page.props.app.version}` : page.props.app.version));
const polledCounters = ref<NavCounters | null>(null);
watch(() => page.props.nav?.counters, () => (polledCounters.value = null));
const counters = computed(() => polledCounters.value ?? page.props.nav?.counters);
const pendingMigrations = computed(() => (/^\/settings\/system(\?|$)/.test(page.url) ? 0 : (page.props.system?.pending_migrations?.length ?? 0)));
const quick = computed(() => quickActions(page.props.auth.user, modules.value));
const paletteActions = computed<PaletteAction[]>(() => {
    const goto = (label: string, href: string, heading: string | null) => ({ label: trans(label), sub: trans('search.action_goto'), href, keywords: `${trans('search.keywords_goto')} ${heading ? trans(heading) : ''}` });

    return [
        ...quick.value.map((a) => ({ label: trans(a.label), sub: trans('search.action_new'), href: a.href, keywords: trans('search.keywords_new') })),
        ...[...groups.value, ...settingsGroups(page.props.auth.user, modules.value)].flatMap((g) => g.items.map((i) => goto(i.label, i.href, g.heading))),
        ...(page.props.auth.user ? [goto('dashboard.nav', '/dashboard', null)] : []),
    ];
});

const read = (): boolean => {
    try {
        return localStorage.getItem('ignis.sidebar') === 'collapsed';
    } catch {
        return false;
    }
};
const collapsed = ref(read());
const drawer = ref(false);
const wideQuery = window.matchMedia('(min-width: 900px)');
const wide = ref(wideQuery.matches);
const onWide = (e: MediaQueryListEvent) => {
    wide.value = e.matches;
    if (e.matches) drawer.value = false;
};
const navExpanded = computed(() => (wide.value ? !collapsed.value : drawer.value));
function toggleCollapsed() {
    collapsed.value = !collapsed.value;
    try {
        localStorage.setItem('ignis.sidebar', collapsed.value ? 'collapsed' : 'open');
    } catch {
    }
}
function toggleNav() {
    if (wide.value) toggleCollapsed();
    else drawer.value = !drawer.value;
}
function onKey(e: KeyboardEvent) {
    const t = e.target instanceof Element ? e.target : null;
    const modified = e.metaKey || ((e.ctrlKey || e.altKey) && !e.getModifierState('AltGraph'));
    if (e.key !== '[' || e.repeat || modified || modalOpen(document)) return;
    if (t?.closest('input, textarea, select, [contenteditable]:not([contenteditable="false"]), [role="dialog"], [role="menu"]')) return;
    e.preventDefault();
    toggleNav();
}
onMounted(() => {
    window.addEventListener('keydown', onKey);
    wideQuery.addEventListener('change', onWide);
});
onUnmounted(() => {
    window.removeEventListener('keydown', onKey);
    wideQuery.removeEventListener('change', onWide);
});
</script>

<template>
    <div class="ignis-app min-h-screen text-text" :class="{ 'is-collapsed': collapsed }">
        <Topbar :user="page.props.auth.user" :actions="paletteActions" :quick="quick" :nav-expanded="navExpanded" :nav-controls="wide ? 'ignis-sidebar' : 'ignis-drawer'" @toggle-nav="toggleNav" @counters="(c: NavCounters) => (polledCounters = c)" />
        <div class="ignis-shell">
            <Sidebar id="ignis-sidebar" :groups="groups" :active="active" :counters="counters" :collapsed="collapsed" :version="version" />
            <main class="ignis-main">
                <div class="ignis-main__content">
                    <p v-if="page.props.flash?.status" class="mb-4 rounded-md bg-(--ok-soft) px-3 py-2 text-sm text-ok" role="status">
                        {{ trans(page.props.flash.status, statusParams) }}
                    </p>
                    <p v-if="pendingMigrations" class="mb-4 rounded-md bg-(--warn-soft) px-3 py-2 text-sm text-warn" role="status">
                        {{ trans('settings.overview.pending_banner', { count: String(pendingMigrations) }) }}
                        <Link href="/settings/system" class="underline">{{ trans('settings.overview.pending_link') }}</Link>
                    </p>
                    <AnnouncementBanner v-if="page.props.auth.user" />
                    <slot />
                </div>
                <AppFooter :version="version" />
            </main>
        </div>
        <MobileDrawer v-model:open="drawer">
            <Sidebar :groups="groups" :active="active" :counters="counters" :version="version" />
        </MobileDrawer>
    </div>
</template>
