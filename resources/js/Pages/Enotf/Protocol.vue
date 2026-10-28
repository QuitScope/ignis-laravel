<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed, onBeforeUnmount, onMounted, provide, reactive, ref, shallowRef, watch, watchEffect } from 'vue';
import type { CatalogEntry, GivenRow } from '@/components/enotf/MedicationsSection.vue';
import ProtocolShell from '@/components/enotf/ProtocolShell.vue';
import Abschluss from '@/components/enotf/pages/Abschluss.vue';
import Anamnese from '@/components/enotf/pages/Anamnese.vue';
import Diagnose from '@/components/enotf/pages/Diagnose.vue';
import Erstbefund from '@/components/enotf/pages/Erstbefund.vue';
import Massnahmen from '@/components/enotf/pages/Massnahmen.vue';
import Rettdaten from '@/components/enotf/pages/Rettdaten.vue';
import Verlauf from '@/components/enotf/pages/Verlauf.vue';
import TabletLayout from '@/Layouts/TabletLayout.vue';
import { xsrfToken } from '@/lib/draftSave';
import { createEnotfAutosave, type PatchResponse } from '@/lib/enotfAutosave';
import { lockscreenUrl, useEnotfSession } from '@/lib/enotfSession';
import { createEnotfSync } from '@/lib/enotfSync';
import { addressLine, createClock, emptyValue, type EnotfForm, type FieldDef, fieldText, PAGES, pageStatus, useWizard } from '@/lib/enotfUi';
import { usePulse } from '@/lib/realtime';
import type { BzUnit, VitalRow } from '@/lib/enotfVitals';

defineOptions({ layout: TabletLayout });

const props = defineProps<{
    protocol: {
        enr: string;
        status: string;
        revision: number;
        patient_sync: string;
        vehicles: string[];
        destination_poi: string | null;
    };
    values: Record<string, unknown>;
    section: string;
    vitals: VitalRow[];
    bz_unit: BzUnit;
    medications: GivenRow[];
    medication_catalog: CatalogEntry[];
    medication_routes: string[];
    medication_units: Record<string, string>;
    readonly: boolean;
    can: { release: boolean; withdraw: boolean; share: boolean; handover: boolean; qm: boolean };
    now: string;
    timezone: string;
    crew: { slot: string; position: number; name: string | null }[];
    vehicle_suggestions: Record<string, { value: string; hint?: string }[]>;
    pfname_suggestions: string[];
    vital_max: Record<string, number>;
    schema: Record<string, FieldDef[]>;
}>();

const base = `/enotf/p/${encodeURIComponent(props.protocol.enr)}`;
const slugs = PAGES.map((p) => p.slug);
const current = ref(slugs.includes(props.section) ? props.section : 'rettdaten');
const clock = createClock(props.now);
const wizard = useWizard();

const save = createEnotfAutosave({
    url: base,
    revision: props.protocol.revision,
    values: props.values,
    onChange: () => sync(),
});
const view = shallowRef({ ...save.state });
const sync = () => {
    view.value = { ...save.state, values: { ...save.state.values } };
    if (save.state.status === 'locked') location.assign(lockscreenUrl());
};
const devices = createEnotfSync({ url: base, enr: props.protocol.enr, autosave: save });
const { vehicle, emdSyncAt } = useEnotfSession(props.readonly ? undefined : (st) => void devices.onStatus(st.protocols), (t) => clock.sync(t));
usePulse(() => (vehicle.value ? `enotf.vehicle.${vehicle.value}` : null), ['rows'], () => router.reload({ only: ['vitals', 'medications'] }), { pollMs: 0 });
const medRows = shallowRef<GivenRow[]>(props.medications);
watch(() => props.medications, (r) => (medRows.value = r));
const vitalRows = shallowRef<VitalRow[]>(props.vitals);
watch(() => props.vitals, (r) => (vitalRows.value = r));
const fills = computed(() => pageStatus({ ...view.value.values, medis: medRows.value }));
const readonly = computed(() => props.readonly || ['closed', 'forbidden'].includes(view.value.status));

const form: EnotfForm = reactive({
    values: computed(() => ({ ...view.value.values, medis: medRows.value })),
    set: (key: string, value: unknown) => save.set(key, value),
    flush: () => save.flush(),
    readonly,
    invalid: computed(() => view.value.invalid),
    conflicts: computed(() => view.value.conflicts),
    clock,
    timezone: props.timezone,
    enr: props.protocol.enr,
    vitalMax: props.vital_max,
}) as unknown as EnotfForm;
provide('enotfForm', form);
provide('enotfWizard', wizard);

