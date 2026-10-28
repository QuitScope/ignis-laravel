<script setup lang="ts">
/** Arrival board of the signed-in clinic device: polls every 15 s (read only); seen/arrived by PUT. Plain text only. */
import { trans } from 'laravel-vue-i18n';
import { onBeforeUnmount, onMounted, ref } from 'vue';
import PortalNav from '@/components/enotf/PortalNav.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { xsrfToken } from '@/lib/draftSave';

defineOptions({ layout: GuestLayout });

type Row = { id: number; status: string; priority: number; eta: string | null; note: string | null; enr: string | null; vehicle: string | null; created_at: string | null };
const props = defineProps<{ hospital: { id: number; name: string }; preregistrations: Row[] }>();

const rows = ref<Row[]>(props.preregistrations);
const message = ref('');
const headers = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };

const signedOut = (status?: number) => {
    if (status === 403) location.assign('/enotf/schnittstelle/login');
    return status === 403;
};
async function poll() {
    if (document.hidden) return;
    const res = await fetch('/enotf/schnittstelle/prereg', { credentials: 'same-origin', headers }).catch(() => null);
    if (signedOut(res?.status)) return;
    const body = (await res?.json().catch(() => null)) as { preregistrations?: Row[] } | null;
    if (res?.ok && body?.preregistrations) rows.value = body.preregistrations;
}
async function mark(row: Row, status: 'seen' | 'arrived') {
    message.value = '';
    const res = await fetch(`/enotf/schnittstelle/prereg/${row.id}`, {
        method: 'PUT',
        credentials: 'same-origin',
        headers: { ...headers, 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrfToken() },
        body: JSON.stringify({ status }),
    }).catch(() => null);
    if (signedOut(res?.status)) return;
    if (!res?.ok) message.value = trans('enotf.portal.save_failed');
    await poll();
}

let timer: number | undefined;
onMounted(() => (timer = window.setInterval(poll, 15000)));
onBeforeUnmount(() => window.clearInterval(timer));
</script>

<template>
    <div class="mx-auto max-w-5xl space-y-4">
        <PortalNav :hospital="hospital.name" current="board" />
        <p role="status" class="text-sm text-danger">{{ message }}</p>
        <p v-if="!rows.length" class="text-text-2">{{ trans('enotf.board.empty') }}</p>
        <table v-else class="w-full text-left">
            <caption class="sr-only">{{ trans('enotf.portal.board') }}</caption>
            <thead class="border-b border-border text-sm text-text-2">
                <tr>
                    <th scope="col" class="py-2 pr-3">{{ trans('enotf.board.eta') }}</th>
                    <th scope="col" class="py-2 pr-3">{{ trans('enotf.prereg.priority') }}</th>
                    <th scope="col" class="py-2 pr-3">{{ trans('enotf.board.vehicle') }}</th>
                    <th scope="col" class="py-2 pr-3">{{ trans('enotf.prereg.note') }}</th>
                    <th scope="col" class="py-2 pr-3">{{ trans('enotf.board.status') }}</th>
                    <th scope="col" class="py-2"><span class="sr-only">{{ trans('enotf.board.actions') }}</span></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                <tr v-for="r in rows" :key="r.id" :class="{ 'font-semibold': r.priority === 2 }">
                    <td class="py-2 pr-3">{{ r.eta ?? '–' }}</td>
                    <td class="py-2 pr-3">{{ trans(`enotf.prereg.priority_${r.priority}`) }}</td>
                    <td class="py-2 pr-3">{{ r.vehicle ?? '–' }} <span class="block text-xs font-normal text-text-2">{{ r.enr }}</span></td>
                    <td class="py-2 pr-3">{{ r.note ?? '' }}</td>
                    <td class="py-2 pr-3">{{ trans(`enotf.prereg.status.${r.status}`) }}</td>
                    <td class="flex flex-wrap gap-2 py-2">
                        <button v-if="r.status === 'open'" type="button" class="min-h-11 rounded-md border border-border px-3" @click="mark(r, 'seen')">{{ trans('enotf.board.mark_seen') }}</button>
                        <button type="button" class="min-h-11 rounded-md border border-border px-3" @click="mark(r, 'arrived')">{{ trans('enotf.board.mark_arrived') }}</button>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
