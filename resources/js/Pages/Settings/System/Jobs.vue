<script setup lang="ts">
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { xsrfToken } from '@/lib/draftSave';

type Task = { task: string; expression: string; next_run: string | null; status: string | null; last_run: string | null; duration_ms: number | null };
type Failed = { id: string; class: string; queue: string; failed_at: string | null; error: string };
type Run = { id: number; task: string; status: string; started_at: string | null; duration_ms: number | null; output: string | null };

defineProps<{ tasks: Task[]; queue: { pending: number; failed: number }; failed: Failed[]; commands: string[]; history?: Run[] }>();

const card = 'rounded-lg border border-border bg-card p-4 shadow-(--shadow)';
const th = 'px-2 py-1 text-left font-medium text-text-2';
const td = 'px-2 py-1 align-top';
const busy = ref(false);
const confirmOpen = ref(false);
const notice = ref('');
const loadingHistory = ref(false);

const when = (iso: string | null) => (iso ? new Date(iso).toLocaleString() : '-');
const duration = (ms: number | null) => (ms === null ? '-' : ms < 1000 ? `${ms} ms` : `${(ms / 1000).toFixed(1)} s`);
const options = { preserveScroll: true, onStart: () => (busy.value = true), onFinish: () => (busy.value = false) };

async function run(command: string) {
    notice.value = '';
    busy.value = true;
    try {
        const res = await fetch('/settings/system/jobs/run', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrfToken() },
            body: JSON.stringify({ command }),
        });
        notice.value = trans(res.status === 202 ? 'settings.jobs.queued' : 'settings.jobs.run_failed');
    } catch {
        notice.value = trans('settings.jobs.run_failed');
    } finally {
        busy.value = false;
    }
}

const retry = (id: string) => router.post(`/settings/system/jobs/failed/${id}/retry`, {}, options);
const remove = (id: string) => router.delete(`/settings/system/jobs/failed/${id}`, options);
function removeAll() {
    router.delete('/settings/system/jobs/failed', { ...options, onSuccess: () => (confirmOpen.value = false) });
}
function loadHistory() {
    router.reload({ only: ['history'], onStart: () => (loadingHistory.value = true), onFinish: () => (loadingHistory.value = false) });
}
</script>

