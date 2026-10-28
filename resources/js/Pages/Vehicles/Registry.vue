<script setup lang="ts">
import { computed, onUnmounted, ref, watch } from 'vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import Pagination from '@/components/Pagination.vue';
import { time } from '@/components/vehicles/defects';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Existing = { id: number; name: string; identifier: string; type: string; service: number; allowed_jobs: string[] | null };
type Entry = {
    id: number;
    emd_vehicle_id: number | null;
    name: string;
    identifier: string | null;
    type: string | null;
    service: number;
    department: string | null;
    value_long: string | null;
    job: string | null;
    image: string | null;
    radio_channel: string | null;
    created_at: string | null;
    match_type: 'name' | 'identifier' | 'none';
    existing: Existing | null;
};
type Status = { pending: boolean; count: number; requested: string | null; last_sync_at: string | null };
type Action = 'import' | 'overwrite' | 'merge';

const props = defineProps<{
    entries: { data: Entry[]; from: number | null; to: number | null; total: number; prev_page_url: string | null; next_page_url: string | null };
    counts: { pending: number; matched: number; new: number; max_id: number | null };
    status: Status;
    can: { view: boolean };
}>();

const BASE = '/vehicles/registry';
const field = 'h-9 w-full rounded-md border border-border bg-card px-3 text-sm';
const entryError = computed(() => (usePage().props.errors as Record<string, string>).entry);

// ----- waiting for ef_bridge: poll the status until the intake clears the request -----
const POLL_MS = 4000;
let timer: ReturnType<typeof setInterval> | undefined;
const stopPolling = () => {
    clearInterval(timer);
    timer = undefined;
};
async function poll() {
    try {
        const res = await fetch(`${BASE}/status`, { headers: { Accept: 'application/json' } });
        if (!res.ok) return;
        const state = (await res.json()) as Status;
        if (!state.pending) {
            stopPolling();
            router.reload({ only: ['entries', 'counts', 'status'] });
        }
    } catch {
    }
}
watch(
    () => props.status.pending,
    (pending) => {
        stopPolling();
        if (pending) timer = setInterval(poll, POLL_MS);
    },
    { immediate: true },
);
onUnmounted(stopPolling);

const busy = ref(false);
const send = (url: string, data: Record<string, string | number> = {}, done?: () => void) =>
    router.post(url, data, {
        preserveScroll: true,
        onStart: () => (busy.value = true),
        onFinish: () => {
            busy.value = false;
            done?.();
        },
    });
const requestRegistry = () => send(`${BASE}/request`);
const cancelRequest = () => send(`${BASE}/cancel-request`);

// ----- decisions -----
const form = useForm({ vehicle_id: null as number | null, name: '', identifier: '', type: '', service: 0, allowed_jobs: '' });
const deciding = ref<{ entry: Entry; action: Action } | null>(null);
function open(entry: Entry, action: Action) {
    deciding.value = { entry, action };
    form.clearErrors();
    form.defaults({
        vehicle_id: action === 'import' ? null : (entry.existing?.id ?? null),
        name: entry.name,
        identifier: entry.identifier ?? '',
        type: entry.type ?? '',
        service: entry.service,
        allowed_jobs: entry.job ?? '',
    }).reset();
}
function decide() {
    const d = deciding.value;
    if (!d) return;
    form.post(`${BASE}/${d.entry.id}/${d.action}`, { preserveScroll: true, onSuccess: () => (deciding.value = null) });
}
const ignore = (entry: Entry) => send(`${BASE}/${entry.id}/ignore`);

const confirmingAll = ref(false);
const ignoreAll = () => send(`${BASE}/ignore-all`, { max_id: props.counts.max_id ?? 0 }, () => (confirmingAll.value = false));

const locked = computed(() => {
    const d = deciding.value;
    const v = d?.action === 'merge' ? d.entry.existing : null;
    return {
        name: !!v,
        identifier: !!v?.identifier,
        type: !!v?.type,
        service: !!v && v.service > 0,
        allowed_jobs: !!v?.allowed_jobs?.length,
    };
});
const anyLocked = computed(() => Object.values(locked.value).some(Boolean));

