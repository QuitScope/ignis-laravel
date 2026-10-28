<script setup lang="ts">
/**
 * Page "Rett. Daten" (legacy protokoll/rettdaten.php): overview boxes Patientendaten, Transport von / Einsatzort,
 * Einsatzdaten, Transportziel, Zeiten; focus views ?t=von|ziel (chromeless) edit the address locally until "speichern"
 * (PUT address/{transport|target}, special rights through set), "zurück" discards.
 */
import { trans } from 'laravel-vue-i18n';
import { computed, reactive, ref } from 'vue';
import FocusFooter from '@/components/enotf/ui/FocusFooter.vue';
import MaskedDate from '@/components/enotf/ui/MaskedDate.vue';
import MaskedTime from '@/components/enotf/ui/MaskedTime.vue';
import SelectSheet from '@/components/enotf/ui/SelectSheet.vue';
import StatusTime from '@/components/enotf/ui/StatusTime.vue';
import Suggest, { type SuggestItem } from '@/components/enotf/ui/Suggest.vue';
import SummaryBox from '@/components/enotf/ui/SummaryBox.vue';
import TriState from '@/components/enotf/ui/TriState.vue';
import { type Address, addressLine, ageOn, catalog, type Code, fieldMark, nowWall, useEnotfForm, useEnotfWizard, useFocusDraft } from '@/lib/enotfUi';

export type PutAddress = (kind: 'transport' | 'target', body: Record<string, unknown>, local: Record<string, unknown>) => Promise<boolean>;
type PoiHit = { id: number; name: string; address: Address };

const props = defineProps<{ enr: string; putAddress: PutAddress }>();
const form = useEnotfForm();
const wizard = useEnotfWizard();
const t = (k: string) => trans(`enotf.ui.rd.${k}`);
const str = (k: string) => (form.values[k] === null || form.values[k] === undefined ? '' : String(form.values[k]));
const val = (k: string) => (typeof form.values[k] === 'string' ? (form.values[k] as string) : null);

const age = computed(() => ageOn(form.values.patgebdat, nowWall(form.clock, form.timezone).slice(0, 10)));
const tzOptions = computed<[Code, string][]>(() =>
    form.values.destination_poi_id && form.values.transportziel == null ? [...catalog('transportziel'), ['poi', str('ziel_poi') || t('poi')]] : catalog('transportziel'),
);
const tzValue = computed(() => (form.values.transportziel ?? (form.values.destination_poi_id ? 'poi' : null)) as Code | null);
const setTz = (v: Code | null) => v !== 'poi' && form.set('transportziel', v);

const TIMES: [string, boolean][] = [['salarm', true], ['s3', false], ['s4', false], ['spat', true], ['s7', true], ['s8', true], ['s1', false], ['s2', false], ['sende', true]];

// ── focus view von/ziel ──
const KINDS = {
    von: { poi: 'transp_poi', addr: 'transp_adresse', sr: 'sonderrechte_anfahrt', api: 'transport' },
    ziel: { poi: 'ziel_poi', addr: 'ziel_adresse', sr: 'sonderrechte_transport', api: 'target' },
} as const;
const kind = computed(() => (wizard.topic.value === 'ziel' ? KINDS.ziel : KINDS.von));
const focus = computed(() => wizard.topic.value === 'von' || wizard.topic.value === 'ziel');
const draft = reactive({ poi: '', poiId: null as number | null, strasse: '', hnr: '', ort: '', ortsteil: '', sr: null as string | null, touched: false });
const error = ref('');
const busy = ref(false);
const focusDraft = useFocusDraft(
    () => (focus.value ? wizard.topic.value : null),
    draft,
    () => {
        error.value = '';
        const a = (form.values[kind.value.addr] ?? {}) as Address;
        return { poi: str(kind.value.poi), poiId: null, strasse: a.strasse ?? '', hnr: a.hnr ?? '', ort: a.ort ?? '', ortsteil: a.ortsteil ?? '', sr: (form.values[kind.value.sr] as string | null) ?? null, touched: false };
    },
);
const touch = () => {
    draft.touched = true;
    draft.poiId = null;
};

