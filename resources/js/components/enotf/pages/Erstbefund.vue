<script setup lang="ts">
/**
 * Page "Erstbefund" (legacy protokoll/erstbefund.php): overview = topic column + summary boxes; ?t=<topic> = topic column,
 * sub-topic column (quick tile + steps) and the answer columns of step ?q; ?t=messwerte = chromeless keypad mask (values
 * stay local until "Speichern", then one set per changed field + flush; "Abbrechen" discards).
 */
import { trans } from 'laravel-vue-i18n';
import { computed, reactive, ref } from 'vue';
import BodyMap from '@/components/enotf/ui/BodyMap.vue';
import FocusFooter from '@/components/enotf/ui/FocusFooter.vue';
import GcsPicker from '@/components/enotf/ui/GcsPicker.vue';
import NumberPad from '@/components/enotf/ui/NumberPad.vue';
import QuickFill from '@/components/enotf/ui/QuickFill.vue';
import SummaryBox from '@/components/enotf/ui/SummaryBox.vue';
import TileColumn from '@/components/enotf/ui/TileColumn.vue';
import TileLink from '@/components/enotf/ui/TileLink.vue';
import { catalog, type Code, fieldMark, gcsSum, injuryText, useEnotfForm, useEnotfWizard, useFocusDraft } from '@/lib/enotfUi';
import { type BzUnit, grade, NUMERIC, press, vitalText, vitalValue } from '@/lib/enotfVitals';

const props = defineProps<{ bzUnit: BzUnit }>();
const form = useEnotfForm();
const wizard = useEnotfWizard();
const t = (k: string) => trans(`enotf.ui.eb.${k}`);

type Col = { field: string; options: [Code, string][]; green?: Code; header?: string; multiple?: boolean; exclusive?: Code[] };
type Step = { label?: string; requires?: string[]; cols?: Col[]; widget?: 'gcs' | 'bodymap'; pupils?: boolean };
type Topic = { requires: string[]; quick?: { label: string; values: Record<string, Code | null> }; nav?: { label: string; q: number; requires?: string[] }[]; steps: Step[] };

const pick = (name: string, codes: Code[]) => codes.map((c) => catalog(name).find(([k]) => String(k) === String(c)) as [Code, string]);
const col = (field: string, name: string, o: Partial<Col> = {}): Col => ({ field, options: catalog(name), ...o });
const NEURO = ['d_bewusstsein', 'd_ex_1', 'd_pupillenw_1', 'd_pupillenw_2', 'd_lichtreakt_1', 'd_lichtreakt_2', 'd_gcs_1', 'd_gcs_2', 'd_gcs_3'];
const VITALS_REQ = ['spo2', 'atemfreq', 'rrsys', 'herzfreq', 'bz'];

