<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { type Assignable, type DefectEvent, type DefectRow, defectTone, time } from './defects';

type Detail = DefectRow & {
    description: string | null;
    resolution_note: string | null;
    resolved_at: string | null;
    resolver: string | null;
    events: DefectEvent[];
};

// Detail, status/assignment and resolution of one defect (GET /vehicles/defects/{id} as JSON); null closes it.
const props = defineProps<{ defectId: number | null; users: Assignable[]; can: { manage: boolean; delete: boolean } }>();
const emit = defineEmits<{ close: [] }>();

const detail = ref<Detail | null>(null);
const failed = ref(false);
const status = ref('');
const statusNote = ref('');
const assignedTo = ref<number | ''>('');
const resolutionNote = ref('');
const confirmingDelete = ref(false);
const busy = ref(false);

async function load() {
    const id = props.defectId;
    if (id === null) return;
    failed.value = false;
    try {
        const res = await fetch(`/vehicles/defects/${id}`, { headers: { Accept: 'application/json' } });
        if (!res.ok) throw new Error(String(res.status));
        const d = (await res.json()).defect as Detail;
        if (id !== props.defectId) return;
        detail.value = d;
        status.value = d.status;
        statusNote.value = '';
        assignedTo.value = d.assigned_to ?? '';
        resolutionNote.value = '';
    } catch {
        if (id === props.defectId) failed.value = true;
    }
}
watch(
    () => props.defectId,
    (id) => {
        detail.value = null;
        confirmingDelete.value = false;
        if (id !== null) load();
    },
    { immediate: true },
);

const errors = computed(() => usePage().props.errors as Record<string, string>);
const url = () => `/vehicles/defects/${props.defectId}`;
const opts = (after: () => void) => ({ preserveScroll: true, onSuccess: after, onError: () => load(), onFinish: () => (busy.value = false) });

const changes = computed(() => {
    const d = detail.value;
    const out: Record<string, string | number> = {};
    if (!d) return out;
    if (status.value !== d.status) {
        out.status = status.value;
        out.from_status = d.status;
        if (statusNote.value.trim() !== '') out.status_note = statusNote.value;
    }
    if ((assignedTo.value === '' ? null : assignedTo.value) !== d.assigned_to) out.assigned_to = assignedTo.value;
    return out;
});
function save() {
    busy.value = true;
    router.patch(url(), changes.value, opts(load));
}
function resolve() {
    busy.value = true;
    router.post(`${url()}/resolve`, { resolution_note: resolutionNote.value }, opts(load));
}
function destroy() {
    busy.value = true;
    router.delete(url(), opts(() => emit('close')));
}

const field = 'w-full rounded-md border border-border bg-card px-3 py-2 text-sm';
</script>

