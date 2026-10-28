<script setup lang="ts">
import { nextTick, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import ComposeForm from '@/components/mail/ComposeForm.vue';
import FolderNav from '@/components/mail/FolderNav.vue';
import MailboxSwitcher from '@/components/mail/MailboxSwitcher.vue';
import MessageList from '@/components/mail/MessageList.vue';
import ReadingPane from '@/components/mail/ReadingPane.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { folderHref, mailRequest, type ComposeProps, type Folder, type MailboxRef, type MessageRow, type OpenMessage } from '@/lib/mail';
import { usePulse } from '@/lib/realtime';

/**
 * The workspace (InboxController): folders, the list of the chosen mailbox (cursor pages) and the reading pane. Opening a
 * mail is a partial reload (only `message`); reading it marks it read by PATCH, never by the GET.
 */
const props = defineProps<{
    folder: Folder;
    state: 'ok' | 'none' | 'inactive' | 'unknown';
    draftDays: number;
    selected: number | null;
    mailbox: MailboxRef | null;
    mailboxes: (MailboxRef & { unread: number })[];
    counts: Partial<Record<Folder, number>>;
    messages: { data: MessageRow[]; next: string | null; prev: string | null };
    message: OpenMessage | null;
    compose?: ComposeProps | null;
}>();

const pane = ref<InstanceType<typeof ReadingPane> | null>(null);
const read = ref(new Set<number>());

function open(id: number) {
    if (!props.mailbox) return;
    router.get(folderHref(props.folder, props.mailbox.id, id), {}, {
        only: ['message'],
        preserveState: true,
        preserveScroll: true,
        onSuccess: () => nextTick(() => pane.value?.focus()),
    });
}

watch(() => props.message, async (m) => {
    if (!m || !m.unread || read.value.has(m.id) || !props.mailbox) return;
    const res = await mailRequest('PATCH', `/mail/messages/${m.id}/read`, { read: true, mailbox_id: props.mailbox.id });
    if (res.ok) {
        read.value.add(m.id);
        router.reload({ only: ['counts', 'mailboxes'] });
    }
}, { immediate: true });

function done(close: boolean) {
    if (!props.mailbox) return;
    read.value = new Set();
    if (close) router.visit(folderHref(props.folder, props.mailbox.id), { preserveScroll: true });
    else router.reload({ only: ['message', 'messages', 'counts', 'mailboxes'] });
}

const composing = ref(false);
function startCompose() {
    composing.value = true;
    router.reload({ only: ['compose'] });
}
function composed() {
    composing.value = false;
    router.reload({ only: ['messages', 'counts', 'mailboxes'] });
}

const user = usePage<{ auth: { user: { id: number } | null } }>().props.auth.user;
usePulse(`user.${user?.id ?? 0}`, ['mail'], () => router.reload({ only: ['messages', 'counts', 'mailboxes'] }), { pollMs: 0 });
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold">{{ trans('mail.nav') }}</h1>
                <p v-if="mailbox" class="mt-1 text-sm text-text-2">{{ mailbox.display_name }} &lt;{{ mailbox.address }}&gt;</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <MailboxSwitcher :mailboxes="mailboxes" :selected="selected" :folder="folder" />
                <template v-if="mailbox">
                    <Button as-child variant="ghost" size="sm"><Link :href="`/mail/signature?mailbox=${mailbox.id}`">{{ trans('mail.signature.nav') }}</Link></Button>
                    <Button @click="startCompose">{{ trans('mail.new') }}</Button>
                </template>
            </div>
        </div>

        <p v-if="state !== 'ok'" class="rounded-md bg-(--fill-3) px-3 py-2 text-sm" role="status">{{ trans(`mail.state.${state}`) }}</p>

        <div v-else class="grid gap-4 lg:grid-cols-[12rem_minmax(18rem,26rem)_1fr]">
            <FolderNav :folder="folder" :mailbox-id="selected" :counts="counts" />
            <section class="min-w-0 overflow-hidden rounded-lg border border-border bg-card" :aria-label="trans(`mail.folder.${folder}`)">
                <p v-if="folder === 'drafts'" class="border-b border-border px-3 py-2 text-xs text-text-2">{{ trans('mail.drafts_hint', { days: String(draftDays) }) }}</p>
                <MessageList :rows="messages.data" :folder="folder" :open-id="message?.id ?? null" :read="read" :next="messages.next" :prev="messages.prev" @open="open" />
            </section>
            <section class="min-w-0 rounded-lg border border-border bg-card p-4" :aria-label="trans('mail.pane.label')">
                <ReadingPane v-if="message && mailbox" ref="pane" :message="message" :mailbox-id="mailbox.id" @done="done" />
                <p v-else class="py-8 text-center text-sm text-text-2">{{ trans('mail.pane.empty') }}</p>
            </section>
        </div>

        <Dialog :open="composing" @update:open="(v) => (composing = v)">
            <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-3xl">
                <DialogHeader>
                    <DialogTitle>{{ trans('mail.compose.title.new') }}</DialogTitle>
                </DialogHeader>
                <ComposeForm v-if="compose" v-bind="compose" @sent="composed" @discarded="composing = false" />
                <p v-else class="text-sm text-text-2">{{ trans('mail.compose.loading') }}</p>
            </DialogContent>
        </Dialog>
    </div>
</template>