const TOPICS: Record<string, Topic> = {
    atemwege: {
        requires: ['awfrei_1', 'zyanose_1'],
        quick: { label: 'ohne_path', values: { awfrei_1: 1, zyanose_1: 1 } },
        steps: [
            { label: 'awfrei', requires: ['awfrei_1'], cols: [col('awfrei_1', 'awfrei', { green: 1 })] },
            { label: 'zyanose', requires: ['zyanose_1'], cols: [col('zyanose_1', 'zyanose', { green: 1 })] },
        ],
    },
    atmung: {
        requires: ['b_symptome', 'b_auskult'],
        quick: { label: 'ohne_path', values: { b_symptome: 0, b_auskult: 0 } },
        steps: [
            { label: 'b_symptome', requires: ['b_symptome'], cols: [col('b_symptome', 'b_symptome', { green: 0 })] },
            { label: 'b_auskult', requires: ['b_auskult'], cols: [col('b_auskult', 'b_auskult', { green: 0 })] },
        ],
    },
    kreislauf: {
        requires: ['c_kreislauf', 'c_puls_rad', 'c_puls_reg'],
        quick: { label: 'ohne_path', values: { c_kreislauf: 1, c_puls_rad: 1, c_puls_reg: 1, c_rekap: 1, c_blutung: 1 } },
        steps: [
            { label: 'c_kreislauf', requires: ['c_kreislauf'], cols: [col('c_kreislauf', 'c_kreislauf', { green: 1 })] },
            { label: 'c_rekap', cols: [col('c_rekap', 'c_rekap', { green: 1 })] },
            {
                label: 'puls',
                requires: ['c_puls_rad', 'c_puls_reg'],
                cols: [
                    col('c_puls_reg', 'c_puls_reg', { green: 1 }),
                    { field: 'c_puls_rad', options: [[1, 'Radialispuls tastbar'], [2, 'Radialispuls nicht tastbar']], green: 1 },
                ],
            },
            { label: 'c_blutung', cols: [col('c_blutung', 'c_blutung', { green: 1 })] },
        ],
    },
    neurologie: {
        requires: NEURO,
        quick: { label: 'ohne_path', values: { d_bewusstsein: 1, d_ex_1: 1, d_pupillenw_1: 2, d_pupillenw_2: 2, d_lichtreakt_1: 1, d_lichtreakt_2: 1, d_gcs_1: 0, d_gcs_2: 0, d_gcs_3: 0 } },
        nav: [
            { label: 'd_bewusstsein', q: 1, requires: ['d_bewusstsein'] },
            { label: 'd_ex_1', q: 2, requires: ['d_ex_1'] },
            { label: 'pupillen', q: 3, requires: ['d_pupillenw_1', 'd_pupillenw_2', 'd_lichtreakt_1', 'd_lichtreakt_2'] },
            { label: 'gcs', q: 5, requires: ['d_gcs_1', 'd_gcs_2', 'd_gcs_3'] },
        ],
        steps: [
            { cols: [col('d_bewusstsein', 'd_bewusstsein', { green: 1 })] },
            { cols: [col('d_ex_1', 'd_extremitaeten', { green: 1 })] },
            { pupils: true, cols: [col('d_pupillenw_1', 'd_pupillenweite', { header: 'links' }), col('d_pupillenw_2', 'd_pupillenweite', { header: 'rechts' })] },
            { pupils: true, cols: [col('d_lichtreakt_1', 'd_lichtreaktion', { header: 'links' }), col('d_lichtreakt_2', 'd_lichtreaktion', { header: 'rechts' })] },
            { widget: 'gcs' },
        ],
    },
    erweitern: {
        requires: [],
        quick: { label: 'keine', values: Object.fromEntries(['k', 'w', 't', 'a', 'al', 'bl'].flatMap((r) => [[`v_muster_${r}`, 1], [`v_muster_${r}1`, null]])) },
        steps: [
            { label: 'verletzungen', widget: 'bodymap' },
            {
                label: 'schmerzen',
                cols: [{ field: 'sz_nrs', options: pick('sz_nrs', [0, 1, 2, 3, 4, 5, 6]) }, { field: 'sz_nrs', options: pick('sz_nrs', [7, 8, 9, 10, 98, 99]) }, col('sz_toleranz_1', 'sz_toleranz')],
            },
        ],
    },
    ekg: { requires: ['c_ekg'], steps: [{ cols: [{ field: 'c_ekg', options: pick('c_ekg', [1, 3, 5, 51, 4, 41, 6]), green: 1 }, { field: 'c_ekg', options: pick('c_ekg', [21, 2, 7, 71, 97, 98, 99]) }] }] },
    psychisch: {
        requires: ['psych'],
        steps: [
            {
                cols: [
                    { field: 'psych', options: pick('psych', [1, 2, 3, 4, 5, 6, 7, 8, 9]), green: 1, multiple: true, exclusive: [1, 98, 99] },
                    { field: 'psych', options: pick('psych', [10, 11, 12, 98, 99]), multiple: true, exclusive: [1, 98, 99] },
                ],
            },
        ],
    },
    messwerte: { requires: VITALS_REQ, steps: [] },
};

