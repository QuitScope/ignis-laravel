<script setup lang="ts">
import { computed, defineAsyncComponent, onBeforeUnmount, onMounted, ref, shallowRef } from 'vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { EditorApi } from '@/editor/createEditor';
import { draftRequest, sendFinalSave } from '@/lib/draftSave';

const EditorPane = defineAsyncComponent(() => import('@/editor/EditorPane.vue'));

const props = defineProps<{
    document: {
        id: number;
        public_id: string;
        title: string;
        content: Record<string, unknown>;
        lock_version: number;
        gender: number;
        personnel: { id: number; name: string };
    };
    template: { id: number; name: string; is_active: boolean } | null;
    variables: { key: string; label: string }[];
    values: Record<string, string>;
    missingReasons: Record<string, string>;
    readOnly: boolean;
    urls: { save: string; issue: string; delete: string; personnel: string };
    can: { delete: boolean };
}>();

const AUTOSAVE_MS = 30000;
const errors = computed(() => usePage().props.errors as Record<string, string>);
const api = shallowRef<EditorApi | null>(null);

const title = ref(props.document.title);
const dirty = ref(false);
const saving = ref(false);
const savedAt = ref<string | null>(null);
const blocked = ref<'stale' | 'issued' | 'read_only' | null>(props.readOnly ? 'read_only' : null);
const failure = ref<{ kind: 'retry' | 'invalid'; messages: string[] } | null>(null);
const warnings = ref<string[]>([]);
let lockVersion = props.document.lock_version;

const status = computed(() => {
    if (saving.value) return trans('documents.editor.status.saving');
    if (dirty.value) return trans('documents.editor.status.dirty');
    return savedAt.value ? trans('documents.editor.status.saved', { time: savedAt.value }) : '';
});

function onReady(a: EditorApi) {
    api.value = a;
    if (blocked.value) a.lock();
}

let queue: Promise<unknown> = Promise.resolve();

function save(autosave: boolean): Promise<boolean> {
    const next = queue.then(() => send(autosave));
    queue = next.catch(() => false);
    return next;
}

async function send(autosave: boolean): Promise<boolean> {
    if (!api.value || blocked.value) return false;
    if (autosave && !dirty.value) return true;

    const content = JSON.stringify(api.value.getJSON());
    const sentTitle = title.value;
    saving.value = true;
    let res: Response;
    let data: { lock_version?: number; warnings?: string[]; reason?: string; message?: string; errors?: Record<string, string[] | string> } | null = null;
    try {
        res = await fetch(props.urls.save, draftRequest({ content, title: sentTitle, lock_version: lockVersion, autosave }));
        data = await res.json().catch(() => null);
    } catch {
        failure.value = { kind: 'retry', messages: [trans('documents.editor.error.network')] };
        return false;
    } finally {
        saving.value = false;
    }

    if (res.ok && typeof data?.lock_version === 'number') {
        lockVersion = data.lock_version;
        warnings.value = data.warnings ?? [];
        failure.value = null;
        if (JSON.stringify(api.value.getJSON()) === content && title.value === sentTitle) dirty.value = false;
        savedAt.value = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        return true;
    }
    if (res.status === 409) {
        const reason = data?.reason === 'issued' || data?.reason === 'read_only' ? data.reason : 'stale';
        blocked.value = reason;
        api.value.lock();
        return false;
    }
    if (res.status === 422) {
        const messages = Object.values(data?.errors ?? {}).flat();
        failure.value = { kind: 'invalid', messages: messages.length ? messages : [data?.message ?? trans('documents.error.invalid')] };
        return false;
    }
    failure.value = { kind: 'retry', messages: [data?.message && res.status < 500 ? data.message : trans('documents.editor.error.server', { status: String(res.status) })] };
    return false;
}

const reload = () => {
    dirty.value = false;
    window.location.reload();
};

const issueForm = useForm({ accept_missing: false });
const issueNotice = ref('');
async function issue() {
    issueNotice.value = '';
    const items = api.value?.openItems() ?? [];
    const fields = items.filter((i) => i.kind === 'field');
    if (fields.length) {
        api.value?.reveal(fields[0]);
        issueNotice.value = trans('documents.editor.issue.required', { fields: fields.map((f) => f.label).join(', ') });
        return;
    }
    if (blocked.value !== 'read_only' && !(await save(true))) {
        issueNotice.value = trans('documents.editor.issue.save_failed');
        return;
    }

    const missing = items.filter((i) => i.kind === 'variable').map((i) => (i.kind === 'variable' && i.state === 'unknown' ? `{{${i.name}}}` : i.label));
    const question = missing.length
        ? trans('documents.editor.issue.confirm_missing', { names: missing.join(', ') })
        : trans('documents.issue_confirm');
    if (!confirm(question)) return;
    post(missing.length > 0);
}
function post(acceptMissing: boolean) {
    issueForm.accept_missing = acceptMissing;
    issueForm.post(props.urls.issue, {
        preserveScroll: true,
        onError: (e) => {
            if (e.variables && !acceptMissing && confirm(`${e.variables}\n\n${trans('documents.issue_accept_missing')}`)) post(true);
        },
    });
}

const destroy = () => {
    if (confirm(trans('documents.delete_confirm', { title: props.document.title }))) {
        dirty.value = false;
        router.delete(props.urls.delete);
    }
};

