<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { Archive, FilePen, Inbox, Send, Trash2 } from '@lucide/vue';
import { FOLDERS, folderHref, type Folder } from '@/lib/mail';

// The folders of the chosen mailbox with their unread count (one query per request, InboxController::unreadCounts).
defineProps<{ folder: Folder; mailboxId: number | null; counts: Partial<Record<Folder, number>> }>();

const icons = { inbox: Inbox, sent: Send, drafts: FilePen, archive: Archive, trash: Trash2 };
</script>

<template>
    <nav :aria-label="trans('mail.folders')">
        <ul class="space-y-0.5 text-sm">
            <li v-for="f in FOLDERS" :key="f">
                <Link
                    :href="folderHref(f, mailboxId)"
                    class="flex items-center gap-2 rounded-md px-2 py-1.5 hover:bg-(--fill-3)"
                    :class="f === folder ? 'bg-(--fill-3) font-medium' : 'text-text-2'"
                    :aria-current="f === folder ? 'page' : undefined"
                >
                    <component :is="icons[f]" class="size-4" aria-hidden="true" />
                    <span class="flex-1">{{ trans(`mail.folder.${f}`) }}</span>
                    <span v-if="counts[f]" class="rounded-full bg-(--accent) px-1.5 text-xs text-white tabular-nums">
                        {{ counts[f] }}<span class="sr-only"> {{ trans('mail.list.unread') }}</span>
                    </span>
                </Link>
            </li>
        </ul>
    </nav>
</template>