<template>
    <Dialog :open="defectId !== null" @update:open="(v) => !v && emit('close')">
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
            <DialogHeader>
                <DialogTitle>{{ detail?.title ?? trans('vehicles.defects.loading') }}</DialogTitle>
            </DialogHeader>

            <p v-if="failed" class="text-sm text-danger" role="alert">{{ trans('vehicles.defects.load_error') }}</p>
            <p v-else-if="!detail" class="text-sm text-text-2">{{ trans('vehicles.defects.loading') }}</p>

            <div v-else class="space-y-4 text-sm">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="defectTone[detail.status]">
                        {{ trans(`vehicles.defect_status.${detail.status}`) }}
                    </span>
                    <span v-if="detail.is_blocking" class="rounded-full bg-(--danger-soft) px-2 py-0.5 text-xs font-medium text-danger">
                        {{ trans('vehicles.defects.blocking') }}
                    </span>
                    <span class="text-text-2">{{ trans(`vehicles.defect_category.${detail.category}`) }} · {{ detail.vehicle.name }}</span>
                </div>
                <p v-if="detail.description" class="whitespace-pre-line">{{ detail.description }}</p>
                <p class="text-xs text-text-2">
                    <template v-if="detail.reporter">{{ trans('vehicles.defects.reporter', { name: detail.reporter }) }} · </template>{{ time(detail.created_at) }}
                </p>
                <p v-if="detail.status === 'resolved'" class="rounded-md bg-(--ok-soft) px-3 py-2">
                    <span class="block text-xs">{{ trans('vehicles.defects.resolved_by', { name: detail.resolver ?? '–' }) }} · {{ time(detail.resolved_at) }}</span>
                    <span v-if="detail.resolution_note" class="whitespace-pre-line">{{ detail.resolution_note }}</span>
                </p>

                <form v-if="can.manage" class="grid gap-3 sm:grid-cols-2" @submit.prevent="save">
                    <label class="space-y-1">
                        <span class="text-text-2">{{ trans('vehicles.defects.col.status') }}</span>
                        <select v-model="status" :class="field">
                            <option v-for="s in ['open', 'in_progress', 'deferred']" :key="s" :value="s">{{ trans(`vehicles.defect_status.${s}`) }}</option>
                            <option v-if="detail.status === 'resolved'" value="resolved" disabled>{{ trans('vehicles.defect_status.resolved') }}</option>
                        </select>
                        <span v-if="errors.status" class="text-xs text-danger">{{ errors.status }}</span>
                    </label>
                    <label class="space-y-1">
                        <span class="text-text-2">{{ trans('vehicles.defects.field.assignee') }}</span>
                        <select v-model="assignedTo" :class="field">
                            <option value="">{{ trans('vehicles.defects.unassigned') }}</option>
                            <option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option>
                            <option v-if="detail.assigned_to !== null && !users.some((u) => u.id === detail?.assigned_to)" :value="detail.assigned_to" disabled>
                                {{ detail.assignee }}
                            </option>
                        </select>
                        <span v-if="errors.assigned_to" class="text-xs text-danger">{{ errors.assigned_to }}</span>
                    </label>
                    <label v-if="status !== detail.status" class="space-y-1 sm:col-span-2">
                        <span class="text-text-2">{{ trans('vehicles.defects.field.status_note') }}</span>
                        <input v-model="statusNote" maxlength="5000" :class="field" />
                        <span v-if="detail.status === 'resolved'" class="block text-xs text-text-2">{{ trans('vehicles.defects.reopen_hint') }}</span>
                    </label>
                    <div class="sm:col-span-2">
                        <Button type="submit" size="sm" :disabled="busy || Object.keys(changes).length === 0">{{ trans('vehicles.defects.save') }}</Button>
                    </div>
                </form>

                <form v-if="can.manage && detail.status !== 'resolved'" class="space-y-2" @submit.prevent="resolve">
                    <label class="block space-y-1">
                        <span class="text-text-2">{{ trans('vehicles.defects.field.resolution_note') }}</span>
                        <textarea v-model="resolutionNote" rows="2" maxlength="5000" :class="field" />
                        <span v-if="errors.resolution_note" class="text-xs text-danger">{{ errors.resolution_note }}</span>
                    </label>
                    <Button type="submit" size="sm" variant="secondary" :disabled="busy">{{ trans('vehicles.defects.resolve') }}</Button>
                </form>

                <section>
                    <h3 class="mb-2 font-medium">{{ trans('vehicles.defects.history') }}</h3>
                    <ul class="space-y-2">
                        <li v-for="(e, i) in detail.events" :key="i">
                            <span class="block">{{ e.details ?? e.action }}</span>
                            <span class="block text-xs text-text-2">{{ time(e.time) }}<template v-if="e.user"> · {{ e.user }}</template></span>
                        </li>
                    </ul>
                </section>
            </div>

            <DialogFooter>
                <template v-if="can.delete && detail">
                    <template v-if="confirmingDelete">
                        <span class="text-sm">{{ trans('vehicles.defects.delete_confirm', { title: detail.title }) }}</span>
                        <Button variant="ghost" @click="confirmingDelete = false">{{ trans('vehicles.cancel') }}</Button>
                        <Button variant="destructive" :disabled="busy" @click="destroy">{{ trans('vehicles.delete') }}</Button>
                    </template>
                    <Button v-else variant="destructive" @click="confirmingDelete = true">{{ trans('vehicles.defects.delete') }}</Button>
                </template>
                <Button variant="ghost" @click="emit('close')">{{ trans('vehicles.defects.close') }}</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
