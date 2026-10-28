<script setup lang="ts">
/**
 * Frame of the protocol pages (legacy _layout-protokoll.php): top bar, numbered section bar with fill stripes, notices.
 * `chromeless` = focus view (no top bar, no section bar; the page brings its own footer).
 */
import { ArrowUpDown, FileText, Hospital, LayoutGrid, RadioTower, RefreshCw, Save, Share2, ShieldAlert } from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import SelectSheet from '@/components/enotf/ui/SelectSheet.vue';
import { toWall } from '@/lib/calendar';
import { xsrfToken } from '@/lib/draftSave';
import type { Clock, Code, Fill } from '@/lib/enotfUi';

const props = defineProps<{
    enr: string;
    pages: { slug: string; label: string }[];
    fills: Record<string, Fill>;
    current: string;
    chromeless: boolean;
    crew: { slot: string; position: number; name: string | null }[];
    clock: Clock;
    timezone: string;
    readonly: boolean;
    status: { text: string; alert: boolean };
    patientSync: string;
    emdSyncAt: string | null;
    can: { handover: boolean; share: boolean; qm: boolean };
    protBy: number | null;
    flush: () => Promise<void>;
}>();
const emit = defineEmits<{ go: [slug: string]; patient: []; protBy: [value: number] }>();

const base = `/enotf/p/${encodeURIComponent(props.enr)}`;
const wall = ref('');
const dispatch = ref<'ok' | 'lost' | 'unknown'>('unknown');
const DISPATCH = { ok: ['var(--enotf-dispatch-ok)', 'enotf.ui.connected'], lost: ['var(--enotf-dispatch-lost)', 'enotf.ui.disconnected'], unknown: ['var(--enotf-white)', 'enotf.ui.dispatch_unknown'] } as const;
const tick = () => {
    const now = props.clock.now();
    wall.value = toWall(now, props.timezone);
    const at = props.emdSyncAt ? Date.parse(props.emdSyncAt) : NaN;
    dispatch.value = Number.isNaN(at) ? 'unknown' : now.getTime() - at <= 120_000 ? 'ok' : 'lost';
};
watch(() => props.emdSyncAt, tick);
let timer: number | undefined;
onMounted(() => {
    tick();
    timer = window.setInterval(tick, 15_000);
});
onBeforeUnmount(() => window.clearInterval(timer));
const artOpen = ref(false);
const pickArt = (by: number) => {
    artOpen.value = false;
    if (by !== props.protBy) emit('protBy', by);
};
type ShareVehicle = { id: number; identifier: string; name: string };
const share = ref({ open: false, vehicles: [] as [Code, string][], to: null as Code | null, sent: null as number | null, message: '' });
const json = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
const post = (url: string, body?: unknown) =>
    fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { ...json, 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrfToken() },
        body: body === undefined ? undefined : JSON.stringify(body),
    }).catch(() => null);
const openShare = async () => {
    share.value = { ...share.value, open: true, message: '' };
    const res = await fetch('/enotf/share/vehicles', { credentials: 'same-origin', headers: json }).catch(() => null);
    const body = (await res?.json().catch(() => null)) as { vehicles?: ShareVehicle[] } | null;
    share.value.vehicles = (body?.vehicles ?? []).map((v) => [v.id, `${v.identifier} ${v.name}`]);
};
const sendShare = async () => {
    if (share.value.to === null) return;
    await props.flush();
    const res = await post(`${base}/share`, { to_vehicle_id: share.value.to });
    const body = (await res?.json().catch(() => null)) as { id?: number; message?: string } | null;
    share.value.sent = res?.status === 201 && body?.id ? body.id : null;
    share.value.message = share.value.sent ? trans('enotf.share.sent') : (body?.message ?? trans('enotf.share.failed'));
};
const cancelShare = async () => {
    const res = await post(`/enotf/share/${share.value.sent}/cancel`);
    share.value.message = res?.ok ? trans('enotf.share.cancelled') : trans('enotf.share.failed');
    if (res?.ok) share.value.sent = null;
};
const syncColor = { none: 'var(--enotf-white)', pending: 'var(--enotf-yellow)', synced: 'var(--enotf-green)' } as Record<string, string>;
</script>