const FOCUS: Record<string, string[]> = { rettdaten: ['von', 'ziel'], erstbefund: ['messwerte'], anamnese: ['anamnese'], massnahmen: ['medimaske'], verlauf: ['add'], abschluss: ['freigabe'] };
const chromeless = computed(() => !!wizard.topic.value && (FOCUS[current.value] ?? []).includes(wizard.topic.value));
watchEffect(() => readonly.value && chromeless.value && wizard.open(null));

const label = (key: string) => trans(`enotf.f.${key}`);
const statusText = computed(() => {
    const s = view.value;
    switch (s.status) {
        case 'saving':
            return trans('enotf.autosave.saving');
        case 'saved':
            return trans('enotf.autosave.saved');
        case 'conflict':
            return trans('enotf.autosave.conflict', {
                fields: s.conflicts.map(label).join(', '),
            });
        case 'offline':
            return trans('enotf.autosave.offline');
        case 'auth':
            return trans('enotf.autosave.auth');
        case 'invalid':
            return trans('enotf.autosave.invalid', {
                fields: s.invalid.map(label).join(', '),
            });
        case 'closed':
            return [
                s.closedAs && !s.message ? trans('enotf.sync.closed') : s.message || trans('enotf.protocol.closed'),
                s.unsaved.length ? trans('enotf.sync.unsaved', { fields: s.unsaved.map(label).join(', ') }) : '',
            ]
                .filter(Boolean)
                .join(' ');
        case 'forbidden':
            return trans('enotf.autosave.forbidden');
        case 'locked':
            return trans('enotf.autosave.locked');
        default:
            return '';
    }
});
const alert = computed(() => ['offline', 'auth', 'invalid', 'closed', 'forbidden', 'locked'].includes(view.value.status) || (chromeless.value && view.value.status === 'conflict'));

const merge = computed(() => view.value.conflicts.filter((k) => k in view.value.theirs));
const mergeSig = computed(() => JSON.stringify(merge.value.map((k) => [k, view.value.theirs[k]])));
const mergeLater = ref('');
const mergeOpen = computed(() => !readonly.value && merge.value.length > 0 && mergeLater.value !== mergeSig.value);
const fieldDefs = Object.fromEntries(Object.values(props.schema).flat().map((f) => [f.key, f]));
const shown = (key: string, v: unknown): string => {
    if (emptyValue(v)) return '–';
    if (key === 'transp_adresse' || key === 'ziel_adresse') return addressLine(v) || '–';
    return fieldDefs[key] ? fieldText(fieldDefs[key], v) : String(v);
};

const go = (slug: string) => {
    current.value = slug;
    try {
        window.history.replaceState(window.history.state, '', slug === 'rettdaten' ? base : `${base}/${slug}`);
    } catch {
    }
    wizard.open(null);
    window.scrollTo(0, 0);
};

const putAddress = async (kind: 'transport' | 'target', body: Record<string, unknown>, local: Record<string, unknown>): Promise<boolean> => {
    await save.flush();
    const res = await fetch(`${base}/address/${kind}`, {
        method: 'PUT',
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrfToken() },
        body: JSON.stringify({ revision: save.state.revision, ...body }),
    }).catch(() => null);
    const data = (await res?.json().catch(() => null)) as PatchResponse | null;
    if (!res?.ok || !data) return false;
    save.adopt(data, local);
    return true;
};

const releaseError = ref('');
const releasing = ref(false);
const release = async () => {
    if (releasing.value) return;
    releasing.value = true;
    releaseError.value = '';
    await save.flush();
    const res = await fetch(`${base}/release`, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrfToken() },
    }).catch(() => null);
    releasing.value = false;
    if (res && (res.ok || res.status === 409)) {
        router.visit(base);
        return;
    }
    const body = (await res?.json().catch(() => null)) as { message?: string } | null;
    releaseError.value = body?.message ?? trans('enotf.release.failed');
};
const patientSync = ref(props.protocol.patient_sync);
const sendPatient = async () => {
    await save.flush();
    const res = await fetch(`${base}/patient-sync`, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrfToken() },
    }).catch(() => null);
    const body = (await res?.json().catch(() => null)) as { patient_sync?: string; message?: string } | null;
    if (res?.ok && body?.patient_sync) patientSync.value = body.patient_sync;
    else releaseError.value = body?.message ?? trans('enotf.patient_sync.failed');
};
const withdraw = () => router.delete(base);

