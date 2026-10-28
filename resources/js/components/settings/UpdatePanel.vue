<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { trans } from 'laravel-vue-i18n';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { formatBytes } from '@/lib/duration';
import { xsrfToken } from '@/lib/draftSave';
import { extraStep, percent, runStarted, stepList, viewOf, type UpdateStatus } from '@/lib/updateProgress';

type Backup = { name: string; created_at: string; from: string; to: string; size_bytes: number };

const props = defineProps<{
    version: string;
    updates: { check_enabled: boolean; latest_version: string | null; release_url: string | null };
    update: { can_self_update: boolean; reason: string | null; repo_configured: boolean };
    locale: string;
}>();

const BASE = '/settings/system/update';
const card = 'rounded-lg border border-border bg-card p-4 shadow-(--shadow)';

const latest = ref(props.updates.latest_version);
const releaseUrl = ref(props.updates.release_url);
const status = ref<UpdateStatus | null>(null);
const backups = ref<Backup[]>([]);
const notice = ref('');
const error = ref('');
const busy = ref(false);
const offline = ref(false);
const startOpen = ref(false);
const rollbackOpen = ref(false);
const withoutTrial = ref(false);
const waiting = ref<{ since: number; before: string | null } | null>(null);
const noStart = ref(false);
let timer: ReturnType<typeof setTimeout> | undefined;

const view = computed(() => (status.value ? viewOf(status.value) : 'idle'));
const running = computed(() => view.value === 'running' || waiting.value !== null);
const steps = computed(() => (status.value ? stepList(status.value.state) : []));
const rollingBack = computed(() => status.value?.state.status === 'rolling_back');
const extra = computed(() => (status.value ? extraStep(status.value.state) : null));
const docker = computed(() => status.value?.driver === 'docker');
const logDir = computed(() => status.value?.paths?.log ?? 'storage/logs');
const blocker = computed(() => status.value?.blocker ?? props.update.reason);
const installed = computed(() => status.value?.installed ?? props.version);
const preflight = computed(() => status.value?.preflight ?? null);
const canStart = computed(() => !blocker.value && !preflight.value && !!latest.value && latest.value !== installed.value && !running.value && !['stale', 'failed_rollback', 'unknown'].includes(view.value));
const canRollback = computed(() => !!status.value?.backup_available && !running.value && view.value !== 'stale');
const when = (iso: string) => new Date(iso).toLocaleString(props.locale);

async function api(method: 'GET' | 'POST', path: string, body?: unknown): Promise<{ ok: boolean; status: number; json: any }> {
    const res = await fetch(BASE + path, {
        method,
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { Accept: 'application/json', ...(body === undefined ? {} : { 'Content-Type': 'application/json' }), 'X-XSRF-TOKEN': xsrfToken() },
        body: body === undefined ? undefined : JSON.stringify(body),
    });
    const json = await res.json().catch(() => null);

    return { ok: res.ok, status: res.status, json };
}

async function loadBackups() {
    try {
        const r = await api('GET', '/backups');
        if (r.ok) backups.value = r.json.backups;
    } catch {
    }
}

async function poll() {
    clearTimeout(timer);
    const before = view.value;
    try {
        const r = await api('GET', '/status');
        if (!r.ok || !r.json?.state) throw new Error('status');
        offline.value = false;
        status.value = r.json;
        if (waiting.value && runStarted(waiting.value.before, r.json)) waiting.value = null;
        else if (waiting.value && Date.now() - waiting.value.since > 60_000) {
            waiting.value = null;
            noStart.value = true;
        }
        if (before === 'running' && view.value !== 'running') void loadBackups();
    } catch {
        offline.value = true;
    }
    if (running.value) timer = setTimeout(poll, 2000);
}

async function check() {
    busy.value = true;
    error.value = notice.value = '';
    try {
        const r = await api('POST', '/check');
        if (r.ok) {
            latest.value = r.json.latest_version;
            releaseUrl.value = r.json.release_url;
            notice.value = trans('update.panel.checked');
        } else error.value = r.json?.message ?? trans('update.panel.request_failed');
    } catch {
        error.value = trans('update.panel.request_failed');
    } finally {
        busy.value = false;
    }
}

async function launch(path: string, body: unknown, close: () => void) {
    busy.value = true;
    noStart.value = false;
    error.value = notice.value = '';
    try {
        const r = await api('POST', path, body);
        if (r.status === 202) {
            close();
            waiting.value = { since: Date.now(), before: status.value?.state.started_at ?? null };
            void poll();
        } else {
            error.value = r.json?.message ?? r.json?.errors?.version?.[0] ?? trans('update.panel.request_failed');
            close();
        }
    } catch {
        error.value = trans('update.panel.request_failed');
        close();
    } finally {
        busy.value = false;
    }
}

const start = () => launch('', { version: latest.value, without_trial: withoutTrial.value }, () => (startOpen.value = false));
const rollback = () => launch('/rollback', { confirm: true }, () => (rollbackOpen.value = false));

const reload = () => window.location.reload();

