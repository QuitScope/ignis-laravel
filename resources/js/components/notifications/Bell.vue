<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import FaIcon from '@/components/FaIcon.vue';
import { faIcons } from '@/lib/faIcons';
import { PopoverContent, PopoverPortal, PopoverRoot, PopoverTrigger } from 'reka-ui';
import NotificationPopover from '@/components/notifications/Popover.vue';
import { advancePoll, POLL_MS, titleWithCount, type InboxEntry } from '@/lib/notifications';
import type { NavCounters } from '@/lib/navigation';
import { usePulse } from '@/lib/realtime';

// Topbar bell (legacy assets/js/navbar/notifications.js): the counter comes with every page (`notifications.unread`)
// and is refreshed by polling every 30 s while the tab is visible, at once when it becomes visible again. A new
// entry is announced once as a toast. Signed out (401/419) stops the polling; other errors back off. The same poll
// carries the sidebar counters (`navCounters`), handed to the layout: one request for both.
const emit = defineEmits<{ counters: [NavCounters] }>();
const page = usePage<{ notifications: { unread: number | null }; auth: { user: { id: number } | null } }>();
const count = ref(page.props.notifications?.unread ?? 0);
watch(
    () => page.props.notifications?.unread,
    (n) => {
        if (typeof n === 'number') count.value = n;
    },
);

const open = ref(false);
const toast = ref<InboxEntry | null>(null);
const toasted = new Set<string>();
let toastTimer: ReturnType<typeof setTimeout> | undefined;
let pollTimer: ReturnType<typeof setTimeout> | undefined;
let delay = POLL_MS;
let stopped = false;
let inFlight = false;
let again = false;
let since = new Date().toISOString();

const label = computed(() => (count.value > 0 ? trans('inbox.bell_unread', { count: String(count.value) }) : trans('inbox.bell')));

const { live } = usePulse(`user.${page.props.auth.user?.id ?? 0}`, ['inbox', 'mail'], () => void poll(), { pollMs: 0 });
watch(live, () => schedule());

function schedule() {
    clearTimeout(pollTimer);
    if (!stopped) pollTimer = setTimeout(poll, live.value && delay === POLL_MS ? 120_000 : delay);
}

async function poll() {
    if (inFlight) {
        again = true;
        return;
    }
    if (stopped || document.visibilityState !== 'visible') return schedule();
    inFlight = true;
    const started = new Date().toISOString();
    let status = 0;
    try {
        const res = await fetch(`/api/notifications/poll?since=${encodeURIComponent(since)}`, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        });
        status = res.status;
        if (res.ok) {
            const data = (await res.json()) as { success: boolean; unreadCount: number; new: InboxEntry[]; navCounters?: NavCounters };
            const fresh = data.unreadCount > count.value ? data.new.find((n) => !toasted.has(n.id)) : undefined;
            if (fresh) {
                toasted.add(fresh.id);
                toast.value = fresh;
                clearTimeout(toastTimer);
                toastTimer = setTimeout(() => (toast.value = null), 6000);
            }
            count.value = data.unreadCount;
            if (data.navCounters) emit('counters', data.navCounters);
            since = started;
        }
    } catch {
    }
    inFlight = false;
    ({ stopped, delay } = advancePoll(stopped, status, delay));
    if (again && !stopped && delay === POLL_MS) {
        again = false;
        void poll();
    } else {
        again = false;
        schedule();
    }
}

function onVisible() {
    if (document.visibilityState === 'visible') poll();
}

watch(count, (n) => (document.title = titleWithCount(document.title, n)), { immediate: true });

onMounted(() => {
    document.addEventListener('visibilitychange', onVisible);
    schedule();
});
onBeforeUnmount(() => {
    stopped = true;
    clearTimeout(pollTimer);
    clearTimeout(toastTimer);
    document.removeEventListener('visibilitychange', onVisible);
    document.title = titleWithCount(document.title, 0);
});
</script>

<template>
    <PopoverRoot v-model:open="open">
        <PopoverTrigger class="ignis-btn ignis-btn--secondary ignis-btn--icon" :aria-label="label" :title="trans('inbox.bell')">
            <FaIcon :icon="faIcons.bell" />
            <span v-if="count > 0" class="ignis-btn__dot" aria-hidden="true" />
        </PopoverTrigger>
        <PopoverPortal>
            <PopoverContent
                align="end"
                :side-offset="8"
                :collision-padding="8"
                class="z-30 w-100 max-w-[calc(100vw-1rem)] rounded-(--radius-item) border border-(--hairline) bg-(--overlay) text-text shadow-(--shadow-pop)"
            >
                <NotificationPopover @count="(n: number) => (count = n)" @navigate="open = false" />
            </PopoverContent>
        </PopoverPortal>
    </PopoverRoot>
    <!-- Polite live region: the newest entry, read out once; its link only from the server's href. -->
    <div class="pointer-events-none fixed bottom-4 right-4 z-40 w-80 max-w-[calc(100vw-2rem)]" role="status" aria-live="polite">
        <div v-if="toast" class="pointer-events-auto rounded-lg border border-border bg-card p-3 text-sm shadow-(--shadow)">
            <p class="text-xs text-text-2">{{ trans('inbox.new_toast', { label: toast.label }) }}</p>
            <Link v-if="toast.href" :href="toast.href" class="font-medium underline">{{ toast.title }}</Link>
            <p v-else class="font-medium">{{ toast.title }}</p>
        </div>
    </div>
</template>
