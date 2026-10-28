<script setup lang="ts">
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { trans } from 'laravel-vue-i18n';
import { Paperclip, X } from '@lucide/vue';
import MailEditor from '@/components/mail/MailEditor.vue';
import RecipientInput from '@/components/mail/RecipientInput.vue';
import { Button } from '@/components/ui/button';
import {
    ATTACHMENT_ACCEPT, ATTACHMENT_MAX_MB, MAIL_ATTACHMENTS_MAX_MB, debounce, errorText, formatSize, mailRequest, uploadAttachment,
    type AddressEntry, type AttachmentRef, type ComposeProps, type MailResponse,
} from '@/lib/mail';

/**
 * The compose form (page and workspace dialog). Opening writes nothing; the first change creates the draft
 * (POST /mail/drafts, with in_reply_to / forward_from), later changes save it in place (PUT), debounced and one request
 * at a time. A 409 means the mail went out meanwhile: autosave stops. Sending posts every field, so the server never
 * falls back to an older saved state.
 */
const props = defineProps<ComposeProps>();
const emit = defineEmits<{ sent: [unresolved: string[]]; discarded: [] }>();

const form = reactive({
    subject: props.subject,
    to: [...props.recipients.to],
    cc: [...props.recipients.cc],
    bcc: [...props.recipients.bcc],
    body: props.body,
});
const showCopies = ref(form.cc.length > 0 || form.bcc.length > 0);
const known = new Map<string, AddressEntry>();
const draftId = ref(props.draftId);
const status = ref<'' | 'saving' | 'saved'>('');
const error = ref('');
const closed = ref(false);
const sending = ref(false);
let dirty = false;
let queue: Promise<unknown> = Promise.resolve();

const fields = () => ({
    mailbox_id: props.mailbox.id, subject: form.subject, body_json: JSON.stringify(form.body), to: form.to, cc: form.cc, bcc: form.bcc,
});

function report(res: MailResponse<object>) {
    if (res.status === 409) closed.value = true;
    error.value = res.status === 0 ? trans('mail.compose.offline') : (errorText(res) ?? trans('mail.action.failed'));
}

function save(): Promise<unknown> {
    queue = queue.then(async () => {
        if (closed.value || !dirty) return;
        dirty = false;
        status.value = 'saving';
        const res = draftId.value === null
            ? await mailRequest<{ messageId: number }>('POST', '/mail/drafts', { ...fields(), in_reply_to: props.inReplyTo, forward_from: props.forwardFrom })
            : await mailRequest('PUT', `/mail/drafts/${draftId.value}`, fields());
        if (!res.ok) {
            dirty = true;
            status.value = '';
            return report(res);
        }
        if (draftId.value === null) {
            const created = res.data as { messageId: number; attachments?: AttachmentRef[] };
            draftId.value = created.messageId;
            attachments.value.push(...(created.attachments ?? []));
        }
        error.value = '';
        status.value = 'saved';
    });

    return queue;
}
const autosave = debounce(() => void save(), 1500);
watch(form, () => {
    dirty = true;
    autosave();
}, { deep: true });
onBeforeUnmount(() => {
    autosave.cancel();
    void save();
});

async function send() {
    autosave.cancel();
    sending.value = true;
    dirty = dirty || draftId.value === null;
    await save();
    if (draftId.value !== null && !closed.value) {
        const res = await mailRequest<{ unresolvedAddresses: string[] }>('POST', `/mail/drafts/${draftId.value}/send`, fields());
        if (res.ok) {
            closed.value = true;
            emit('sent', (res.data as { unresolvedAddresses: string[] }).unresolvedAddresses ?? []);
        } else {
            report(res);
        }
    }
    sending.value = false;
}

async function discard() {
    autosave.cancel();
    closed.value = true;
    await queue;
    if (draftId.value !== null) await mailRequest('DELETE', `/mail/messages/${draftId.value}`, { mailbox_id: props.mailbox.id });
    emit('discarded');
}

type Upload = { key: number; name: string; progress: number; error: string };
const attachments = ref<AttachmentRef[]>([...(props.attachments ?? [])]);
const uploads = ref<Upload[]>([]);
const uploading = computed(() => uploads.value.some((u) => u.error === ''));
const fileInput = ref<HTMLInputElement | null>(null);
const dragging = ref(false);
let uploadKey = 0;

async function upload(file: File) {
    const item = reactive<Upload>({ key: ++uploadKey, name: file.name, progress: 0, error: '' });
    uploads.value.push(item);
    if (file.size > ATTACHMENT_MAX_MB * 1024 * 1024) {
        item.error = trans('mail.attachment.too_large', { max: String(ATTACHMENT_MAX_MB) });
        return;
    }
    if (draftId.value === null) {
        dirty = true;
        await save();
    }
    if (draftId.value === null || closed.value) {
        item.error = error.value || trans('mail.attachment.failed');
        return;
    }
    const res = await uploadAttachment(draftId.value, file, props.mailbox.id, (p) => (item.progress = p));
    if (res.ok) {
        attachments.value.push(res.data.attachment);
        dropUpload(item.key);
        return;
    }
    if (res.status === 409) closed.value = true;
    item.error = res.status === 0 ? trans('mail.compose.offline') : (errorText(res) ?? trans('mail.attachment.failed'));
}
const attach = (files: FileList | null | undefined) => Array.from(files ?? []).forEach((f) => void upload(f));
const dropUpload = (key: number) => (uploads.value = uploads.value.filter((u) => u.key !== key));
function picked(e: Event) {
    const input = e.target as HTMLInputElement;
    attach(input.files);
    input.value = ''; // the same file may be picked again
}
function drop(e: DragEvent) {
    dragging.value = false;
    if (!closed.value) attach(e.dataTransfer?.files);
}
async function removeAttachment(a: AttachmentRef) {
    const res = await mailRequest('DELETE', `/mail/attachments/${a.id}`, { mailbox_id: props.mailbox.id });
    if (res.ok) attachments.value = attachments.value.filter((x) => x.id !== a.id);
    else report(res);
}
</script>

