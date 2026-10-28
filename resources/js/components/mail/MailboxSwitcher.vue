<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { folderHref, type Folder, type MailboxRef } from '@/lib/mail';

// Personal and group mailboxes this account opens; switching keeps the folder (the server remembers the choice).
const props = defineProps<{ mailboxes: (MailboxRef & { unread: number })[]; selected: number | null; folder: Folder }>();

function choose(e: Event) {
    router.get(folderHref(props.folder, Number((e.target as HTMLSelectElement).value)));
}
</script>

<template>
    <label v-if="mailboxes.length > 1" class="flex items-center gap-2 text-sm">
        <span class="text-text-2">{{ trans('mail.mailbox.switch') }}</span>
        <select class="h-9 rounded-md border border-border bg-card px-2" :value="selected ?? undefined" @change="choose">
            <option v-for="m in mailboxes" :key="m.id" :value="m.id">
                {{ m.display_name }} &lt;{{ m.address }}&gt;{{ m.kind === 'group' ? ` · ${trans('mail.kind.group')}` : '' }}{{ m.unread ? ` (${m.unread})` : '' }}
            </option>
        </select>
    </label>
</template>