const searchPois = async (q: string): Promise<SuggestItem[]> => {
    if (q.length < 2) return [];
    const res = await fetch(`/enotf/pois/search?q=${encodeURIComponent(q)}`, { credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } }).catch(() => null);
    const body = res?.ok ? ((await res.json().catch(() => null)) as { data: PoiHit[] } | null) : null;
    return (body?.data ?? []).map((p) => ({ value: p.name, hint: addressLine(p.address), data: p }));
};
const pickPoi = (item: SuggestItem) => {
    const p = typeof item === 'string' ? null : (item.data as PoiHit);
    if (!p) return;
    Object.assign(draft, { poi: p.name, strasse: p.address.strasse ?? '', hnr: p.address.hnr ?? '', ort: p.address.ort ?? '', ortsteil: p.address.ortsteil ?? '', touched: true });
    draft.poiId = kind.value.api === 'target' ? p.id : null; // the scene takes the address only (no POI link on the server)
};

const save = async () => {
    if (busy.value) return;
    const k = kind.value;
    busy.value = true;
    error.value = '';
    if (focusDraft.changed('sr')) form.set(k.sr, draft.sr);
    let ok = true;
    if (draft.touched) {
        const parts = { strasse: draft.strasse.trim() || null, hnr: draft.hnr.trim() || null, ort: draft.ort.trim() || null, ortsteil: draft.ortsteil.trim() || null };
        const address = Object.values(parts).some((v) => v !== null) ? parts : null;
        const poi = draft.poi.trim() || null;
        if (draft.poiId !== null) {
            ok = await props.putAddress('target', { poi_id: draft.poiId }, { ziel_poi: poi, ziel_adresse: address, transportziel: null, destination_poi_id: draft.poiId });
        } else {
            const drop = k.api === 'target' && !!form.values.destination_poi_id;
            ok = await props.putAddress(k.api, drop ? { address, poi_id: null } : { address }, drop ? { [k.addr]: address, ziel_poi: null, transportziel: null, destination_poi_id: null } : { [k.addr]: address });
            if (ok && focusDraft.changed('poi')) form.set(k.poi, poi);
        }
    }
    await form.flush();
    busy.value = false;
    if (!ok) error.value = trans('enotf.poi.failed');
    else wizard.open(null);
};
</script>

