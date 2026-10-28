<script setup lang="ts">
/**
 * Page "Abschluss" (legacy protokoll/abschluss.php): topic column (Besonderheiten, Nachforderung NA only on a transport
 * protocol, Reanimation, Übergabe, "An Leitstelle senden", Klinikcode) + boxes Transportdaten (vehicles as Suggest,
 * crew read only from the protocol crew, rows per protocol type), Protokolldaten (pfname Suggest, prot_by), Rea,
 * Übergabe, Einsatzverlauf, button "Abschließen" -> ?t=freigabe (chromeless summary + fresh plausibility + release).
 */
import { trans } from 'laravel-vue-i18n';
import { computed, ref, watch } from 'vue';
import FocusFooter from '@/components/enotf/ui/FocusFooter.vue';
import ReaTree from '@/components/enotf/ui/ReaTree.vue';
import SelectSheet from '@/components/enotf/ui/SelectSheet.vue';
import Suggest, { type SuggestItem } from '@/components/enotf/ui/Suggest.vue';
import SummaryBox from '@/components/enotf/ui/SummaryBox.vue';
import TileColumn from '@/components/enotf/ui/TileColumn.vue';
import TileLink from '@/components/enotf/ui/TileLink.vue';
import { xsrfToken } from '@/lib/draftSave';
import { catalog, type Code, fieldMark, formatDate, naNachfShown, pageOf, placeLine, releaseBlock, useEnotfForm, useEnotfWizard } from '@/lib/enotfUi';

type Crew = { slot: string; position: number; name: string | null };
const props = defineProps<{
    enr: string;
    url: string;
    crew: Crew[];
    pfnameSuggestions: string[];
    vehicleSuggestions: Record<string, SuggestItem[]>;
    can: { release: boolean; withdraw: boolean; handover: boolean };
    patientSync: string;
    busy: boolean;
    destinationPoi?: string | null;
}>();
const emit = defineEmits<{ patient: []; release: []; withdraw: []; go: [page: string] }>();
const form = useEnotfForm();
const wizard = useEnotfWizard();
const t = (k: string, r?: Record<string, string>) => trans(`enotf.ui.ab.${k}`, r);
const v = (k: string) => form.values[k];
const str = (k: string) => (typeof v(k) === 'string' ? (v(k) as string) : '');
const label = (field: string, name: string) => catalog(name).find(([c]) => String(c) === String(v(field) ?? ''))?.[1] ?? '';

const TOPICS = ['besonderheiten', 'nanachf', 'rea', 'uebergabe', 'freigabe'];
const topic = computed(() => (wizard.topic.value && TOPICS.includes(wizard.topic.value) && !form.readonly ? wizard.topic.value : null));
const q = computed(() => wizard.step.value);
const protBy = computed(() => (v('prot_by') === null || v('prot_by') === undefined || v('prot_by') === '' ? null : Number(v('prot_by'))));
const PROT_BY: [Code, string][] = [
    [0, trans('enotf.ui.ab.prot_by_0')],
    [1, trans('enotf.ui.ab.prot_by_1')],
];
const crewOf = (slot: string) => props.crew.filter((c) => c.slot === slot && c.name).map((c) => c.name as string);
const range = (name: string, from: number, to: number) => catalog(name).filter(([c]) => Number(c) >= from && Number(c) <= to);

// ── overview summaries ──
const besText = computed(() => (Array.isArray(v('ebesonderheiten')) ? (v('ebesonderheiten') as Code[]).map((c) => catalog('ebesonderheiten').find(([k]) => k === c)?.[1]).filter(Boolean).join(', ') : ''));
const YES_NO: [boolean, string][] = [
    [false, 'nein'],
    [true, 'ja'],
];
const naNachf = (val: boolean) => {
    if (!form.readonly) form.set('na_nachf', v('na_nachf') === val ? null : val); // tapping the chosen one again clears
};

// ── Klinikcode (legacy topic tile; plain code only in this answer) ──
const clinic = ref<{ code: string; expires_at: string } | null>(null);
const clinicError = ref('');
async function makeCode() {
    clinicError.value = '';
    const res = await fetch(`${props.url}/clinic-code`, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrfToken() },
    }).catch(() => null);
    const body = (await res?.json().catch(() => null)) as { code?: string; expires_at?: string } | null;
    if (res?.ok && body?.code) clinic.value = { code: body.code, expires_at: body.expires_at ?? '' };
    else clinicError.value = trans('enotf.clinic_code.failed');
}

