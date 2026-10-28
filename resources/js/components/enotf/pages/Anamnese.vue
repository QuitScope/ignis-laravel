<script setup lang="ts">
/**
 * Page "Anamnese" (legacy protokoll/anamnese.php): overview = topic column + boxes; ?t=anamnese = chromeless free text
 * with text blocks (written as typed, like the legacy autosave field, so a PIN lock loses nothing; OK flushes and goes
 * back); ?t=symptome = step 1 symptom onset (local until "Speichern"), step 2 NACA; ?t=az, ?t=einsatzort = tile columns.
 */
import { trans } from 'laravel-vue-i18n';
import { computed, nextTick, reactive, ref } from 'vue';
import MaskedDate from '@/components/enotf/ui/MaskedDate.vue';
import MaskedTime from '@/components/enotf/ui/MaskedTime.vue';
import SummaryBox from '@/components/enotf/ui/SummaryBox.vue';
import TextBlocks from '@/components/enotf/ui/TextBlocks.vue';
import TileColumn from '@/components/enotf/ui/TileColumn.vue';
import TileLink from '@/components/enotf/ui/TileLink.vue';
import { catalog, type Code, fieldMark, formatDate, insertAt, nowWall, useEnotfForm, useEnotfWizard, useFocusDraft } from '@/lib/enotfUi';

const form = useEnotfForm();
const wizard = useEnotfWizard();
const t = (k: string) => trans(`enotf.ui.an.${k}`);
const str = (k: string) => (typeof form.values[k] === 'string' ? (form.values[k] as string) : '');
const label = (field: string, name: string) => catalog(name).find(([c]) => String(c) === String(form.values[field] ?? ''))?.[1] ?? '';
const pick = (name: string, codes: Code[]) => catalog(name).filter(([c]) => codes.includes(c));

const TOPICS: Record<string, string[] | undefined> = { anamnese: undefined, symptome: ['naca_initial'], az: undefined, einsatzort: ['elokation'] };
const topic = computed(() => (wizard.topic.value && wizard.topic.value in TOPICS && !form.readonly ? wizard.topic.value : null));

const onset = computed(() => {
    const when = [formatDate(str('symptombeginn_datum') || null), str('symptombeginn_zeit')].filter(Boolean).join(' ');
    const opts = [form.values.symptombeginn_geschaetzt === true ? t('geschaetzt') : '', form.values.symptombeginn_nf === true ? t('nf') : ''].filter(Boolean).join(', ');
    return when && opts ? `${when} (${opts})` : when || opts;
});

// ── free text ──
const area = ref<HTMLTextAreaElement | null>(null);
const lines = computed(() => str('anmerkungen').split('\n').length);
const insert = (text: string, mode: number) => {
    const el = area.value;
    const r = insertAt(str('anmerkungen'), el?.selectionStart ?? str('anmerkungen').length, text, mode);
    form.set('anmerkungen', r.value);
    void nextTick(() => {
        el?.focus();
        el?.setSelectionRange(r.cursor, r.cursor);
    });
};
const busy = ref(false);
const ok = async () => {
    busy.value = true;
    await form.flush();
    busy.value = false;
    wizard.open(null);
};

// ── symptom onset: date prefilled today, time = server now on tap; written on "Speichern" ──
const draft = reactive({ date: null as string | null, time: null as string | null });
const now = () => nowWall(form.clock, form.timezone);
const focusDraft = useFocusDraft(
    () => (topic.value === 'symptome' && wizard.step.value === 1 ? 'symptome' : null),
    draft,
    () => ({ date: str('symptombeginn_datum') || null, time: str('symptombeginn_zeit') || null }),
    (s) => ({ date: s.date ?? now().slice(0, 10) }),
);
const saved = ref(false);
const saveOnset = async () => {
    if (focusDraft.changed('date')) form.set('symptombeginn_datum', draft.date);
    if (focusDraft.changed('time')) form.set('symptombeginn_zeit', draft.time);
    await form.flush();
    focusDraft.reset();
    saved.value = true;
    setTimeout(() => (saved.value = false), 2000);
};
const flag = (key: string, other: string) => {
    const on = form.values[key] !== true;
    form.set(key, on);
    if (on && form.values[other] === true) form.set(other, false);
};
</script>

