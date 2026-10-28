<script setup lang="ts">
import { ref, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { Paperclip } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { composeHref, errorText, formatDate, formatSize, mailRequest, type OpenMessage } from '@/lib/mail';

/**
 * The opened mail. `message.html` is MailContentRenderer::html() output (server-side, allowlisted, escaped): the ONLY
 * v-html of the mail module (tests/js/kbProse.test.ts). The header is the server's visibleHeader (BCC rule), never raw.
 * Actions change the own copy of the chosen mailbox; `done` tells the page whether to close the pane.
 */
const props = defineProps<{ message: OpenMessage; mailboxId: number }>();
const emit = defineEmits<{ done: [close: boolean] }>();

const heading = ref<HTMLElement | null>(null);
const error = ref('');
const confirmDelete = ref(false);
watch(() => props.message.id, () => {
    error.value = '';
    confirmDelete.value = false;
});
defineExpose({ focus: () => heading.value?.focus() });

async function act(method: string, path: string, body: Record<string, unknown>, left: boolean) {
    const res = await mailRequest(method, `/mail/messages/${props.message.id}${path}`, { ...body, mailbox_id: props.mailboxId });
    error.value = errorText(res) ?? (res.ok ? '' : trans('mail.action.failed'));
    if (res.ok) emit('done', left);
}
const move = (folder: string) => act('PATCH', '/folder', { folder }, true);
const flag = () => act('PATCH', '/flag', { flagged: !props.message.flagged }, false);
const unread = () => act('PATCH', '/read', { read: false }, true);
const destroy = () => act('DELETE', '', {}, true);

const rows = (m: OpenMessage) => (['to', 'cc', 'bcc'] as const).filter((k) => m.header[k].length > 0);
</script>

<template>
    <article class="space-y-4" aria-live="polite" :aria-labelledby="`mail-subject-${message.id}`">
        <header class="space-y-2">
            <h2 :id="`mail-subject-${message.id}`" ref="heading" tabindex="-1" class="text-xl font-semibold outline-none">
                {{ message.subject || trans('mail.list.no_subject') }}
            </h2>
            <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-0.5 text-sm">
                <dt class="text-text-2">{{ trans('mail.pane.from') }}</dt>
                <dd>{{ message.from.name }} &lt;{{ message.from.address }}&gt;<span v-if="message.sentBy" class="text-text-2"> · {{ trans('mail.pane.sent_by', { name: message.sentBy }) }}</span></dd>
                <template v-for="k in rows(message)" :key="k">
                    <dt class="text-text-2">{{ trans(`mail.compose.${k}`) }}</dt>
                    <dd class="break-all">{{ message.header[k].join(', ') }}</dd>
                </template>
                <dt class="text-text-2">{{ trans('mail.pane.date') }}</dt>
                <dd><time :datetime="message.date ?? undefined">{{ formatDate(message.date) }}</time></dd>
            </dl>
            <div class="flex flex-wrap gap-2" role="toolbar" :aria-label="trans('mail.pane.actions')">
                <template v-if="message.draft">
                    <Button as-child size="sm"><Link :href="composeHref('draft', message.id, mailboxId)">{{ trans('mail.action.edit') }}</Link></Button>
                    <Button size="sm" variant="ghost" @click="confirmDelete = true">{{ trans('mail.action.discard') }}</Button>
                </template>
                <template v-else>
                    <Button as-child size="sm" variant="outline"><Link :href="composeHref('reply', message.id, mailboxId)">{{ trans('mail.action.reply') }}</Link></Button>
                    <Button as-child size="sm" variant="outline"><Link :href="composeHref('reply-all', message.id, mailboxId)">{{ trans('mail.action.reply_all') }}</Link></Button>
                    <Button as-child size="sm" variant="outline"><Link :href="composeHref('forward', message.id, mailboxId)">{{ trans('mail.action.forward') }}</Link></Button>
                    <Button size="sm" variant="ghost" :aria-pressed="message.flagged" @click="flag">{{ trans(message.flagged ? 'mail.action.unflag' : 'mail.action.flag') }}</Button>
                    <Button v-if="message.role !== 'sender'" size="sm" variant="ghost" @click="unread">{{ trans('mail.action.unread') }}</Button>
                    <Button v-if="message.folder !== 'archive' && message.folder !== 'trash'" size="sm" variant="ghost" @click="move('archive')">{{ trans('mail.action.archive') }}</Button>
                    <Button v-if="message.folder === 'archive'" size="sm" variant="ghost" @click="move('restore')">{{ trans('mail.action.restore') }}</Button>
                    <Button v-if="message.folder !== 'trash'" size="sm" variant="ghost" @click="move('trash')">{{ trans('mail.action.trash') }}</Button>
                    <template v-else>
                        <Button size="sm" variant="ghost" @click="move('restore')">{{ trans('mail.action.restore') }}</Button>
                        <Button size="sm" variant="ghost" @click="confirmDelete = true">{{ trans('mail.action.delete') }}</Button>
                    </template>
                </template>
            </div>
            <div v-if="confirmDelete" class="flex flex-wrap items-center gap-2 rounded-md border border-border p-2 text-sm" role="alertdialog" :aria-label="trans('mail.action.delete')">
                <span>{{ trans(message.draft ? 'mail.action.discard_confirm' : 'mail.action.delete_confirm') }}</span>
                <Button size="sm" variant="destructive" @click="destroy">{{ trans(message.draft ? 'mail.action.discard' : 'mail.action.delete') }}</Button>
                <Button size="sm" variant="ghost" @click="confirmDelete = false">{{ trans('mail.action.cancel') }}</Button>
            </div>
            <p v-if="error" class="text-sm text-danger" role="alert">{{ error }}</p>
        </header>
        <ul v-if="message.attachments?.length" class="flex flex-wrap gap-2 text-sm" :aria-label="trans('mail.attachment.list')">
            <li v-for="a in message.attachments" :key="a.id">
                <span v-if="a.missing" class="inline-flex items-center gap-1 rounded-md border border-border px-2 py-1 text-text-2">
                    <Paperclip class="size-4" aria-hidden="true" />{{ a.name }} · {{ trans('mail.attachment.missing') }}
                </span>
                <!-- A plain link: the server answers with Content-Disposition attachment, never inline. -->
                <a v-else :href="`/mail/attachments/${a.id}`" class="inline-flex items-center gap-1 rounded-md border border-border px-2 py-1 hover:bg-(--fill-3)">
                    <Paperclip class="size-4" aria-hidden="true" />{{ a.name }} <span class="text-xs text-text-2">{{ formatSize(a.size) }}</span>
                </a>
            </li>
        </ul>
        <p v-if="message.broken" class="rounded-md bg-(--fill-3) px-3 py-2 text-sm text-text-2" role="note">{{ trans('mail.pane.broken') }}</p>
        <!-- eslint-disable-next-line vue/no-v-html -->
        <div v-else class="mail-prose" v-html="message.html" />
    </article>
</template>

<style scoped>
.mail-prose { line-height: 1.5; overflow-wrap: anywhere; }
.mail-prose :deep(p) { margin: 0 0 0.5em; min-height: 1em; }
.mail-prose :deep(ul) { list-style: disc; padding-left: 1.5em; }
.mail-prose :deep(ol) { list-style: decimal; padding-left: 1.5em; }
.mail-prose :deep(blockquote) { border-left: 3px solid var(--border-strong); padding-left: 0.8em; color: var(--text-2); }
.mail-prose :deep(a) { color: var(--accent); text-decoration: underline; }
.mail-prose :deep(.mail-badge) { display: inline; height: 1.2em; width: auto; margin-right: 0.3em; vertical-align: text-bottom; }
</style>