// ── Freigabe: pending edits first, then the server's plausibility (same rules as the release) ──
type Open = { field: string; rule: string; message: string };
const open = ref<Open[] | null>(null);
const checkFailed = ref(false);
async function check() {
    open.value = null;
    checkFailed.value = false;
    await form.flush();
    const res = await fetch(`${props.url}/plausibility`, { credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } }).catch(() => null);
    const body = (await res?.json().catch(() => null)) as { sections?: Record<string, Open[]> } | null;
    if (res?.ok && body?.sections) open.value = Object.values(body.sections).flat();
    else checkFailed.value = true;
}
watch(topic, (tp) => tp === 'freigabe' && void check(), { immediate: true });
const block = computed(() => releaseBlock({ readonly: form.readonly, canRelease: props.can.release, open: open.value?.length ?? null }));
const armed = ref<'release' | 'withdraw' | null>(null);
const tap = (what: 'release' | 'withdraw') => {
    if (armed.value !== what) {
        armed.value = what;
        return;
    }
    armed.value = null;
    if (what === 'release') emit('release');
    else emit('withdraw');
};
const missing = (text: string) => ({ text, missing: true });
const line = (value: string, fallback: string) => (value.trim() ? { text: value, missing: false } : missing(t(`missing.${fallback}`)));
const vehicleOf = (by: number | null) => (by === 0 ? line(str('fzg_transp'), 'fzg_transp') : by === 1 ? line(str('fzg_na'), 'fzg_na') : missing(t('missing.prot_by')));
const crewLine = (by: number | null) => {
    const names = by === 1 ? crewOf('na') : by === 0 ? crewOf('transport') : [];
    return names.length ? { text: names.join(', '), missing: false } : missing(t(by === null ? 'missing.prot_by' : 'missing.crew'));
};
const summary = computed(() => [
    { title: t('patient'), lines: [{ text: `${[str('pat_vorname'), str('pat_nachname')].filter(Boolean).join(' ') || t('missing.name')} * ${formatDate(str('patgebdat')) || t('missing.gebdat')}`, missing: false }] },
    {
        title: t('transport'),
        lines: [
            line(placeLine(v('transp_poi'), v('transp_adresse')), 'von'),
            line(placeLine(v('ziel_poi'), v('ziel_adresse')) || (v('destination_poi_id') ? (props.destinationPoi ?? '') : ''), 'nach'),
        ].map((l, i) => ({ ...l, text: `${t(i ? 'nach' : 'von')}: ${l.text}` })),
    },
    { title: t('besatzung'), lines: [vehicleOf(protBy.value), crewLine(protBy.value)] },
    { title: t('fzg_sonst'), lines: [line(str('fzg_sonst'), 'fzg_sonst')] },
    { title: t('einsatz'), lines: [{ text: `${t('enr')}: ${props.enr}`, missing: false }, line([formatDate(str('edatum')), str('ezeit')].filter(Boolean).join(', '), 'beginn')] },
    { title: t('protokollant'), lines: [line(str('pfname'), 'pfname'), vehicleOf(protBy.value)] },
]);
</script>