<template>
    <div :class="['enotf flex flex-col', chromeless ? 'fixed inset-0 z-40 overflow-y-auto' : '-m-4 min-h-[calc(100vh-2.75rem)]']">
        <template v-if="!chromeless">
            <header class="enotf-topbar">
                <nav class="flex flex-wrap items-center" :aria-label="trans('enotf.ui.topbar')">
                    <a href="/enotf/overview" class="enotf-iconlink"><LayoutGrid class="size-8" aria-hidden="true" />{{ trans('enotf.ui.start') }}</a>
                    <a v-if="can.handover && !readonly" :href="`${base}/prereg`" class="enotf-iconlink"><Hospital class="size-6" aria-hidden="true" />{{ trans('enotf.ui.prereg') }}</a>
                    <button v-if="!readonly" type="button" class="enotf-iconlink" @click="artOpen = true"><RefreshCw class="size-6" aria-hidden="true" />{{ trans('enotf.ui.art') }}</button>
                    <button v-if="can.share && !readonly" type="button" class="enotf-iconlink" @click="openShare"><Share2 class="size-6" aria-hidden="true" />{{ trans('enotf.ui.share') }}</button>
                    <a :href="`/enotf/print/${encodeURIComponent(enr)}`" class="enotf-iconlink"><FileText class="size-6" aria-hidden="true" />{{ trans('enotf.ui.print') }}</a>
                    <a v-if="can.qm" :href="`/enotf/qm/${encodeURIComponent(enr)}`" class="enotf-iconlink"><ShieldAlert class="size-6" aria-hidden="true" />{{ trans('enotf.ui.qm') }}</a>
                </nav>
                <div class="flex items-center gap-4">
                    <a href="/enotf/loggedout" class="flex flex-col items-center px-4 text-sm leading-tight text-white no-underline">
                        <span class="flex gap-3">
                            <span v-for="c in crew" :key="`${c.slot}-${c.position}`">{{ c.name }}</span>
                        </span>
                        <small class="text-[0.65rem]">{{ trans('enotf.ui.logout') }}</small>
                    </a>
                    <div class="enotf-cluster">
                        <span class="flex items-center gap-2">
                            <RadioTower class="size-4" :style="{ color: DISPATCH[dispatch][0] }" role="img" :aria-label="trans(DISPATCH[dispatch][1])" />
                        </span>
                        <span class="flex items-center gap-2">
                            <button
                                type="button"
                                class="inline-flex"
                                :disabled="readonly || patientSync !== 'none'"
                                :aria-label="trans(patientSync === 'none' ? 'enotf.patient_sync.button' : `enotf.patient_sync.${patientSync}`)"
                                :title="trans(patientSync === 'none' ? 'enotf.patient_sync.button' : `enotf.patient_sync.${patientSync}`)"
                                @click="emit('patient')"
                            >
                                <ArrowUpDown class="size-4" :style="{ color: syncColor[patientSync] ?? 'var(--enotf-white)' }" aria-hidden="true" />
                            </button>
                            <Save class="size-4" aria-hidden="true" />
                            <span role="status" class="max-w-48 truncate text-xs">{{ status.alert ? '' : status.text || trans('enotf.ui.ready') }}</span>
                        </span>
                    </div>
                    <div class="enotf-cluster items-end pe-2">
                        <span>{{ wall.slice(11, 16) }}</span>
                        <span>{{ wall.slice(8, 10) }}.{{ wall.slice(5, 7) }}.{{ wall.slice(0, 4) }}</span>
                    </div>
                </div>
            </header>
            <p v-if="readonly" class="enotf-notice" role="status">{{ trans('enotf.protocol.readonly') }}</p>
        </template>
        <p v-if="status.alert" class="enotf-notice enotf-notice--alert" role="alert">{{ status.text }}</p>

        <div class="flex flex-1">
            <nav v-if="!chromeless" class="enotf-nav" :aria-label="trans('enotf.ui.sections')">
                <button
                    v-for="p in pages"
                    :key="p.slug"
                    type="button"
                    class="enotf-nav__item enotf-stripe"
                    :data-fill="fills[p.slug] ?? undefined"
                    :aria-current="p.slug === current ? 'page' : undefined"
                    @click="emit('go', p.slug)"
                >
                    <span>{{ trans(p.label) }}</span>
                    <span v-if="fills[p.slug] === 'red' || fills[p.slug] === 'yellow'" class="sr-only">{{ trans('enotf.ui.required_open') }}</span>
                </button>
            </nav>
            <div class="min-w-0 flex-1 p-4"><slot /></div>
        </div>
    </div>

    <div v-if="artOpen" class="enotf enotf-modal" @click.self="artOpen = false" @keydown.escape="artOpen = false">
        <div class="enotf-modal__box" role="dialog" aria-modal="true" aria-labelledby="enotf-art-title">
            <h2 id="enotf-art-title" class="enotf-box__title">{{ trans('enotf.ui.art_title') }}</h2>
            <div class="grid grid-cols-2 gap-2">
                <button v-for="by in [0, 1]" :key="by" type="button" class="enotf-tile flex-col justify-center" :aria-pressed="protBy === by" @click="pickArt(by)">
                    <span class="text-3xl">{{ by ? 'NA' : 'NF' }}</span>
                    <span>{{ trans(`enotf.ui.art_${by}`) }}</span>
                    <small v-if="protBy === by" class="text-(--enotf-label)">{{ trans('enotf.ui.art_current') }}</small>
                </button>
            </div>
            <button type="button" class="enotf-footer__btn mt-3" @click="artOpen = false">{{ trans('enotf.ui.back') }}</button>
        </div>
    </div>

    <div v-if="share.open" class="enotf enotf-modal" @click.self="share.open = false" @keydown.escape="share.open = false">
        <div class="enotf-modal__box space-y-3" role="dialog" aria-modal="true" aria-labelledby="enotf-share-title">
            <h2 id="enotf-share-title" class="enotf-box__title">{{ trans('enotf.share.button') }}</h2>
            <SelectSheet
                v-model="share.to"
                :options="share.vehicles"
                :label="trans('enotf.share.to')"
                :placeholder="share.vehicles.length ? trans('enotf.share.pick') : trans('enotf.share.no_vehicles')"
                :disabled="share.sent !== null"
            />
            <button v-if="share.sent === null" type="button" class="enotf-footer__btn enotf-footer__btn--primary" :disabled="share.to === null" @click="sendShare">
                {{ trans('enotf.share.send') }}
            </button>
            <button v-else type="button" class="enotf-footer__btn enotf-footer__btn--danger" @click="cancelShare">{{ trans('enotf.share.cancel') }}</button>
            <p v-if="share.message" role="status" class="text-sm">{{ share.message }}</p>
            <button type="button" class="enotf-footer__btn" @click="share.open = false">{{ trans('enotf.ui.back') }}</button>
        </div>
    </div>
</template>