function openStart() {
    withoutTrial.value = false;
    startOpen.value = true;
}

onMounted(() => {
    void poll();
    void loadBackups();
});
onBeforeUnmount(() => clearTimeout(timer));
</script>

<template>
    <section :class="[card, 'max-w-3xl space-y-3']" aria-labelledby="ov-version" data-slot="update-action">
        <h2 id="ov-version" class="text-lg font-medium">{{ trans('settings.overview.version.title') }}</h2>
        <p class="text-sm">{{ trans('settings.overview.version.current') }}: <strong>{{ installed }}</strong></p>
        <p v-if="latest" class="text-sm">
            {{ trans('settings.overview.version.latest') }}: <strong>{{ latest }}</strong>
            <a v-if="releaseUrl" :href="releaseUrl" rel="noopener noreferrer" target="_blank" class="ml-2 underline">{{ trans('settings.overview.version.release') }}</a>
        </p>
        <p v-else class="text-sm text-text-2">{{ updates.check_enabled ? trans('settings.overview.version.unchecked') : trans('settings.overview.version.off') }}</p>

        <p v-if="blocker" class="rounded-md border border-warn p-2 text-sm text-warn" data-slot="update-blocker">{{ trans('update.blocker.' + blocker) }}</p>
        <p v-if="preflight && !blocker && !running" class="rounded-md border border-warn p-2 text-sm text-warn" data-slot="update-preflight">{{ preflight.message }}</p>
        <p v-if="notice" class="text-sm" role="status">{{ notice }}</p>
        <p v-if="error" class="text-sm text-danger" role="alert">{{ error }}</p>
        <p v-if="noStart && !running" class="text-sm text-danger" role="alert" data-slot="update-no-start">{{ trans('update.panel.no_start', { log: logDir }) }}</p>

        <div v-if="!running" class="flex flex-wrap gap-2">
            <Button type="button" size="sm" variant="outline" :disabled="busy || !update.repo_configured" @click="check">{{ trans('update.panel.check') }}</Button>
            <Button type="button" size="sm" :disabled="busy || !canStart" @click="openStart">{{ trans('update.panel.start') }}</Button>
            <Button v-if="canRollback" type="button" size="sm" variant="outline" :disabled="busy" @click="rollbackOpen = true">{{ trans('update.panel.rollback') }}</Button>
        </div>

        <div v-if="running" aria-live="polite" class="space-y-2" data-slot="update-progress">
            <p class="text-sm font-medium">{{ trans(rollingBack ? 'update.panel.rolling_back' : 'update.panel.running', { from: status?.state.from ?? installed, to: status?.state.to ?? latest ?? '' }) }}</p>
            <p v-if="!status || view !== 'running'" class="text-sm text-text-2">{{ trans('update.panel.starting') }}</p>
            <p v-else-if="extra" class="text-sm font-semibold" aria-current="step" data-slot="update-extra-step"><span aria-hidden="true">▶</span> {{ trans('update.step.' + extra) }}</p>
            <div v-else>
                <div class="h-2 w-full overflow-hidden rounded bg-border" role="progressbar" :aria-valuenow="percent(steps)" aria-valuemin="0" aria-valuemax="100">
                    <div class="h-full bg-primary transition-all" :style="{ width: percent(steps) + '%' }" />
                </div>
                <ol class="mt-2 space-y-1 text-sm">
                    <li v-for="s in steps" :key="s.name" :class="s.state === 'current' ? 'font-semibold' : s.state === 'pending' ? 'text-text-2' : s.state === 'failed' ? 'text-danger' : ''" :aria-current="s.state === 'current' ? 'step' : undefined">
                        <span aria-hidden="true">{{ s.state === 'done' ? '✓' : s.state === 'current' ? '▶' : s.state === 'failed' ? '✗' : '○' }}</span>
                        {{ trans('update.step.' + s.name) }}
                    </li>
                </ol>
            </div>
            <p v-if="offline" class="text-sm text-warn">{{ trans('update.panel.offline') }}</p>
            <p v-if="status?.maintenance" class="text-xs text-text-2">{{ trans('update.panel.maintenance_on') }}</p>
        </div>

        <div v-else-if="view === 'done'" class="rounded-md border border-ok p-3 text-sm" role="status" data-slot="update-done">
            <p class="font-medium text-ok">{{ trans('update.panel.done', { version: status?.state.to ?? '' }) }}</p>
            <p class="mt-1 text-text-2">{{ trans('update.panel.done_hint') }}</p>
            <p v-if="status?.image_ref" class="mt-1 break-words text-warn" data-slot="update-image-ref">{{ trans('update.panel.image_ref_notice', { image: status.image_ref }) }}</p>
            <Button type="button" size="sm" class="mt-2" @click="reload">{{ trans('update.panel.reload') }}</Button>
        </div>
        <div v-else-if="view === 'rolled_back'" class="rounded-md border border-warn p-3 text-sm" role="alert" data-slot="update-rolled-back">
            <p class="font-medium text-warn">{{ trans('update.panel.rolled_back') }}</p>
            <p v-if="status?.state.error" class="mt-1 break-words">{{ status.state.error }}</p>
            <p v-if="status?.image_ref" class="mt-1 break-words text-warn" data-slot="update-image-ref">{{ trans('update.panel.image_ref_rolled_back', { image: status.image_ref }) }}</p>
        </div>
        <div v-else-if="view === 'failed_rollback'" class="space-y-1 rounded-md border border-danger p-3 text-sm" role="alert" data-slot="update-failed-rollback">
            <p class="font-medium text-danger">{{ trans('update.panel.failed_rollback') }}</p>
            <p v-if="status?.state.error" class="break-words">{{ status.state.error }}</p>
            <p v-if="status?.state.backup">{{ trans('update.panel.backup_path') }}: <code class="break-all text-xs">{{ status.state.backup }}</code></p>
            <template v-if="docker">
                <p>{{ trans('update.panel.recovery_docker') }}</p>
                <p><code class="break-all text-xs">docker compose exec updater php updater/updater.php rollback</code></p>
                <p>{{ trans('update.panel.recovery_docs_docker') }}</p>
                <p><code class="break-all text-xs">docker compose exec app php artisan up</code> {{ trans('update.panel.up_hint') }}</p>
            </template>
            <template v-else>
                <p>{{ trans('update.panel.recovery_docs') }}</p>
                <p><code class="text-xs">php artisan up</code> {{ trans('update.panel.up_hint') }}</p>
            </template>
        </div>
        <div v-else-if="view === 'failed'" class="rounded-md border border-danger p-3 text-sm" role="alert" data-slot="update-failed">
            <p class="font-medium text-danger">{{ trans('update.panel.failed') }}</p>
            <p v-if="status?.state.error" class="mt-1 break-words">{{ status.state.error }}</p>
        </div>
        <div v-else-if="view === 'stale'" class="space-y-1 rounded-md border border-danger p-3 text-sm" role="alert" data-slot="update-stale">
            <p class="font-medium text-danger">{{ trans('update.panel.stale') }}</p>
            <p v-if="docker">{{ trans('update.panel.stale_hint_docker') }} <code class="text-xs">docker compose restart updater</code></p>
            <p v-else>{{ trans('update.panel.stale_hint') }} <code class="text-xs">php artisan ignis:update:recover</code></p>
        </div>
        <div v-else-if="view === 'unknown'" class="space-y-1 rounded-md border border-danger p-3 text-sm" role="alert" data-slot="update-unknown">
            <p class="font-medium text-danger">{{ trans('update.panel.unknown') }}</p>
            <p class="break-words">{{ trans('update.panel.unknown_hint', { path: status?.paths?.state ?? 'storage/app/update/state.json', log: logDir }) }}</p>
        </div>

        <details v-if="status?.log.length" class="text-sm">
            <summary class="cursor-pointer">{{ trans('update.panel.log') }}</summary>
            <pre class="mt-1 max-h-64 overflow-auto whitespace-pre-wrap break-words rounded bg-card-2 p-2 text-xs">{{ status.log.join('\n') }}</pre>
        </details>

        <div v-if="backups.length" class="space-y-1">
            <h3 class="text-sm font-medium">{{ trans('update.panel.backups') }}</h3>
            <ul class="space-y-0.5 text-sm text-text-2">
                <li v-for="b in backups" :key="b.name">{{ when(b.created_at) }} · {{ b.from }} → {{ b.to }} · {{ formatBytes(b.size_bytes, locale) }}</li>
            </ul>
        </div>

        <Dialog v-model:open="startOpen">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{{ trans('update.panel.start_title', { version: latest ?? '' }) }}</DialogTitle>
                    <DialogDescription>{{ trans('update.panel.start_text', { from: installed, to: latest ?? '' }) }}</DialogDescription>
                </DialogHeader>
                <ul class="list-disc space-y-1 pl-5 text-sm">
                    <li>{{ trans('update.panel.start_maintenance') }}</li>
                    <li>{{ trans('update.panel.start_backup') }}</li>
                </ul>
                <label v-if="status?.trial_unavailable" class="flex items-start gap-2 text-sm">
                    <input v-model="withoutTrial" type="checkbox" class="mt-1" />
                    <span>{{ trans('update.panel.without_trial') }}</span>
                </label>
                <DialogFooter>
                    <Button type="button" variant="outline" @click="startOpen = false">{{ trans('lookup.cancel') }}</Button>
                    <Button type="button" :disabled="busy" @click="start">{{ trans('update.panel.start_confirm') }}</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="rollbackOpen">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{{ trans('update.panel.rollback') }}</DialogTitle>
                    <DialogDescription>{{ trans('update.panel.rollback_warning') }}</DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button type="button" variant="outline" @click="rollbackOpen = false">{{ trans('lookup.cancel') }}</Button>
                    <Button type="button" variant="destructive" :disabled="busy" @click="rollback">{{ trans('update.panel.rollback_confirm') }}</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </section>
</template>