<template>
    <!-- Freigabe (chromeless, legacy abschluss/freigabe.php) -->
    <div v-if="topic === 'freigabe'" class="space-y-4">
        <div class="grid gap-4 md:grid-cols-2">
            <section v-for="s in summary" :key="s.title" class="enotf-box">
                <h2 class="enotf-box__title">{{ s.title }}</h2>
                <p v-for="(l, i) in s.lines" :key="i" :class="l.missing ? 'text-(--enotf-label)' : ''">{{ l.text }}</p>
            </section>
        </div>
        <section class="enotf-box" aria-live="polite">
            <h2 class="enotf-box__title">{{ t('plausibility') }}</h2>
            <p v-if="checkFailed" role="alert" class="text-(--enotf-red)">{{ trans('enotf.plausibility_panel.error') }}</p>
            <p v-else-if="open === null">{{ t('checking') }}</p>
            <p v-else-if="open.length === 0" class="text-(--enotf-ok)">{{ trans('enotf.plausibility_panel.ok') }}</p>
            <ul v-else class="space-y-1">
                <li v-for="o in open" :key="o.rule">
                    <button type="button" class="text-start underline" @click="emit('go', pageOf[o.field] ?? 'abschluss')">{{ o.message }}</button>
                </li>
            </ul>
        </section>
        <p v-if="block && block !== 'unchecked'" class="text-(--enotf-label)">{{ t(`block.${block}`) }}</p>
        <FocusFooter
            :remove="can.withdraw"
            :save="!block"
            :busy="busy"
            :save-label="armed === 'release' ? t('release_sure') : t('release')"
            @back="wizard.open(null)"
            @remove="tap('withdraw')"
            @save="tap('release')"
        />
        <p v-if="armed === 'withdraw'" role="alert" class="text-(--enotf-red)">{{ t('withdraw_sure') }}</p>
    </div>

    <div v-else class="flex gap-4">
        <div v-if="!form.readonly || can.handover" class="enotf-col w-56 shrink-0">
            <template v-if="!form.readonly">
                <TileLink t="besonderheiten" :requires="['ebesonderheiten']">{{ t('topic.besonderheiten') }}</TileLink>
                <TileLink v-if="naNachfShown(v('prot_by'))" t="nanachf" :requires="['na_nachf']">{{ t('topic.nanachf') }}</TileLink>
                <TileLink t="rea" :requires="['rea_status', 'rea_details']">{{ t('topic.rea') }}</TileLink>
                <TileLink t="uebergabe">{{ t('topic.uebergabe') }}</TileLink>
                <button type="button" class="enotf-tile" :disabled="patientSync !== 'none'" @click="emit('patient')">
                    <span>{{ patientSync === 'none' ? t('topic.leitstelle') : trans(`enotf.patient_sync.${patientSync}`) }}</span>
                </button>
            </template>
            <button v-if="can.handover" type="button" class="enotf-tile" @click="makeCode"><span>{{ t('topic.klinikcode') }}</span></button>
        </div>

        <div v-if="topic === 'besonderheiten'" class="flex min-w-0 flex-1 gap-4">
            <TileColumn class="w-72 shrink-0" field="ebesonderheiten" :options="range('ebesonderheiten', 1, 9)" multiple :exclusive="[1]" :green="1" />
            <TileColumn class="w-72 shrink-0" field="ebesonderheiten" :options="range('ebesonderheiten', 10, 99)" multiple :exclusive="[1]" />
        </div>

        <div v-else-if="topic === 'nanachf'" class="enotf-col w-56 shrink-0" role="radiogroup" :aria-label="t('topic.nanachf')">
            <button v-for="[val, l] in YES_NO" :key="l" type="button" role="radio" class="enotf-tile" :aria-checked="v('na_nachf') === val" @click="naNachf(val)">
                <span>{{ t(l) }}</span>
            </button>
        </div>

        <ReaTree v-else-if="topic === 'rea'" />

        <div v-else-if="topic === 'uebergabe'" class="flex min-w-0 flex-1 gap-4">
            <div class="enotf-col w-48 shrink-0">
                <TileLink t="uebergabe" :q="1" :requires="['uebergabe_ort']">{{ t('ort') }}</TileLink>
                <TileLink t="uebergabe" :q="2" :requires="['uebergabe_an']">{{ t('an') }}</TileLink>
                <TileLink t="freigabe">{{ t('freigabe') }}</TileLink>
            </div>
            <template v-if="q === 1">
                <TileColumn class="w-56 shrink-0" field="uebergabe_ort" :options="range('uebergabe_ort', 1, 9)" />
                <TileColumn class="w-56 shrink-0" field="uebergabe_ort" :options="range('uebergabe_ort', 10, 99)" />
            </template>
            <TileColumn v-else-if="q === 2" class="w-56 shrink-0" field="uebergabe_an" :options="catalog('uebergabe_an')" />
        </div>

        <div v-else class="grid min-w-0 flex-1 grid-cols-2 content-start gap-4">
            <div v-if="clinic || clinicError" class="enotf-box col-span-2" role="status">
                <template v-if="clinic">
                    <span class="enotf-box__title">{{ trans('enotf.clinic_code.title') }}</span>
                    <p class="font-mono text-2xl tracking-wider select-all">{{ clinic.code }}</p>
                    <p class="enotf-label">{{ trans('enotf.clinic_code.valid_until', { at: clinic.expires_at }) }}</p>
                </template>
                <p v-else role="alert" class="text-(--enotf-red)">{{ clinicError }}</p>
            </div>

            <section class="enotf-box space-y-3">
                <h2 class="enotf-box__title">{{ t('transportdaten') }}</h2>
                <template v-if="protBy !== 1">
                    <label class="enotf-label block">{{ t('fzg_transp') }}<Suggest :model-value="str('fzg_transp')" :data-mark="fieldMark(form, 'fzg_transp')" :items="vehicleSuggestions.fzg_transp ?? []" :label="t('fzg_transp')" :disabled="form.readonly" @update:model-value="(x) => form.set('fzg_transp', x)" /></label>
                    <div>
                        <span class="enotf-label">{{ t('crew_transport') }}</span>
                        <p class="enotf-input">{{ crewOf('transport').join(', ') }}</p>
                    </div>
                </template>
                <template v-if="protBy !== 0">
                    <label class="enotf-label block">{{ t('fzg_na') }}<Suggest :model-value="str('fzg_na')" :data-mark="fieldMark(form, 'fzg_na')" :items="vehicleSuggestions.fzg_na ?? []" :label="t('fzg_na')" :disabled="form.readonly" @update:model-value="(x) => form.set('fzg_na', x)" /></label>
                    <div>
                        <span class="enotf-label">{{ t('crew_na') }}</span>
                        <p class="enotf-input">{{ crewOf('na').join(', ') }}</p>
                    </div>
                </template>
                <label class="enotf-label block"
                    >{{ t('fzg_sonst') }}<input :value="str('fzg_sonst')" type="text" class="enotf-input" :data-mark="fieldMark(form, 'fzg_sonst')" maxlength="255" :disabled="form.readonly" @input="form.set('fzg_sonst', ($event.target as HTMLInputElement).value || null)"
                /></label>
            </section>

            <div class="space-y-4">
                <section class="enotf-box space-y-3">
                    <h2 class="enotf-box__title">{{ t('protokolldaten') }}</h2>
                    <label class="enotf-label block">{{ t('pfname') }}<Suggest :model-value="str('pfname')" :data-mark="fieldMark(form, 'pfname')" :items="pfnameSuggestions" :label="t('pfname')" :disabled="form.readonly" @update:model-value="(x) => form.set('pfname', x)" /></label>
                    <div>
                        <span class="enotf-label">{{ t('prot_by') }}</span>
                        <SelectSheet field="prot_by" :options="PROT_BY" :label="t('prot_by')" />
                    </div>
                </section>
                <SummaryBox :title="t('topic.rea')" t="rea" :requires="['rea_status', 'rea_details']">
                    <p class="enotf-input">{{ label('rea_status', 'rea_status') }}</p>
                </SummaryBox>
                <SummaryBox :title="t('topic.uebergabe')" t="uebergabe">
                    <dl class="enotf-dl">
                        <div><dt>{{ t('ort') }}</dt><dd>{{ label('uebergabe_ort', 'uebergabe_ort') }}</dd></div>
                        <div><dt>{{ t('an') }}</dt><dd>{{ label('uebergabe_an', 'uebergabe_an') }}</dd></div>
                    </dl>
                </SummaryBox>
                <SummaryBox :title="t('einsatzverlauf')" t="besonderheiten" :requires="['ebesonderheiten']">
                    <dl class="enotf-dl">
                        <div><dt>{{ t('topic.besonderheiten') }}</dt><dd>{{ besText }}</dd></div>
                    </dl>
                </SummaryBox>
            </div>

            <button v-if="!form.readonly" type="button" class="enotf-footer__btn enotf-footer__btn--primary col-span-2 min-h-[74px] text-xl" @click="wizard.open('freigabe')">
                {{ t('abschliessen') }}
            </button>
        </div>
    </div>
</template>