const topic = computed(() => (wizard.topic.value && TOPICS[wizard.topic.value] ? wizard.topic.value : null));
const def = computed(() => (topic.value ? TOPICS[topic.value] : null));
const nav = computed(() => def.value?.nav ?? (def.value && def.value.steps.length > 1 ? def.value.steps.map((s, i) => ({ label: s.label ?? '', q: i + 1, requires: s.requires })) : []));
const step = computed(() => {
    const d = def.value;
    if (!d) return null;
    const q = wizard.step.value ?? (d.steps.length === 1 && !d.nav ? 1 : null);
    return q ? (d.steps[Math.min(q, d.steps.length) - 1] ?? null) : null;
});

// ── overview ──
const label = (field: string, name: string) => catalog(name).find(([c]) => String(c) === String(form.values[field] ?? ''))?.[1] ?? '';
const gcs = computed(() => gcsSum(form.values.d_gcs_1, form.values.d_gcs_2, form.values.d_gcs_3));
const psychText = computed(() => (Array.isArray(form.values.psych) ? (form.values.psych as Code[]).map((c) => catalog('psych').find(([k]) => k === c)?.[1]).filter(Boolean).join(', ') : ''));
const REGIONS = ['v_muster_k', 'v_muster_w', 'v_muster_t', 'v_muster_a', 'v_muster_al', 'v_muster_bl'];
const BOXES = {
    kreislauf: [['c_kreislauf', 'c_kreislauf'], ['c_rekap', 'c_rekap'], ['c_ekg', 'c_ekg'], ['c_puls_reg', 'c_puls_reg'], ['c_puls_rad', 'c_puls_rad'], ['c_blutung', 'c_blutung']],
    neuro: [['d_pupillenw_1', 'd_pupillenweite'], ['d_pupillenw_2', 'd_pupillenweite'], ['d_lichtreakt_1', 'd_lichtreaktion'], ['d_lichtreakt_2', 'd_lichtreaktion']],
};
const shown = (code: string) => vitalText(code, form.values[code], props.bzUnit);
const tone = (code: string, text: string) => `enotf-tone--${grade(code, text, props.bzUnit) ?? 'none'}`;
const UNITS: Record<string, string> = { spo2: '%', atemfreq: '/min', etco2: 'mmHg', herzfreq: '/min', rrsys: 'mmHg', rrdias: 'mmHg', bz: props.bzUnit, temp: '°C' };

// ── Messwerte mask ──
const draft = reactive<Record<string, string>>({});
const active = ref('spo2');
const bad = ref<string[]>([]);
const busy = ref(false);
const focusDraft = useFocusDraft(
    () => (topic.value === 'messwerte' ? 'messwerte' : null),
    draft,
    () => {
        active.value = 'spo2';
        bad.value = [];
        return Object.fromEntries(NUMERIC.map((c) => [c, shown(c)]));
    },
);
const saveVitals = async () => {
    if (busy.value || form.readonly) return;
    const values = Object.fromEntries(NUMERIC.map((c) => [c, vitalValue(c, draft[c], props.bzUnit, form.vitalMax[c])]));
    bad.value = NUMERIC.filter((c) => values[c] === undefined);
    if (bad.value.length) return;
    busy.value = true;
    const start = focusDraft.start();
    NUMERIC.filter((c) => draft[c].trim().toLowerCase() !== start[c]).forEach((c) => form.set(c, values[c]));
    await form.flush();
    busy.value = false;
    wizard.open(null);
};
</script>