const onBeforeUnload = (e: BeforeUnloadEvent) => {
    if (!dirty.value) return;
    e.preventDefault();
    e.returnValue = '';
};
function finalSave() {
    if (!dirty.value || !api.value || blocked.value) return;
    const content = JSON.stringify(api.value.getJSON());
    const sentTitle = title.value;
    dirty.value = false;
    queue = queue.then(() => sendFinalSave(props.urls.save, { content, title: sentTitle, lock_version: lockVersion, autosave: true }));
}
const onVisibility = () => document.visibilityState === 'hidden' && save(true);

let removeRouterGuard: (() => void) | undefined;
let timer: ReturnType<typeof setInterval> | undefined;
onMounted(() => {
    window.addEventListener('beforeunload', onBeforeUnload);
    window.addEventListener('pagehide', finalSave);
    document.addEventListener('visibilitychange', onVisibility);
    removeRouterGuard = router.on('before', (e) => {
        if (dirty.value && e.detail.visit.method === 'get' && !confirm(trans('documents.editor.leave_confirm'))) return false;
    });
    if (!props.readOnly) timer = setInterval(() => save(true), AUTOSAVE_MS);
});
onBeforeUnmount(() => {
    finalSave();
    window.removeEventListener('beforeunload', onBeforeUnload);
    window.removeEventListener('pagehide', finalSave);
    document.removeEventListener('visibilitychange', onVisibility);
    removeRouterGuard?.();
    clearInterval(timer);
});
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-start justify-between gap-2">
            <div>
                <h1 class="text-2xl font-semibold">{{ document.title }}</h1>
                <p class="mt-1 text-sm text-text-2">
                    {{ trans('documents.status.draft') }} · {{ trans('documents.col.public_id') }} {{ document.public_id }} · {{ document.personnel.name }}
                    <template v-if="template"> · {{ trans('documents.template') }}: {{ template.name }}</template>
                </p>
            </div>
            <div class="flex gap-2">
                <Button as-child variant="ghost">
                    <Link :href="urls.personnel">{{ trans('documents.back') }}</Link>
                </Button>
                <Button v-if="can.delete" variant="destructive" @click="destroy">{{ trans('documents.delete') }}</Button>
            </div>
        </div>

        <div class="flex flex-wrap items-end gap-3 rounded-lg border border-border bg-card p-4">
            <div class="min-w-64 flex-1 space-y-1">
                <Label for="document-title">{{ trans('documents.col.title') }}</Label>
                <Input id="document-title" v-model="title" maxlength="200" :disabled="!!blocked" @input="dirty = true" />
            </div>
            <span class="text-sm text-text-2" role="status" aria-live="polite">{{ status }}</span>
            <Button variant="secondary" :disabled="!!blocked || saving || !api" @click="save(false)">{{ trans('documents.editor.save') }}</Button>
            <Button :disabled="issueForm.processing || blocked === 'issued' || blocked === 'stale' || !api" @click="issue">{{ trans('documents.issue') }}</Button>
        </div>

        <p v-if="blocked === 'read_only'" class="rounded-md border border-border bg-card p-3 text-sm" role="status">{{ trans('documents.read_only') }}</p>
        <div v-else-if="blocked === 'stale'" class="flex flex-wrap items-center gap-3 rounded-md border border-warn bg-card p-3 text-sm" role="alert">
            <span>{{ trans('documents.error.stale') }}</span>
            <Button size="sm" @click="reload">{{ trans('documents.editor.reload') }}</Button>
        </div>
        <div v-else-if="blocked === 'issued'" class="flex flex-wrap items-center gap-3 rounded-md border border-border bg-card p-3 text-sm" role="alert">
            <span>{{ trans('documents.error.issued') }}</span>
            <Button as-child size="sm" variant="secondary"><Link :href="`/documents/${document.id}`">{{ trans('documents.open') }}</Link></Button>
        </div>
        <div v-if="failure" class="rounded-md border border-danger bg-card p-3 text-sm" role="alert">
            <p v-for="(m, i) in failure.messages" :key="i" class="text-danger">{{ m }}</p>
            <div v-if="failure.kind === 'retry'" class="mt-2 flex items-center gap-3">
                <span class="text-text-2">{{ trans('documents.editor.error.kept') }}</span>
                <Button size="sm" variant="secondary" :disabled="saving" @click="save(false)">{{ trans('documents.editor.retry') }}</Button>
            </div>
        </div>
        <div v-if="warnings.length" class="rounded-md border border-warn bg-card p-3 text-sm" role="status">
            <p class="font-medium">{{ trans('documents.warnings') }}</p>
            <ul class="mt-1 list-disc pl-5">
                <li v-for="(w, i) in warnings" :key="i">{{ w }}</li>
            </ul>
        </div>
        <p v-if="issueNotice" class="text-sm text-danger" role="alert">{{ issueNotice }}</p>
        <div v-for="key in ['fields', 'variables', 'issue']" :key="key">
            <p v-if="errors[key]" class="text-sm text-danger" role="alert">{{ errors[key] }}</p>
        </div>

        <div id="document-editor">
            <EditorPane
                mode="document"
                :content="document.content"
                :variables="variables"
                :values="values"
                :missing-reasons="missingReasons"
                :gender="document.gender"
                :read-only="readOnly"
                @ready="onReady"
                @change="dirty = true"
            />
        </div>
    </div>
</template>