<template>
    <form class="space-y-3" :aria-busy="sending" @submit.prevent="send" @dragover.prevent="dragging = true" @dragleave="dragging = false" @drop.prevent="drop">
        <p class="text-sm text-text-2">{{ trans('mail.compose.from') }}: {{ mailbox.display_name }} &lt;{{ mailbox.address }}&gt;</p>
        <div class="grid gap-2 sm:grid-cols-[6rem_1fr] sm:items-center">
            <label :for="`mail-to-${mailbox.id}`" class="text-sm">{{ trans('mail.compose.to') }}</label>
            <div class="flex items-start gap-2">
                <RecipientInput :id="`mail-to-${mailbox.id}`" v-model="form.to" :label="trans('mail.compose.to')" :known="known" class="flex-1" />
                <Button v-if="!showCopies" type="button" variant="ghost" size="sm" @click="showCopies = true">{{ trans('mail.compose.add_cc') }}</Button>
            </div>
            <template v-if="showCopies">
                <label :for="`mail-cc-${mailbox.id}`" class="text-sm">{{ trans('mail.compose.cc') }}</label>
                <RecipientInput :id="`mail-cc-${mailbox.id}`" v-model="form.cc" :label="trans('mail.compose.cc')" :known="known" />
                <label :for="`mail-bcc-${mailbox.id}`" class="text-sm">{{ trans('mail.compose.bcc') }}</label>
                <RecipientInput :id="`mail-bcc-${mailbox.id}`" v-model="form.bcc" :label="trans('mail.compose.bcc')" :known="known" />
            </template>
            <label :for="`mail-subject-${mailbox.id}`" class="text-sm">{{ trans('mail.compose.subject') }}</label>
            <input :id="`mail-subject-${mailbox.id}`" v-model="form.subject" type="text" maxlength="255" class="h-9 w-full rounded-md border border-border bg-transparent px-2 text-sm" />
        </div>
        <MailEditor v-model="form.body" :label="trans('mail.editor.label')" />
        <div class="space-y-2 rounded-md border border-dashed border-border px-3 py-2 text-sm" :class="{ 'border-(--accent)': dragging }">
            <div class="flex flex-wrap items-center gap-2">
                <Paperclip class="size-4 text-text-2" aria-hidden="true" />
                <input ref="fileInput" type="file" multiple class="hidden" :accept="ATTACHMENT_ACCEPT" @change="picked" />
                <Button type="button" variant="outline" size="sm" :disabled="closed" @click="fileInput?.click()">{{ trans('mail.attachment.add') }}</Button>
                <span class="text-text-2">{{ trans('mail.attachment.drop') }}</span>
            </div>
            <p class="text-xs text-text-2">{{ trans('mail.attachment.hint', { file: String(ATTACHMENT_MAX_MB), mail: String(MAIL_ATTACHMENTS_MAX_MB) }) }}</p>
            <p v-if="draftId === null && forwardNames.length" class="text-xs text-text-2">{{ trans('mail.attachment.forwarded', { names: forwardNames.join(', ') }) }}</p>
            <ul v-if="attachments.length || uploads.length" class="space-y-1" :aria-label="trans('mail.attachment.list')">
                <li v-for="a in attachments" :key="a.id" class="flex items-center gap-2">
                    <span class="truncate">{{ a.name }}</span>
                    <span class="shrink-0 text-xs text-text-2">{{ formatSize(a.size) }}</span>
                    <Button type="button" variant="ghost" size="icon-sm" :disabled="closed" :aria-label="trans('mail.attachment.remove', { name: a.name })" @click="removeAttachment(a)">
                        <X class="size-4" aria-hidden="true" />
                    </Button>
                </li>
                <li v-for="u in uploads" :key="`upload-${u.key}`" class="flex items-center gap-2">
                    <span class="truncate">{{ u.name }}</span>
                    <progress v-if="!u.error" class="h-2 w-24" max="100" :value="u.progress" :aria-label="trans('mail.attachment.uploading', { name: u.name })" />
                    <template v-else>
                        <span class="text-danger" role="alert">{{ u.error }}</span>
                        <Button type="button" variant="ghost" size="icon-sm" :aria-label="trans('mail.attachment.remove', { name: u.name })" @click="dropUpload(u.key)">
                            <X class="size-4" aria-hidden="true" />
                        </Button>
                    </template>
                </li>
            </ul>
        </div>
        <p v-if="error" class="text-sm text-danger" role="alert">{{ error }}</p>
        <div class="flex flex-wrap items-center gap-2">
            <Button type="submit" :disabled="sending || closed || uploading">{{ trans(sending ? 'mail.compose.sending' : 'mail.compose.send') }}</Button>
            <Button type="button" variant="ghost" :disabled="sending" @click="discard">{{ trans('mail.compose.discard') }}</Button>
            <span class="text-xs text-text-2" role="status">{{ status ? trans(`mail.compose.${status}`) : '' }}</span>
        </div>
    </form>
</template>