<template>
    <div v-if="topic === 'messwerte'" class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]">
        <div>
            <div class="grid grid-cols-3 gap-3">
                <template v-for="c in ['spo2', 'atemfreq', 'etco2', 'herzfreq', 'rrsys', 'bz', 'temp']" :key="c">
                    <label :class="['enotf-box', c === 'rrsys' ? 'col-span-2' : '', bad.includes(c) || (c === 'rrsys' && bad.includes('rrdias')) ? 'outline-2 outline-(--enotf-red)' : '']">
                        <span class="enotf-label flex justify-between">
                            <span>{{ t(`v.${c === 'rrsys' ? 'rr' : c}`) }}</span><span>{{ UNITS[c] }}</span>
                        </span>
                        <span class="flex items-center gap-2">
                            <input
                                v-model="draft[c]"
                                type="text"
                                inputmode="decimal"
                                autocomplete="off"
                                maxlength="7"
                                :aria-label="t(`v.${c}`)"
                                :aria-invalid="bad.includes(c)"
                                :data-mark="fieldMark(form, c)"
                                :class="['enotf-input text-2xl', active === c ? 'border-(--enotf-accent)!' : '', tone(c, draft[c]), VITALS_REQ.includes(c) && !draft[c] ? 'border-(--enotf-red)' : '']"
                                @focus="active = c"
                            />
                            <template v-if="c === 'rrsys'">
                                <span class="text-2xl">/</span>
                                <input
                                    v-model="draft.rrdias"
                                    type="text"
                                    inputmode="decimal"
                                    autocomplete="off"
                                    maxlength="7"
                                    :aria-label="t('v.rrdias')"
                                    :aria-invalid="bad.includes('rrdias')"
                                    :data-mark="fieldMark(form, 'rrdias')"
                                    :class="['enotf-input text-2xl', active === 'rrdias' ? 'border-(--enotf-accent)!' : '', tone('rrdias', draft.rrdias)]"
                                    @focus="active = 'rrdias'"
                                />
                            </template>
                        </span>
                    </label>
                </template>
            </div>
            <p v-if="bad.length" role="alert" class="mt-2 text-(--enotf-red)">{{ trans('enotf.vitals.invalid') }}</p>
            <FocusFooter :busy="busy" :save-label="t('save')" @back="wizard.open(null)" @save="saveVitals" />
        </div>
        <NumberPad :code="active" :value="draft[active]" :bz-unit="bzUnit" :disabled="form.readonly" @press="(k) => (draft[active] = press(draft[active] ?? '', k))" />
    </div>

    <div v-else class="flex gap-4">
        <div v-if="!form.readonly" class="enotf-col w-48 shrink-0">
            <TileLink v-for="(d, k) in TOPICS" :key="k" :t="String(k)" :requires="d.requires.length ? d.requires : undefined">{{ t(`topic.${k}`) }}</TileLink>
        </div>

        <div v-if="def && !form.readonly" class="flex min-w-0 flex-1 gap-4">
            <div v-if="def.quick || nav.length" class="enotf-col w-48 shrink-0">
                <QuickFill v-if="def.quick" :values="def.quick.values">{{ t(def.quick.label) }}</QuickFill>
                <TileLink v-for="n in nav" :key="n.q" :t="topic!" :q="n.q" :requires="n.requires">{{ t(`step.${n.label}`) }}</TileLink>
            </div>
            <template v-if="step">
                <div v-if="step.pupils" class="enotf-col w-48 shrink-0">
                    <TileLink t="neurologie" :q="3" :requires="['d_pupillenw_1', 'd_pupillenw_2']">{{ t('step.pupillenweite') }}</TileLink>
                    <TileLink t="neurologie" :q="4" :requires="['d_lichtreakt_1', 'd_lichtreakt_2']">{{ t('step.lichtreaktion') }}</TileLink>
                </div>
                <GcsPicker v-if="step.widget === 'gcs'" />
                <div v-else-if="step.widget === 'bodymap'" class="flex-1"><BodyMap /></div>
                <TileColumn
                    v-for="(c, i) in step.cols ?? []"
                    :key="`${c.field}-${i}`"
                    class="w-56 shrink-0"
                    :field="c.field"
                    :options="c.options"
                    :green="c.green"
                    :header="c.header ? t(c.header) : undefined"
                    :multiple="c.multiple"
                    :exclusive="c.exclusive"
                />
            </template>
        </div>

        <div v-else class="grid min-w-0 flex-1 grid-cols-6 content-start gap-4">
            <SummaryBox class="col-span-3" :title="t('topic.atemwege')" t="atemwege" :requires="TOPICS.atemwege.requires">
                <dl class="enotf-dl">
                    <div><dt>{{ t('step.awfrei') }}</dt><dd>{{ label('awfrei_1', 'awfrei') }}</dd></div>
                    <div><dt>{{ t('step.zyanose') }}</dt><dd>{{ label('zyanose_1', 'zyanose') }}</dd></div>
                </dl>
            </SummaryBox>
            <SummaryBox class="col-span-3" :title="t('topic.atmung')" t="atmung" :requires="TOPICS.atmung.requires">
                <dl class="enotf-dl">
                    <div><dt>{{ t('step.b_symptome') }}</dt><dd>{{ label('b_symptome', 'b_symptome') }}</dd></div>
                    <div><dt>{{ t('step.b_auskult') }}</dt><dd>{{ label('b_auskult', 'b_auskult') }}</dd></div>
                </dl>
            </SummaryBox>
            <SummaryBox class="col-span-3" :title="t('topic.kreislauf')" t="kreislauf" :requires="[...TOPICS.kreislauf.requires, 'c_ekg']">
                <dl class="enotf-dl enotf-dl--3">
                    <template v-for="[f, cat] in BOXES.kreislauf" :key="f">
                        <div><dt>{{ t(`sum.${f}`) }}</dt><dd>{{ label(f, cat) }}</dd></div>
                    </template>
                </dl>
            </SummaryBox>
            <SummaryBox class="col-span-3" :title="t('topic.neurologie')" t="neurologie" :requires="NEURO">
                <dl class="enotf-dl enotf-dl--3">
                    <div><dt>{{ t('step.d_bewusstsein') }}</dt><dd>{{ label('d_bewusstsein', 'd_bewusstsein') }}</dd></div>
                    <div><dt>{{ t('step.d_ex_1') }}</dt><dd>{{ label('d_ex_1', 'd_extremitaeten') }}</dd></div>
                    <div><dt>{{ t('gcs') }}</dt><dd>{{ gcs ?? '-' }}</dd></div>
                </dl>
                <dl class="enotf-dl enotf-dl--4 mt-2">
                    <template v-for="[f, cat] in BOXES.neuro" :key="f">
                        <div><dt>{{ t(`sum.${f}`) }}</dt><dd>{{ label(f, cat) }}</dd></div>
                    </template>
                </dl>
            </SummaryBox>
            <SummaryBox class="col-span-4" :title="t('step.verletzungen')" t="erweitern" :q="1">
                <dl class="enotf-dl enotf-dl--3">
                    <template v-for="f in REGIONS" :key="f">
                        <div><dt>{{ t(`region.${f}`) }}</dt><dd :class="`enotf-sevtext--${form.values[f] ?? ''}`">{{ injuryText(form.values[f], form.values[`${f}1`]) }}</dd></div>
                    </template>
                </dl>
            </SummaryBox>
            <SummaryBox class="col-span-2" :title="t('step.schmerzen')" t="erweitern" :q="2">
                <dl class="enotf-dl">
                    <div><dt>{{ t('sum.sz_nrs') }}</dt><dd>{{ label('sz_nrs', 'sz_nrs') }}</dd></div>
                    <div><dt>{{ t('sum.sz_toleranz_1') }}</dt><dd>{{ label('sz_toleranz_1', 'sz_toleranz') }}</dd></div>
                </dl>
            </SummaryBox>
            <SummaryBox class="col-span-2" :title="t('topic.psyche')" t="psychisch" :requires="['psych']">
                <dl class="enotf-dl">
                    <div><dt>{{ t('sum.psych') }}</dt><dd>{{ psychText }}</dd></div>
                </dl>
            </SummaryBox>
            <SummaryBox class="col-span-4" :title="t('vitals')" t="messwerte" :requires="VITALS_REQ" :marks="[...NUMERIC]">
                <dl class="enotf-dl enotf-dl--8">
                    <template v-for="c in NUMERIC" :key="c">
                        <div><dt>{{ t(`v.${c}`) }}</dt><dd :class="tone(c, shown(c))" :data-mark="fieldMark(form, c)">{{ shown(c) }}</dd></div>
                    </template>
                </dl>
            </SummaryBox>
        </div>
    </div>
</template>