<template>
    <div v-if="topic === 'anamnese'" class="space-y-3">
        <div class="flex gap-3">
            <textarea
                ref="area"
                :value="str('anmerkungen')"
                :data-mark="fieldMark(form, 'anmerkungen')"
                class="enotf-input min-h-[55vh] flex-1 resize-none"
                maxlength="10000"
                :aria-label="t('topic.anamnese')"
                @input="form.set('anmerkungen', ($event.target as HTMLTextAreaElement).value)"
            />
            <button type="button" class="enotf-footer__btn enotf-footer__btn--primary h-20 w-40 text-2xl" :disabled="busy" @click="ok">OK</button>
        </div>
        <p v-if="lines > 22" role="status" class="rounded bg-(--enotf-red) px-3 py-1 text-sm">{{ t('too_long') }}</p>
        <TextBlocks @insert="insert" />
    </div>

    <div v-else class="flex gap-4">
        <div v-if="!form.readonly" class="enotf-col w-48 shrink-0">
            <TileLink v-for="(req, k) in TOPICS" :key="k" :t="String(k)" :requires="req">{{ t(`topic.${k}`) }}</TileLink>
        </div>

        <div v-if="topic === 'symptome'" class="flex min-w-0 flex-1 gap-4">
            <div class="enotf-col w-48 shrink-0">
                <TileLink t="symptome" :q="1">{{ t('onset') }}</TileLink>
                <TileLink t="symptome" :q="2" :requires="['naca_initial']">{{ t('naca') }}</TileLink>
            </div>
            <template v-if="wizard.step.value === 1">
                <div class="enotf-col w-56 shrink-0">
                    <span class="enotf-col__head">{{ trans('enotf.ui.date') }}</span>
                    <MaskedDate v-model="draft.date" :data-mark="fieldMark(form, 'symptombeginn_datum')" class="min-h-[74px]" :label="trans('enotf.ui.date')" />
                    <button type="button" role="checkbox" class="enotf-tile" :aria-checked="form.values.symptombeginn_geschaetzt === true" @click="flag('symptombeginn_geschaetzt', 'symptombeginn_nf')">
                        <span>{{ t('geschaetzt') }}</span>
                    </button>
                </div>
                <div class="enotf-col w-56 shrink-0">
                    <span class="enotf-col__head">{{ trans('enotf.ui.time') }}</span>
                    <MaskedTime v-model="draft.time" :data-mark="fieldMark(form, 'symptombeginn_zeit')" class="min-h-[74px]" :label="trans('enotf.ui.time')" :fill-now="() => now().slice(11, 16)" />
                    <button type="button" role="checkbox" class="enotf-tile" :aria-checked="form.values.symptombeginn_nf === true" @click="flag('symptombeginn_nf', 'symptombeginn_geschaetzt')">
                        <span>{{ t('nf') }}</span>
                    </button>
                </div>
                <div class="enotf-col w-48 shrink-0 justify-center">
                    <button type="button" class="enotf-footer__btn enotf-footer__btn--primary" @click="saveOnset">{{ trans('enotf.ui.save') }}</button>
                    <p v-if="saved" role="status" class="px-2 py-1 text-sm text-(--enotf-ok)">{{ t('saved') }}</p>
                </div>
            </template>
            <template v-else-if="wizard.step.value === 2">
                <TileColumn class="w-72 shrink-0" field="naca_initial" :options="catalog('naca')" :header="t('initial')" />
                <TileColumn class="w-72 shrink-0" field="naca_uebergabe" :options="catalog('naca')" :header="t('uebergabe')" />
            </template>
        </div>
        <div v-else-if="topic === 'az'" class="min-w-0 flex-1">
            <TileColumn class="max-w-2xl" field="az_vor_ereignis" :options="catalog('az_vor_ereignis')" />
        </div>
        <div v-else-if="topic === 'einsatzort'" class="flex min-w-0 flex-1 gap-4">
            <TileColumn class="w-56 shrink-0" field="elokation" :options="pick('elokation', [1, 2, 3, 4, 5, 6, 7, 8, 9])" />
            <TileColumn class="w-56 shrink-0" field="elokation" :options="pick('elokation', [10, 11, 98, 99])" />
        </div>

        <div v-else class="grid min-w-0 flex-1 grid-cols-2 content-start gap-4">
            <SummaryBox class="col-span-2" :title="t('topic.anamnese')" t="anamnese" :marks="['anmerkungen']">
                <p class="enotf-input h-[50vh] overflow-y-auto whitespace-pre-wrap">{{ str('anmerkungen') }}</p>
            </SummaryBox>
            <SummaryBox :title="t('topic.symptome')" t="symptome" :q="1" :marks="['symptombeginn_datum', 'symptombeginn_zeit', 'symptombeginn_geschaetzt', 'symptombeginn_nf']">
                <span class="enotf-label block">{{ t('onset') }}</span>
                <span class="enotf-input">{{ onset || ' ' }}</span>
            </SummaryBox>
            <SummaryBox :title="t('naca')" t="symptome" :q="2" :requires="['naca_initial']">
                <dl class="enotf-dl">
                    <div><dt>{{ t('initial') }}</dt><dd>{{ label('naca_initial', 'naca') }}</dd></div>
                    <div><dt>{{ t('uebergabe') }}</dt><dd>{{ label('naca_uebergabe', 'naca') }}</dd></div>
                </dl>
            </SummaryBox>
            <SummaryBox class="col-span-2" :title="t('topic.az')" t="az">
                <span class="enotf-input">{{ label('az_vor_ereignis', 'az_vor_ereignis') || ' ' }}</span>
            </SummaryBox>
            <SummaryBox :title="t('topic.einsatzort')" t="einsatzort" :requires="['elokation']">
                <span class="enotf-input">{{ label('elokation', 'elokation') || ' ' }}</span>
            </SummaryBox>
        </div>
    </div>
</template>
