<script setup lang="ts">
/**
 * Page "Maßnahmen" (legacy protokoll/massnahmen.php): overview = topic column + boxes; topics Atemwege, Atmung, Zugang
 * (art -> site list -> size column per side, legacy flow), Medikamente (link to the chromeless mask ?t=medimaske +
 * "Keine Medikamente" = medis_keine), Weitere. Given medication stays rows (9b API) via MedicationsSection.
 */
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';
import MedicationsSection, { type CatalogEntry, type GivenRow } from '@/components/enotf/MedicationsSection.vue';
import SummaryBox from '@/components/enotf/ui/SummaryBox.vue';
import TileColumn from '@/components/enotf/ui/TileColumn.vue';
import TileLink from '@/components/enotf/ui/TileLink.vue';
import TileToggle from '@/components/enotf/ui/TileToggle.vue';
import { catalog, type Code, type Zugang, zugangText, zugangToggle, useEnotfForm, useEnotfWizard } from '@/lib/enotfUi';

defineProps<{ url: string; rows: GivenRow[]; medCatalog: CatalogEntry[]; routes: string[]; units: Record<string, string> }>();
const emit = defineEmits<{ rows: [rows: GivenRow[]] }>();
const form = useEnotfForm();
const wizard = useEnotfWizard();
const t = (k: string) => trans(`enotf.ui.ms.${k}`);
const label = (field: string, name: string) => catalog(name).find(([c]) => String(c) === String(form.values[field] ?? ''))?.[1] ?? '';
const range = (name: string, from: number, to: number) => catalog(name).filter(([c]) => Number(c) >= from && Number(c) <= to);

const TOPICS: Record<string, string[] | undefined> = { atemwege: ['awsicherung_neu'], atmung: ['b_beatmung'], zugang: ['c_zugang'], medikamente: ['medis'], weitere: undefined };
const topic = computed(() => (wizard.topic.value && (wizard.topic.value in TOPICS || wizard.topic.value === 'medimaske') && !form.readonly ? wizard.topic.value : null));
const q = computed(() => wizard.step.value);

// ── Zugang: legacy sheet order (zugang/1_1, 1_2), stored "Fuß", nav label "Fuss"; sides from ZugangCatalog ──
const LR = ['links', 'rechts'];
const ARTS = [
    {
        art: 'pvk',
        sizes: ['24G', '22G', '20G', '18G', '18G_kurz', '17G', '16G', '14G'],
        sites: [['Handrücken', LR], ['Unterarm', LR], ['Ellbeuge', LR], ['Oberarm', LR], ['Hals', LR], ['Kopf', ['']], ['Bein', LR], ['Fuß', LR, 'Fuss'], ['Sonstige', ['']]] as [string, string[], string?][],
    },
    {
        art: 'io',
        sizes: ['15mm', '25mm', '45mm'],
        sites: [['Tibia proximal', LR], ['Tibia distal', LR], ['Humerus proximal', LR], ['Sternum', ['']], ['anderer Ort', ['']]] as [string, string[], string?][],
    },
];
const ZSTEPS = ARTS.flatMap((a, ai) => [{ ai, site: null as number | null }, ...a.sites.map((_, si) => ({ ai, site: si as number | null }))]);
const firstOf = (ai: number) => ZSTEPS.findIndex((s) => s.ai === ai) + 1;
const stepsOf = (ai: number) => ZSTEPS.flatMap((s, i) => (s.ai === ai ? [i + 1] : []));
const zstep = computed(() => (topic.value === 'zugang' && q.value ? (ZSTEPS[q.value - 1] ?? null) : null));
const zlist = computed(() => (Array.isArray(form.values.c_zugang) ? (form.values.c_zugang as Zugang[]) : []));
const sizeOn = (art: string, ort: string, seite: string, groesse: string) => zlist.value.some((z) => z.art === art && z.ort === ort && (z.seite ?? '') === seite && z.groesse === groesse);
const tapSize = (art: string, ort: string, seite: string, groesse: string) => form.set('c_zugang', zugangToggle(form.values.c_zugang, { art, groesse, ort, seite: seite || null }));
const keinZugang = computed(() => Array.isArray(form.values.c_zugang) && form.values.c_zugang.length === 0);
const gauge = (g: string) => (g.endsWith('G') || g.endsWith('_kurz') ? `var(--gauge-${g.replace('_kurz', '').toLowerCase()})` : 'transparent');
const sizeLabel = (g: string) => catalog('zugang_groesse').find(([c]) => c === g)?.[1] ?? g;