const badge: Record<Entry['match_type'], string> = {
    name: 'bg-(--warn-soft) text-warn',
    identifier: 'bg-(--info-soft) text-info',
    none: 'bg-(--ok-soft) text-ok',
};
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold">{{ trans('vehicles.registry.title') }}</h1>
                <p class="mt-1 text-sm text-text-2">{{ trans('vehicles.registry.lead') }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <Button v-if="counts.pending" variant="ghost" :disabled="busy" @click="confirmingAll = true">{{ trans('vehicles.registry.ignore_all') }}</Button>
                <Button v-if="status.pending" variant="ghost" :disabled="busy" @click="cancelRequest">{{ trans('vehicles.registry.cancel_request') }}</Button>
                <Button :disabled="status.pending || busy" @click="requestRegistry">{{ trans('vehicles.registry.request') }}</Button>
            </div>
        </div>

        <div class="rounded-lg border border-border bg-card p-3 text-sm shadow-(--shadow)" aria-live="polite">
            <p v-if="status.pending" class="font-medium">
                {{ trans('vehicles.registry.waiting', { time: time(status.requested) }) }}
                <span class="block font-normal text-text-2">{{ trans('vehicles.registry.waiting_hint') }}</span>
            </p>
            <p v-else class="text-text-2">{{ trans('vehicles.registry.request_hint') }}</p>
            <p class="mt-1 text-xs text-text-2">
                {{ trans('vehicles.registry.last_sync', { time: status.last_sync_at ? time(status.last_sync_at) : trans('vehicles.registry.never') }) }}
            </p>
        </div>

        <p v-if="entryError" class="text-sm text-danger" role="alert">{{ entryError }}</p>

        <div v-if="counts.pending" class="flex flex-wrap gap-2 text-sm">
            <span class="rounded-full bg-(--fill-3) px-2 py-0.5">{{ trans('vehicles.registry.count.pending', { count: String(counts.pending) }) }}</span>
            <span class="rounded-full px-2 py-0.5" :class="badge.none">{{ trans('vehicles.registry.count.new', { count: String(counts.new) }) }}</span>
            <span class="rounded-full px-2 py-0.5" :class="badge.name">{{ trans('vehicles.registry.count.matched', { count: String(counts.matched) }) }}</span>
        </div>

        <div class="overflow-x-auto rounded-lg border border-border bg-card shadow-(--shadow)">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-border text-text-2">
                    <tr>
                        <th class="px-3 py-2 font-medium">{{ trans('vehicles.registry.col.name') }}</th>
                        <th class="px-3 py-2 font-medium">{{ trans('vehicles.registry.col.type') }}</th>
                        <th class="px-3 py-2 font-medium">{{ trans('vehicles.registry.col.department') }}</th>
                        <th class="px-3 py-2 font-medium">{{ trans('vehicles.registry.col.match') }}</th>
                        <th class="px-3 py-2" />
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="e in entries.data" :key="e.id" class="border-b border-border last:border-0">
                        <td class="px-3 py-2">
                            <span class="font-medium">{{ e.name }}</span>
                            <span class="block text-xs text-text-2">{{ e.identifier ?? '–' }}<template v-if="e.value_long"> · {{ e.value_long }}</template></span>
                        </td>
                        <td class="px-3 py-2">
                            {{ e.type ?? '–' }}
                            <span class="block text-xs text-text-2">{{ trans(`vehicles.service.${e.service}`) }}</span>
                        </td>
                        <td class="px-3 py-2">
                            {{ e.department ?? '–' }}
                            <span v-if="e.job" class="block text-xs text-text-2">{{ e.job }}</span>
                        </td>
                        <td class="px-3 py-2">
                            <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="badge[e.match_type]">{{ trans(`vehicles.registry.match.${e.match_type}`) }}</span>
                            <span v-if="e.existing" class="block text-xs text-text-2">
                                <Link v-if="can.view" :href="`/vehicles/${e.existing.id}`" class="hover:underline">{{ e.existing.name }}</Link>
                                <template v-else>{{ e.existing.name }}</template>
                                · {{ e.existing.identifier }}
                            </span>
                        </td>
                        <td class="whitespace-nowrap px-3 py-2 text-right">
                            <template v-if="e.existing">
                                <Button size="sm" variant="ghost" @click="open(e, 'overwrite')">{{ trans('vehicles.registry.action.overwrite') }}</Button>
                                <Button size="sm" variant="ghost" @click="open(e, 'merge')">{{ trans('vehicles.registry.action.merge') }}</Button>
                            </template>
                            <Button v-else size="sm" variant="ghost" @click="open(e, 'import')">{{ trans('vehicles.registry.action.import') }}</Button>
                            <Button size="sm" variant="ghost" :disabled="busy" :aria-label="trans('vehicles.registry.ignore_row', { name: e.name })" @click="ignore(e)">
                                {{ trans('vehicles.registry.action.ignore') }}
                            </Button>
                        </td>
                    </tr>
                    <tr v-if="!entries.data.length">
                        <td colspan="5" class="px-3 py-6 text-center text-text-2">{{ trans('vehicles.registry.empty') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Pagination :from="entries.from" :to="entries.to" :total="entries.total" :prev-url="entries.prev_page_url" :next-url="entries.next_page_url" />

        <Dialog :open="deciding !== null" @update:open="(v) => !v && (deciding = null)">
            <DialogContent class="max-h-[90vh] overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>{{ trans(`vehicles.registry.dialog.${deciding?.action ?? 'import'}`, { name: deciding?.entry.name ?? '' }) }}</DialogTitle>
                    <DialogDescription>
                        {{ trans(`vehicles.registry.dialog.${deciding?.action ?? 'import'}_hint`, { vehicle: deciding?.entry.existing?.name ?? '' }) }}
                    </DialogDescription>
                </DialogHeader>
                <form class="space-y-3" @submit.prevent="decide">
                    <p v-if="anyLocked" class="text-sm text-text-2">{{ trans('vehicles.registry.dialog.merge_unused', { vehicle: deciding?.entry.existing?.name ?? '' }) }}</p>
                    <div class="space-y-1">
                        <Label for="reg-name">{{ trans('vehicles.registry.field.name') }}</Label>
                        <Input id="reg-name" v-model="form.name" maxlength="255" :disabled="locked.name" :aria-invalid="!!form.errors.name" />
                        <p v-if="form.errors.name" class="text-sm text-danger" role="alert">{{ form.errors.name }}</p>
                    </div>
                    <div class="space-y-1">
                        <Label for="reg-identifier">{{ trans('vehicles.registry.field.identifier') }}</Label>
                        <Input id="reg-identifier" v-model="form.identifier" maxlength="255" :disabled="locked.identifier" :aria-invalid="!!form.errors.identifier" />
                        <p v-if="form.errors.identifier" class="text-sm text-danger" role="alert">{{ form.errors.identifier }}</p>
                    </div>
                    <div class="space-y-1">
                        <Label for="reg-type">{{ trans('vehicles.registry.field.type') }}</Label>
                        <Input id="reg-type" v-model="form.type" maxlength="255" :disabled="locked.type" :aria-invalid="!!form.errors.type" />
                        <p v-if="form.errors.type" class="text-sm text-danger" role="alert">{{ form.errors.type }}</p>
                    </div>
                    <div class="space-y-1">
                        <Label for="reg-service">{{ trans('vehicles.registry.field.service') }}</Label>
                        <select id="reg-service" v-model.number="form.service" :class="field" :disabled="locked.service" :aria-invalid="!!form.errors.service">
                            <option v-for="s in [0, 1, 2, 3]" :key="s" :value="s">{{ trans(`vehicles.service.${s}`) }}</option>
                        </select>
                        <p v-if="form.errors.service" class="text-sm text-danger" role="alert">{{ form.errors.service }}</p>
                    </div>
                    <div class="space-y-1">
                        <Label for="reg-jobs">{{ trans('vehicles.registry.field.allowed_jobs') }}</Label>
                        <Input id="reg-jobs" v-model="form.allowed_jobs" maxlength="500" :disabled="locked.allowed_jobs" :aria-invalid="!!form.errors.allowed_jobs" />
                        <p v-if="form.errors.allowed_jobs" class="text-sm text-danger" role="alert">{{ form.errors.allowed_jobs }}</p>
                    </div>
                    <p v-if="form.errors.vehicle_id || entryError" class="text-sm text-danger" role="alert">{{ form.errors.vehicle_id ?? entryError }}</p>
                    <DialogFooter>
                        <Button type="button" variant="ghost" @click="deciding = null">{{ trans('vehicles.cancel') }}</Button>
                        <Button type="submit" :disabled="form.processing">{{ trans(`vehicles.registry.action.${deciding?.action ?? 'import'}`) }}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog :open="confirmingAll" @update:open="(v) => !v && (confirmingAll = false)">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{{ trans('vehicles.registry.ignore_all') }}</DialogTitle>
                    <DialogDescription class="text-foreground">{{ trans('vehicles.registry.ignore_all_confirm', { count: String(counts.pending) }) }}</DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button variant="ghost" @click="confirmingAll = false">{{ trans('vehicles.cancel') }}</Button>
                    <Button variant="destructive" :disabled="busy" @click="ignoreAll">{{ trans('vehicles.registry.ignore_all') }}</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