<template>
    <div class="space-y-4">
        <div>
            <h1 class="text-2xl font-semibold">{{ trans('settings.jobs.title') }}</h1>
            <p class="mt-1 text-sm text-text-2">{{ trans('settings.jobs.lead') }}</p>
        </div>
        <p v-if="notice" class="text-sm" role="status">{{ notice }}</p>

        <section :class="[card, 'space-y-2']" aria-labelledby="jobs-queue">
            <h2 id="jobs-queue" class="text-lg font-medium">{{ trans('settings.jobs.queue.title') }}</h2>
            <dl class="grid max-w-md grid-cols-2 gap-3 text-sm">
                <div>
                    <dt class="text-text-2">{{ trans('settings.jobs.queue.pending') }}</dt>
                    <dd class="text-xl font-semibold">{{ queue.pending }}</dd>
                </div>
                <div>
                    <dt class="text-text-2">{{ trans('settings.jobs.queue.failed') }}</dt>
                    <dd class="text-xl font-semibold">{{ queue.failed }}</dd>
                </div>
            </dl>
        </section>

        <section :class="[card, 'space-y-2 overflow-x-auto']" aria-labelledby="jobs-tasks">
            <h2 id="jobs-tasks" class="text-lg font-medium">{{ trans('settings.jobs.tasks.title') }}</h2>
            <table class="w-full text-sm">
                <thead>
                    <tr>
                        <th :class="th">{{ trans('settings.jobs.tasks.task') }}</th>
                        <th :class="th">{{ trans('settings.jobs.tasks.expression') }}</th>
                        <th :class="th">{{ trans('settings.jobs.tasks.next') }}</th>
                        <th :class="th">{{ trans('settings.jobs.tasks.last') }}</th>
                        <th :class="th"><span class="sr-only">{{ trans('settings.jobs.tasks.run') }}</span></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="t in tasks" :key="t.task" class="border-t border-border">
                        <td :class="[td, 'font-mono']">{{ t.task }}</td>
                        <td :class="[td, 'font-mono']">{{ t.expression }}</td>
                        <td :class="td">{{ when(t.next_run) }}</td>
                        <td :class="td">
                            <template v-if="t.status">{{ trans('settings.jobs.status.' + t.status) }} · {{ when(t.last_run) }} · {{ duration(t.duration_ms) }}</template>
                            <template v-else>{{ trans('settings.jobs.tasks.never') }}</template>
                        </td>
                        <td :class="td">
                            <Button v-if="commands.includes(t.task)" type="button" size="sm" variant="outline" :disabled="busy" @click="run(t.task)">{{ trans('settings.jobs.tasks.run') }}</Button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </section>

        <section :class="[card, 'space-y-2 overflow-x-auto']" aria-labelledby="jobs-failed">
            <div class="flex items-center justify-between gap-2">
                <h2 id="jobs-failed" class="text-lg font-medium">{{ trans('settings.jobs.failed.title') }}</h2>
                <Button v-if="queue.failed > 0" type="button" size="sm" variant="outline" :disabled="busy" @click="confirmOpen = true">{{ trans('settings.jobs.failed.delete_all') }}</Button>
            </div>
            <p v-if="!failed.length" class="text-sm text-text-2">{{ trans('settings.jobs.failed.empty') }}</p>
            <table v-else class="w-full text-sm">
                <thead>
                    <tr>
                        <th :class="th">{{ trans('settings.jobs.failed.class') }}</th>
                        <th :class="th">{{ trans('settings.jobs.failed.queue') }}</th>
                        <th :class="th">{{ trans('settings.jobs.failed.at') }}</th>
                        <th :class="th">{{ trans('settings.jobs.failed.error') }}</th>
                        <th :class="th"><span class="sr-only">{{ trans('settings.jobs.failed.actions') }}</span></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="j in failed" :key="j.id" class="border-t border-border">
                        <td :class="[td, 'font-mono']">{{ j.class }}</td>
                        <td :class="td">{{ j.queue }}</td>
                        <td :class="td">{{ when(j.failed_at) }}</td>
                        <td :class="[td, 'break-words']">{{ j.error }}</td>
                        <td :class="[td, 'space-x-2 whitespace-nowrap']">
                            <Button type="button" size="sm" variant="outline" :disabled="busy" @click="retry(j.id)">{{ trans('settings.jobs.failed.retry') }}</Button>
                            <Button type="button" size="sm" variant="outline" :disabled="busy" @click="remove(j.id)">{{ trans('settings.jobs.failed.delete') }}</Button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </section>

        <section :class="[card, 'space-y-2 overflow-x-auto']" aria-labelledby="jobs-history">
            <div class="flex items-center justify-between gap-2">
                <h2 id="jobs-history" class="text-lg font-medium">{{ trans('settings.jobs.history.title') }}</h2>
                <Button type="button" size="sm" variant="outline" :disabled="loadingHistory" @click="loadHistory">{{ trans(history ? 'settings.jobs.history.reload' : 'settings.jobs.history.load') }}</Button>
            </div>
            <p v-if="history && !history.length" class="text-sm text-text-2">{{ trans('settings.jobs.history.empty') }}</p>
            <table v-else-if="history" class="w-full text-sm">
                <thead>
                    <tr>
                        <th :class="th">{{ trans('settings.jobs.tasks.task') }}</th>
                        <th :class="th">{{ trans('settings.jobs.history.status') }}</th>
                        <th :class="th">{{ trans('settings.jobs.history.started') }}</th>
                        <th :class="th">{{ trans('settings.jobs.history.duration') }}</th>
                        <th :class="th">{{ trans('settings.jobs.history.output') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="r in history" :key="r.id" class="border-t border-border">
                        <td :class="[td, 'font-mono']">{{ r.task }}</td>
                        <td :class="td">{{ trans('settings.jobs.status.' + r.status) }}</td>
                        <td :class="td">{{ when(r.started_at) }}</td>
                        <td :class="td">{{ duration(r.duration_ms) }}</td>
                        <td :class="[td, 'break-words text-text-2']">{{ r.output }}</td>
                    </tr>
                </tbody>
            </table>
        </section>

        <Dialog v-model:open="confirmOpen">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{{ trans('settings.jobs.failed.delete_all') }}</DialogTitle>
                    <DialogDescription>{{ trans('settings.jobs.failed.delete_all_confirm') }}</DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button type="button" variant="outline" @click="confirmOpen = false">{{ trans('lookup.cancel') }}</Button>
                    <Button type="button" variant="destructive" :disabled="busy" @click="removeAll">{{ trans('settings.jobs.failed.delete_all') }}</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