// ── overview ──
const accessText = (art: string) => zugangText(form.values.c_zugang, art, t('kein_zugang'), t('keine_art'));
const medsText = computed(() => {
    const rows = (form.values.medis as GivenRow[] | undefined) ?? [];
    if (!rows.length) return form.values.medis_keine === true ? t('medis_keine') : '';
    return [...rows]
        .sort((a, b) => a.given_at.localeCompare(b.given_at))
        .map((r) => `${r.given_at.slice(11)}: ${r.name} ${r.dose ?? ''} ${r.unit ?? ''} ${r.route ?? ''}`.trim())
        .join('\n');
});
const rescueText = computed(() =>
    (Array.isArray(form.values.rettungstechnik) ? (form.values.rettungstechnik as Code[]) : []).map((c) => catalog('rettungstechnik').find(([k]) => k === c)?.[1]).filter(Boolean).join(', '),
);
const SPECIAL = ['waerme_aktiv', 'e_krintervention', 'e_kuehlung', 'e_narkose', 'e_tourniquet', 'e_cpr'];
</script>

<template>
    <MedicationsSection v-if="topic === 'medimaske'" :url="url" :rows="rows" :catalog="medCatalog" :routes="routes" :units="units" @rows="(r) => emit('rows', r)" @back="wizard.open('medikamente')" />

    <div v-else class="flex gap-4">
        <div v-if="!form.readonly" class="enotf-col w-48 shrink-0">
            <TileLink v-for="(req, k) in TOPICS" :key="k" :t="String(k)" :requires="req">{{ t(`topic.${k}`) }}</TileLink>
        </div>

        <div v-if="topic === 'atemwege'" class="flex min-w-0 flex-1 gap-4">
            <div class="enotf-col w-56 shrink-0">
                <TileLink t="atemwege" :q="1" :requires="['awsicherung_neu']">{{ t('awsicherung') }}</TileLink>
                <TileToggle v-for="f in ['awsicherung_1', 'awsicherung_2', 'entlastungspunktion', 'hws_immo']" :key="f" :field="f">{{ t(f) }}</TileToggle>
            </div>
            <TileColumn v-if="q === 1" class="w-56 shrink-0" field="awsicherung_neu" :options="catalog('awsicherung_neu')" :green="1" />
        </div>

        <div v-else-if="topic === 'atmung'" class="flex min-w-0 flex-1 gap-4">
            <div class="enotf-col w-48 shrink-0">
                <TileLink t="atmung" :q="1" :requires="['b_beatmung']">{{ t('beatmung') }}</TileLink>
                <TileLink t="atmung" :q="2">{{ t('o2gabe') }}</TileLink>
            </div>
            <TileColumn v-if="q === 1" class="w-56 shrink-0" field="b_beatmung" :options="catalog('b_beatmung')" />
            <template v-else-if="q === 2">
                <TileColumn class="w-32 shrink-0" field="o2gabe" :options="range('o2gabe', 0, 8)" />
                <TileColumn class="w-32 shrink-0" field="o2gabe" :options="range('o2gabe', 9, 15)" />
            </template>
        </div>

        <div v-else-if="topic === 'zugang'" class="flex min-w-0 flex-1 gap-4">
            <div class="enotf-col w-48 shrink-0">
                <TileLink t="zugang">{{ t('zugang') }}</TileLink>
                <button type="button" role="checkbox" class="enotf-tile" :aria-checked="keinZugang" @click="form.set('c_zugang', keinZugang ? null : [])">
                    <span>{{ t('kein_zugang') }}</span>
                </button>
            </div>
            <div class="enotf-col w-48 shrink-0">
                <TileLink v-for="(a, ai) in ARTS" :key="a.art" t="zugang" :q="firstOf(ai)" :covers="stepsOf(ai)">{{ t(a.art) }}</TileLink>
            </div>
            <template v-if="zstep">
                <div class="enotf-col w-48 shrink-0">
                    <TileLink v-for="(s, si) in ARTS[zstep.ai].sites" :key="s[0]" t="zugang" :q="firstOf(zstep.ai) + si + 1">{{ s[2] ?? s[0] }}</TileLink>
                </div>
                <template v-if="zstep.site !== null">
                    <div
                        v-for="seite in ARTS[zstep.ai].sites[zstep.site][1]"
                        :key="seite"
                        class="enotf-col w-36 shrink-0"
                        role="group"
                        :aria-label="`${ARTS[zstep.ai].sites[zstep.site][0]} ${seite}`.trim()"
                    >
                        <span v-if="seite" class="enotf-col__head">{{ seite }}</span>
                        <button
                            v-for="g in ARTS[zstep.ai].sizes"
                            :key="g"
                            type="button"
                            role="checkbox"
                            class="enotf-tile"
                            :aria-checked="sizeOn(ARTS[zstep.ai].art, ARTS[zstep.ai].sites[zstep.site][0], seite, g)"
                            @click="tapSize(ARTS[zstep.ai].art, ARTS[zstep.ai].sites[zstep.site][0], seite, g)"
                        >
                            <span>{{ sizeLabel(g) }}</span>
                            <span class="size-3 shrink-0 rounded-full" :style="{ background: gauge(g) }" aria-hidden="true" />
                        </button>
                    </div>
                </template>
            </template>
        </div>

        <div v-else-if="topic === 'medikamente'" class="flex min-w-0 flex-1 gap-4">
            <div class="enotf-col w-56 shrink-0">
                <TileLink t="medimaske">{{ t('medis_pick') }}</TileLink>
                <TileToggle field="medis_keine">{{ t('medis_keine') }}</TileToggle>
            </div>
        </div>

        <div v-else-if="topic === 'weitere'" class="flex min-w-0 flex-1 gap-4">
            <div class="enotf-col w-56 shrink-0">
                <TileLink t="weitere" :q="1">{{ t('lagerung') }}</TileLink>
                <TileLink t="weitere" :q="2">{{ t('rettungstechnik') }}</TileLink>
                <TileLink t="weitere" :q="3">{{ t('spezial') }}</TileLink>
                <TileToggle v-for="f in ['waerme_passiv', 'e_reposition', 'e_verband']" :key="f" :field="f">{{ t(f) }}</TileToggle>
            </div>
            <TileColumn v-if="q === 1" class="w-56 shrink-0" field="lagerung" :options="catalog('lagerung')" />
            <TileColumn v-else-if="q === 2" class="w-56 shrink-0" field="rettungstechnik" :options="catalog('rettungstechnik')" multiple />
            <div v-else-if="q === 3" class="enotf-col w-56 shrink-0">
                <TileToggle v-for="f in SPECIAL" :key="f" :field="f">{{ t(f) }}</TileToggle>
            </div>
        </div>

        <div v-else class="grid min-w-0 flex-1 grid-cols-2 content-start gap-4">
            <SummaryBox :title="t('topic.atemwege')" t="atemwege" :requires="['awsicherung_neu']">
                <dl class="enotf-dl">
                    <div><dt>{{ t('awsicherung') }}</dt><dd>{{ label('awsicherung_neu', 'awsicherung_neu') }}</dd></div>
                </dl>
            </SummaryBox>
            <SummaryBox :title="t('topic.atmung')" t="atmung" :requires="['b_beatmung']">
                <dl class="enotf-dl">
                    <div><dt>{{ t('beatmung') }}</dt><dd>{{ label('b_beatmung', 'b_beatmung') }}</dd></div>
                    <div><dt>{{ t('o2gabe') }}</dt><dd>{{ label('o2gabe', 'o2gabe') }}</dd></div>
                </dl>
            </SummaryBox>
            <SummaryBox class="col-span-2" :title="t('zugaenge')" t="zugang" :requires="['c_zugang']">
                <div class="grid grid-cols-2 gap-3">
                    <div v-for="a in ['pvk', 'io']" :key="a">
                        <span class="enotf-label block">{{ t(a) }}</span>
                        <p class="enotf-input min-h-32 whitespace-pre-wrap">{{ accessText(a) }}</p>
                    </div>
                </div>
            </SummaryBox>
            <SummaryBox class="col-span-2" :title="t('topic.medikamente')" t="medikamente" :requires="['medis']">
                <p class="enotf-input min-h-[20vh] whitespace-pre-wrap">{{ medsText }}</p>
            </SummaryBox>
            <SummaryBox class="col-span-2" :title="t('topic.weitere')" t="weitere">
                <dl class="enotf-dl">
                    <div><dt>{{ t('lagerung') }}</dt><dd>{{ label('lagerung', 'lagerung') }}</dd></div>
                    <div><dt>{{ t('rettungstechnik') }}</dt><dd>{{ rescueText }}</dd></div>
                </dl>
            </SummaryBox>
        </div>
    </div>
</template>
