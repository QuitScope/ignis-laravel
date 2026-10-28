<script setup lang="ts">
import { ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { Flag } from '@lucide/vue';
import { formatDate, nextIndex, type Folder, type MessageRow } from '@/lib/mail';

/**
 * One row per mail (listbox): arrows/Home/End move, Enter or Space opens it in the reading pane. Rows carry only the
 * preview text, never the body. Sent and drafts show the recipients, the other folders the sender.
 */
const props = defineProps<{ rows: MessageRow[]; folder: Folder; openId: number | null; read: Set<number>; next: string | null; prev: string | null }>();
const emit = defineEmits<{ open: [id: number] }>();

const items = ref<HTMLElement[]>([]);
const focused = ref(0);

function key(e: KeyboardEvent, i: number) {
    if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        emit('open', props.rows[i].id);
        return;
    }
    const n = nextIndex(e.key, i, props.rows.length);
    if (n === null) return;
    e.preventDefault();
    focused.value = n;
    items.value[n]?.focus();
}

const outgoing = () => props.folder === 'sent' || props.folder === 'drafts';
const unread = (r: MessageRow) => r.unread && !props.read.has(r.id);
</script>

<template>
    <div class="flex min-h-0 flex-col">
        <p v-if="rows.length === 0" class="px-3 py-8 text-center text-sm text-text-2">{{ trans(`mail.empty.${folder}`) }}</p>
        <ul v-else role="listbox" :aria-label="trans(`mail.folder.${folder}`)" class="divide-y divide-border">
            <li
                v-for="(r, i) in rows"
                :key="r.id"
                ref="items"
                role="option"
                :aria-selected="r.id === openId"
                :tabindex="i === focused ? 0 : -1"
                class="cursor-pointer px-3 py-2 text-sm outline-none hover:bg-(--fill-3) focus-visible:ring-2 focus-visible:ring-(--accent)"
                :class="{ 'bg-(--fill-3)': r.id === openId }"
                @click="focused = i; emit('open', r.id)"
                @keydown="key($event, i)"
            >
                <div class="flex items-baseline gap-2">
                    <span v-if="unread(r)" class="size-2 shrink-0 rounded-full bg-(--accent)" :title="trans('mail.list.unread')"><span class="sr-only">{{ trans('mail.list.unread') }}</span></span>
                    <span class="min-w-0 flex-1 truncate" :class="{ 'font-semibold': unread(r) }">
                        <template v-if="outgoing()">{{ trans('mail.list.to') }} {{ r.to.join(', ') || '–' }}</template>
                        <template v-else>{{ r.from.name }}</template>
                    </span>
                    <Flag v-if="r.flagged" class="size-3.5 shrink-0 text-danger" :aria-label="trans('mail.list.flagged')" />
                    <time class="shrink-0 text-xs text-text-2 tabular-nums" :datetime="r.date ?? undefined">{{ formatDate(r.date) }}</time>
                </div>
                <div class="truncate" :class="{ 'font-semibold': unread(r) }">{{ r.subject || trans('mail.list.no_subject') }}</div>
                <div v-if="r.preview" class="truncate text-xs text-text-2">{{ r.preview }}</div>
            </li>
        </ul>
        <nav v-if="next || prev" class="flex justify-between gap-2 p-2 text-sm" :aria-label="trans('mail.list.pages')">
            <Link v-if="prev" :href="prev" preserve-scroll class="rounded-md border border-border px-3 py-1 hover:bg-(--fill-3)">{{ trans('mail.list.newer') }}</Link>
            <span v-else />
            <Link v-if="next" :href="next" preserve-scroll class="rounded-md border border-border px-3 py-1 hover:bg-(--fill-3)">{{ trans('mail.list.older') }}</Link>
        </nav>
    </div>
</template>
