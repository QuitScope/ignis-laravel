<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { CheckCheck } from '@lucide/vue';
import { kindIcon } from '@/components/notifications/icons';
import { xsrfToken } from '@/lib/draftSave';
import type { InboxEntry } from '@/lib/notifications';

// The bell's panel: mounted each time it opens, loads the last five entries (GET /inbox/popover).
const emit = defineEmits<{ count: [unread: number]; navigate: [] }>();

const entries = ref<InboxEntry[] | null>(null);
const failed = ref(false);
const busy = ref(false);

async function load() {
    try {
        const res = await fetch('/inbox/popover', { credentials: 'same-origin', headers: { Accept: 'application/json' } });
        if (!res.ok) throw new Error(String(res.status));
        const data = (await res.json()) as { entries: InboxEntry[]; unread: number };
        entries.value = data.entries;
        failed.value = false;
        emit('count', data.unread);
    } catch {
        failed.value = true;
    }
}

async function markAll() {
    busy.value = true;
    try {
        const res = await fetch('/api/notifications/mark-all-read', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrfToken() },
        });
        if (res.ok) await load();
    } finally {
        busy.value = false;
    }
}

const when = (iso: string | null) => (iso ? new Date(iso).toLocaleString([], { dateStyle: 'short', timeStyle: 'short' }) : '');

onMounted(load);
</script>

<template>
    <div class="flex items-center justify-between gap-2 border-b border-border px-3 py-2">
        <h2 class="text-sm font-semibold">{{ trans('inbox.title') }}</h2>
        <button
            type="button"
            class="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs text-text-2 hover:bg-(--fill-3) disabled:opacity-50"
            :disabled="busy || !entries?.some((e) => !e.is_read)"
            @click="markAll"
        >
            <CheckCheck class="size-3.5" aria-hidden="true" />
            {{ trans('inbox.mark_all') }}
        </button>
    </div>
    <p v-if="failed" class="px-3 py-4 text-sm text-text-2">{{ trans('inbox.load_failed') }}</p>
    <p v-else-if="entries === null" class="px-3 py-4 text-sm text-text-2">{{ trans('inbox.loading') }}</p>
    <p v-else-if="!entries.length" class="px-3 py-4 text-sm text-text-2">{{ trans('inbox.empty') }}</p>
    <ul v-else class="max-h-96 overflow-y-auto">
        <li v-for="e in entries" :key="e.id" class="border-b border-border last:border-b-0">
            <component
                :is="e.href ? Link : 'div'"
                :href="e.href ?? undefined"
                class="flex gap-3 px-3 py-2 text-sm"
                :class="e.href ? 'hover:bg-(--fill-3)' : ''"
                @click="e.href && emit('navigate')"
            >
                <component :is="kindIcon(e.icon)" class="mt-0.5 size-4 shrink-0 text-text-2" aria-hidden="true" />
                <span class="min-w-0 flex-1">
                    <span class="block truncate" :class="e.is_read ? 'text-text-2' : 'font-medium'">
                        <span v-if="!e.is_read" class="sr-only">{{ trans('inbox.unread_marker') }}</span>{{ e.title }}
                    </span>
                    <span v-if="e.message" class="block truncate text-xs text-text-2">{{ e.message }}</span>
                    <span class="block text-xs text-text-2">{{ e.label }} · <time :datetime="e.created_at ?? undefined">{{ when(e.created_at) }}</time></span>
                </span>
                <span v-if="!e.is_read" class="mt-1.5 size-2 shrink-0 rounded-full bg-accent" aria-hidden="true" />
            </component>
        </li>
    </ul>
    <div class="border-t border-border px-3 py-2 text-right">
        <Link href="/inbox" class="text-sm underline" @click="emit('navigate')">{{ trans('inbox.show_all') }}</Link>
    </div>
</template>