const leave = () => void save.flush();
let offBefore: (() => void) | undefined;
onMounted(() => {
    if (props.readonly) save.stop();
    window.addEventListener('beforeunload', leave);
    offBefore = router.on('before', leave);
});
onBeforeUnmount(() => {
    leave();
    save.stop();
    window.removeEventListener('beforeunload', leave);
    offBefore?.();
});
</script>

<template>
    <ProtocolShell
        :enr="protocol.enr"
        :pages="PAGES"
        :fills="fills"
        :current="current"
        :chromeless="chromeless"
        :crew="crew"
        :clock="clock"
        :timezone="timezone"
        :readonly="readonly"
        :status="{ text: statusText, alert }"
        :patient-sync="patientSync"
        :emd-sync-at="emdSyncAt"
        :can="{ handover: can.handover, share: can.share, qm: can.qm }"
        :prot-by="view.values.prot_by === null || view.values.prot_by === undefined ? null : Number(view.values.prot_by)"
        :flush="() => save.flush()"
        @go="go"
        @patient="sendPatient"
        @prot-by="(by) => save.set('prot_by', by)"
    >
        <p v-if="releaseError" role="alert" class="enotf-notice enotf-notice--alert mb-4">{{ releaseError }}</p>
        <Rettdaten v-if="current === 'rettdaten'" :enr="protocol.enr" :put-address="putAddress" />
        <Erstbefund v-else-if="current === 'erstbefund'" :bz-unit="bz_unit" />
        <Anamnese v-else-if="current === 'anamnese'" />
        <Diagnose v-else-if="current === 'diagnose'" />
        <Verlauf v-else-if="current === 'verlauf'" :url="`${base}/vitals`" :rows="vitalRows" :bz-unit="bz_unit" @rows="(r) => (vitalRows = r)" />
        <Massnahmen
            v-else-if="current === 'massnahmen'"
            :url="`${base}/medications`"
            :rows="medRows"
            :med-catalog="medication_catalog"
            :routes="medication_routes"
            :units="medication_units"
            @rows="(r) => (medRows = r)"
        />
        <Abschluss
            v-else
            :enr="protocol.enr"
            :url="base"
            :crew="crew"
            :pfname-suggestions="pfname_suggestions"
            :vehicle-suggestions="vehicle_suggestions"
            :can="{ release: can.release, withdraw: can.withdraw, handover: can.handover }"
            :patient-sync="patientSync"
            :busy="releasing"
            :destination-poi="protocol.destination_poi"
            @patient="sendPatient"
            @release="release"
            @withdraw="withdraw"
            @go="go"
        />
    </ProtocolShell>

    <div v-if="mergeOpen" class="enotf enotf-modal" @keydown.escape="mergeLater = mergeSig">
        <div class="enotf-modal__box space-y-3" role="dialog" aria-modal="true" aria-labelledby="enotf-merge-title">
            <h2 id="enotf-merge-title" class="enotf-box__title">{{ trans('enotf.merge.title') }}</h2>
            <p class="text-sm">{{ trans('enotf.merge.intro') }}</p>
            <div v-for="key in merge" :key="key" class="space-y-1">
                <strong>{{ label(key) }}</strong>
                <div class="grid grid-cols-2 gap-2">
                    <button type="button" class="enotf-tile flex-col items-start" @click="save.resolve(key, 'mine')">
                        <small>{{ trans('enotf.merge.mine') }}</small>
                        <span class="break-words">{{ shown(key, view.values[key]) }}</span>
                        <small>{{ trans('enotf.merge.keep_mine') }}</small>
                    </button>
                    <button type="button" class="enotf-tile flex-col items-start" @click="save.resolve(key, 'theirs')">
                        <small>{{ trans('enotf.merge.theirs') }}</small>
                        <span class="break-words">{{ shown(key, view.theirs[key]) }}</span>
                        <small>{{ trans('enotf.merge.take_theirs') }}</small>
                    </button>
                </div>
            </div>
            <button type="button" class="enotf-footer__btn" @click="mergeLater = mergeSig">{{ trans('enotf.merge.later') }}</button>
        </div>
    </div>
</template>