<template>
    <div v-if="focus" class="space-y-4">
        <div>
            <h2 class="enotf-box__title">{{ t('poi_head') }}</h2>
            <Suggest v-model="draft.poi" :data-mark="fieldMark(form, kind.poi)" :load="searchPois" :label="t('poi_head')" :maxlength="255" @update:model-value="touch" @pick="pickPoi" />
        </div>
        <div class="grid grid-cols-[minmax(0,1fr)_minmax(0,14rem)_minmax(0,12rem)] gap-3">
            <label class="enotf-label">{{ t('strasse') }}<input v-model="draft.strasse" type="text" class="enotf-input" :data-mark="fieldMark(form, kind.addr)" maxlength="255" @input="touch" /></label>
            <label class="enotf-label">{{ t('hnr') }}<input v-model="draft.hnr" type="text" class="enotf-input" :data-mark="fieldMark(form, kind.addr)" maxlength="255" @input="touch" /></label>
            <div class="enotf-label">
                {{ t(kind.api === 'target' ? 'sr_transport' : 'sr_anfahrt') }}
                <TriState v-model="draft.sr" :data-mark="fieldMark(form, kind.sr)" :label="t(kind.api === 'target' ? 'sr_transport' : 'sr_anfahrt')" />
            </div>
        </div>
        <div class="grid grid-cols-2 gap-3">
            <label class="enotf-label">{{ t('ort') }}<input v-model="draft.ort" type="text" class="enotf-input" :data-mark="fieldMark(form, kind.addr)" maxlength="255" @input="touch" /></label>
            <label class="enotf-label">{{ t('ortsteil') }}<input v-model="draft.ortsteil" type="text" class="enotf-input" :data-mark="fieldMark(form, kind.addr)" maxlength="255" @input="touch" /></label>
        </div>
        <p v-if="error" role="alert" class="text-(--enotf-red)">{{ error }}</p>
        <FocusFooter :busy="busy" @back="wizard.open(null)" @save="save" />
    </div>

    <div v-else class="space-y-4">
        <SummaryBox :title="t('patient')" :requires="['patsex']">
            <div class="grid grid-cols-2 gap-3">
                <label class="enotf-label">{{ trans('enotf.f.pat_vorname') }}<input :value="str('pat_vorname')" type="text" class="enotf-input" :data-mark="fieldMark(form, 'pat_vorname')" maxlength="255" placeholder="Max" :disabled="form.readonly" @input="form.set('pat_vorname', ($event.target as HTMLInputElement).value)" /></label>
                <label class="enotf-label">{{ trans('enotf.f.pat_nachname') }}<input :value="str('pat_nachname')" type="text" class="enotf-input" :data-mark="fieldMark(form, 'pat_nachname')" maxlength="255" placeholder="Mustermann" :disabled="form.readonly" @input="form.set('pat_nachname', ($event.target as HTMLInputElement).value)" /></label>
            </div>
            <div class="mt-3 grid grid-cols-[minmax(0,1fr)_minmax(0,1fr)_6rem] gap-3">
                <div class="enotf-label">{{ t('sex') }}<SelectSheet field="patsex" :options="catalog('patsex')" :label="t('sex')" placeholder="---" /></div>
                <div class="enotf-label">{{ t('birthdate') }}<MaskedDate :model-value="val('patgebdat')" :data-mark="fieldMark(form, 'patgebdat')" :label="t('birthdate')" :disabled="form.readonly" @update:model-value="form.set('patgebdat', $event)" /></div>
                <div class="enotf-label">{{ t('age') }}<output class="enotf-input">{{ age ?? 0 }}</output></div>
            </div>
        </SummaryBox>

        <SummaryBox :title="t('von')" t="von" :requires="['transp_adresse']" :marks="['transp_poi', 'sonderrechte_anfahrt']">
            <span class="enotf-label block">{{ t('von_poi') }}</span>
            <span class="enotf-input">{{ str('transp_poi') || ' ' }}</span>
            <span class="enotf-label mt-2 block">{{ t('von_addr') }}</span>
            <span class="enotf-input">{{ addressLine(form.values.transp_adresse) || ' ' }}</span>
        </SummaryBox>

        <SummaryBox :title="t('mission')" :requires="['edatum', 'ezeit', 'transportziel', 'eart']">
            <div class="grid grid-cols-3 gap-3">
                <div class="enotf-label">{{ t('enr') }}<output class="enotf-input">{{ enr }}</output></div>
                <div class="enotf-label">{{ t('edatum') }}<MaskedDate :model-value="val('edatum')" :data-mark="fieldMark(form, 'edatum')" :label="t('edatum')" :disabled="form.readonly" @update:model-value="form.set('edatum', $event)" /></div>
                <div class="enotf-label">{{ t('ezeit') }}<MaskedTime :model-value="val('ezeit')" :data-mark="fieldMark(form, 'ezeit')" :label="t('ezeit')" :disabled="form.readonly" @update:model-value="form.set('ezeit', $event)" /></div>
            </div>
            <div class="mt-3 grid grid-cols-2 gap-3">
                <div class="enotf-label">{{ t('versorgung') }}<SelectSheet :model-value="tzValue" :data-mark="fieldMark(form, 'transportziel', 'destination_poi_id')" :options="tzOptions" :label="t('versorgung')" placeholder="---" :disabled="form.readonly" @update:model-value="setTz" /></div>
                <div class="enotf-label">{{ t('eart') }}<SelectSheet field="eart" :options="catalog('eart')" :label="t('eart')" placeholder="---" /></div>
            </div>
        </SummaryBox>

        <SummaryBox :title="t('ziel')" t="ziel" :requires="['ziel_adresse']" :marks="['ziel_poi', 'sonderrechte_transport']">
            <span class="enotf-label block">{{ t('ziel_poi') }}</span>
            <span class="enotf-input">{{ str('ziel_poi') || ' ' }}</span>
            <span class="enotf-label mt-2 block">{{ t('ziel_addr') }}</span>
            <span class="enotf-input">{{ addressLine(form.values.ziel_adresse) || ' ' }}</span>
        </SummaryBox>

        <SummaryBox :title="t('times')" :requires="TIMES.map(([k]) => k)">
            <div class="grid grid-cols-3 gap-2 sm:grid-cols-5 xl:grid-cols-9">
                <StatusTime v-for="[k, req] in TIMES" :key="k" :field="k" :label="t(`time.${k}`)" :required="req" />
            </div>
        </SummaryBox>
    </div>
</template>
