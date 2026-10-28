<script setup lang="ts">
import { computed } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { Check, CheckCheck } from '@lucide/vue';
import Pagination from '@/components/Pagination.vue';
import { Button } from '@/components/ui/button';
import { kindIcon } from '@/components/notifications/icons';
import { dayGroups, type InboxEntry } from '@/lib/notifications';

const props = defineProps<{
    entries: { data: InboxEntry[]; from: number | null; to: number | null; total: number; prev_page_url: string | null; next_page_url: string | null };
    filters: { filter: '' | 'unread'; type: string };
    types: { key: string; label: string; icon: string; known: boolean }[];
    unread: number;
    counts: { all: number; unread: number; types: Record<string, number> };
}>();

const groups = computed(() => dayGroups(props.entries.data));
const heading = (g: ReturnType<typeof dayGroups>[number]) =>
    g.day === 'date' ? (g.date?.toLocaleDateString() ?? '–') : trans(`inbox.day.${g.day}`);
const time = (iso: string | null) => (iso ? new Date(iso).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '–');

const url = (change: Partial<{ filter: string; type: string }>) => {
    const q = Object.entries({ ...props.filters, ...change }).filter(([, v]) => v);

    return '/inbox' + (q.length ? '?' + new URLSearchParams(q) : '');
};
const back = computed(() => {
    const q = new URLSearchParams(Object.entries(props.filters).filter(([, v]) => v));
    const page = new URLSearchParams(window.location.search).get('page');
    if (page) q.set('page', page);

    return 'inbox' + (q.size ? '?' + q : '');
});
const markRead = (id?: string) => router.post('/inbox/read', { ...(id ? { id } : {}), return: back.value }, { preserveScroll: true });

const seg = 'rounded-md px-3 py-1 text-sm hover:bg-(--fill-3)';
const segActive = 'bg-(--fill-3) font-medium';
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold">{{ trans('inbox.title') }}</h1>
                <p class="mt-1 text-sm text-text-2">
                    {{ unread === 0 ? trans('inbox.none_unread') : trans('inbox.unread_count', { count: String(unread) }) }}
                </p>
            </div>
            <Button v-if="unread > 0" variant="secondary" @click="markRead()">
                <CheckCheck class="size-4" aria-hidden="true" /> {{ trans('inbox.mark_all') }}
            </Button>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3">
            <nav class="flex gap-1" :aria-label="trans('inbox.filter_read')">
                <Link :href="url({ filter: '' })" :class="[seg, !filters.filter && segActive]" :aria-current="!filters.filter ? 'true' : undefined">
                    {{ trans('inbox.all') }} <span class="tabular-nums text-text-2">{{ counts.all }}</span>
                </Link>
                <Link
                    :href="url({ filter: 'unread' })"
                    :class="[seg, filters.filter === 'unread' && segActive]"
                    :aria-current="filters.filter === 'unread' ? 'true' : undefined"
                >
                    {{ trans('inbox.unread') }} <span class="tabular-nums text-text-2">{{ counts.unread }}</span>
                </Link>
            </nav>
            <nav class="flex flex-wrap gap-1" :aria-label="trans('inbox.filter_type')">
                <Link :href="url({ type: '' })" :class="[seg, !filters.type && segActive]" :aria-current="!filters.type ? 'true' : undefined">
                    {{ trans('inbox.all_types') }} <span class="tabular-nums text-text-2">{{ counts.types[''] ?? 0 }}</span>
                </Link>
                <Link
                    v-for="t in types"
                    :key="t.key"
                    :href="url({ type: t.key })"
                    :class="[seg, 'inline-flex items-center gap-1.5', filters.type === t.key && segActive]"
                    :aria-current="filters.type === t.key ? 'true' : undefined"
                >
                    <component :is="kindIcon(t.icon)" class="size-3.5" aria-hidden="true" />
                    {{ t.label }} <span class="tabular-nums text-text-2">{{ counts.types[t.key] ?? 0 }}</span>
                </Link>
            </nav>
        </div>

        <div v-if="!entries.data.length" class="rounded-lg border border-border p-6 text-center text-sm text-text-2">
            <template v-if="filters.filter || filters.type">
                <p>{{ trans('inbox.empty_filtered') }}</p>
                <Link href="/inbox" class="mt-2 inline-block underline">{{ trans('inbox.show_all') }}</Link>
            </template>
            <p v-else>{{ trans('inbox.empty_page') }}</p>
        </div>

        <section v-for="g in groups" :key="g.key" :aria-labelledby="`day-${g.key}`" class="space-y-1">
            <h2 :id="`day-${g.key}`" class="text-xs font-medium uppercase tracking-wide text-text-2">{{ heading(g) }}</h2>
            <ul class="divide-y divide-border rounded-lg border border-border">
                <li v-for="e in g.entries" :key="e.id" class="flex items-center gap-2 pr-2" :class="!e.is_read && 'bg-(--accent-soft)'">
                    <component :is="e.href ? Link : 'div'" :href="e.href ?? undefined" class="flex min-w-0 flex-1 items-start gap-3 px-3 py-2 text-sm">
                        <component :is="kindIcon(e.icon)" class="mt-0.5 size-4 shrink-0 text-text-2" aria-hidden="true" />
                        <span class="min-w-0 flex-1">
                            <span class="block" :class="!e.is_read && 'font-semibold'">
                                <span v-if="!e.is_read" class="sr-only">{{ trans('inbox.unread_marker') }}</span>{{ e.title }}
                            </span>
                            <span v-if="e.message" class="block text-text-2">{{ e.message }}</span>
                        </span>
                        <span class="flex shrink-0 flex-col items-end gap-1 text-xs text-text-2">
                            <span class="rounded-full bg-(--fill-3) px-2 py-0.5" :class="!e.known && 'italic'">{{ e.label }}</span>
                            <time :datetime="e.created_at ?? undefined" class="tabular-nums">{{ time(e.created_at) }}</time>
                        </span>
                    </component>
                    <Button v-if="!e.is_read" variant="ghost" size="sm" :aria-label="trans('inbox.mark_one')" :title="trans('inbox.mark_one')" @click="markRead(e.id)">
                        <Check class="size-4" aria-hidden="true" />
                    </Button>
                </li>
            </ul>
        </section>

        <Pagination
            v-if="entries.total > entries.data.length"
            :from="entries.from"
            :to="entries.to"
            :total="entries.total"
            :prev-url="entries.prev_page_url"
            :next-url="entries.next_page_url"
        />
    </div>
</template>
