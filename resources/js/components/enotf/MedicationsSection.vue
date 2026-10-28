<script setup lang="ts">
/**
 * Medication mask (legacy massnahmen ?t=medimaske, chromeless): given rows on the left (tap = mark), the entry form on
 * the right (substance with search "Hersteller (Wirkstoff)", time, route, dose with the catalog doses as suggestions that
 * also set the unit, unit). Data path unchanged (9b rows API): "speichern" = POST one row (empty time = server time),
 * "Löschen" = DELETE the marked row; every answer is the current list (emitted to the page).
 */
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';
import FocusFooter from '@/components/enotf/ui/FocusFooter.vue';
import MaskedTime from '@/components/enotf/ui/MaskedTime.vue';
import SelectSheet from '@/components/enotf/ui/SelectSheet.vue';
import Suggest from '@/components/enotf/ui/Suggest.vue';
import { type Code, medTime, nowWall, parseDose, useEnotfForm } from '@/lib/enotfUi';
import { rowRequest } from '@/lib/enotfRows';

export type GivenRow = { id: number; medication_id: number | null; name: string; dose: string | null; unit: string | null; route: string | null; given_at: string };
export type CatalogEntry = { id: number; substance: string; brand: string | null; doses: string[] };

const props = defineProps<{ url: string; rows: GivenRow[]; catalog: CatalogEntry[]; routes: string[]; units: Record<string, string> }>();
const emit = defineEmits<{ rows: [rows: GivenRow[]]; back: [] }>();
const form = useEnotfForm();
const t = (k: string) => trans(`enotf.medications.${k}`);

const sorted = computed(() => [...props.rows].sort((a, b) => a.given_at.localeCompare(b.given_at) || a.id - b.id));
const marked = ref<number | null>(null);
const substances = computed<[Code, string][]>(() => props.catalog.map((c) => [c.id, c.brand ? `${c.brand} (${c.substance})` : c.substance]));
const empty = () => ({ medication_id: null as Code | null, time: null as string | null, route: null as Code | null, dose: '', unit: null as Code | null });
const entry = ref(empty());
const doses = computed(() => props.catalog.find((c) => c.id === entry.value.medication_id)?.doses ?? []);
const busy = ref(false);
const error = ref('');

const setDose = (v: string | null) => {
    const d = parseDose(v ?? '');
    entry.value.dose = d?.dose ?? v ?? '';
    if (d && d.unit in props.units) entry.value.unit = d.unit;
};

async function save() {
    const e = entry.value;
    if (busy.value) return;
    if (e.medication_id === null || e.route === null || e.unit === null || !e.dose.trim()) {
        error.value = t('fill_all');
        return;
    }
    busy.value = true;
    error.value = '';
    const res = await rowRequest<{ medications: GivenRow[] }>(props.url, 'POST', {
        medication_id: e.medication_id,
        dose: e.dose.trim(),
        unit: e.unit,
        route: e.route,
        given_at: medTime(e.time, nowWall(form.clock, form.timezone)),
    });
    busy.value = false;
    if (!res.ok) {
        error.value = res.message || t('failed');
        return;
    }
    emit('rows', res.data.medications);
    entry.value = empty();
}

async function remove() {
    if (busy.value) return;
    if (marked.value === null) {
        error.value = t('mark_first');
        return;
    }
    busy.value = true;
    const res = await rowRequest<{ medications: GivenRow[] }>(`${props.url}/${marked.value}`, 'DELETE');
    busy.value = false;
    if (!res.ok) {
        error.value = res.message || t('failed');
        return;
    }
    error.value = '';
    marked.value = null;
    emit('rows', res.data.medications);
}
</script>

<template>
    <div class="space-y-4">
        <div class="grid gap-6 lg:grid-cols-2">
            <div class="enotf-box min-h-[60vh]" role="listbox" :aria-label="t('title')">
                <button
                    v-for="r in sorted"
                    :key="r.id"
                    type="button"
                    role="option"
                    class="enotf-panel__item"
                    :aria-selected="marked === r.id"
                    @click="marked = marked === r.id ? null : r.id"
                >
                    <span class="text-(--enotf-label)">{{ r.given_at.slice(11) }}</span> {{ r.name }} {{ r.dose }} {{ r.unit }} {{ r.route }}
                </button>
                <p v-if="!sorted.length" class="p-4 text-center">{{ t('none') }}</p>
            </div>
            <div class="space-y-3">
                <SelectSheet v-model="entry.medication_id" :options="substances" :label="t('substance')" :placeholder="t('substance')" />
                <div class="grid grid-cols-2 gap-3">
                    <MaskedTime v-model="entry.time" :label="t('time')" :fill-now="() => nowWall(form.clock, form.timezone).slice(11, 16)" />
                    <SelectSheet v-model="entry.route" :options="routes.map((r) => [r, r] as [Code, string])" :label="t('route')" :placeholder="t('route')" />
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <Suggest :model-value="entry.dose" :items="doses" :label="t('dose')" :maxlength="20" @update:model-value="setDose" />
                    <SelectSheet v-model="entry.unit" :options="Object.entries(units) as [Code, string][]" :label="t('unit')" :placeholder="t('unit')" />
                </div>
                <p v-if="error" role="alert" class="text-(--enotf-red)">{{ error }}</p>
            </div>
        </div>
        <FocusFooter remove :busy="busy" @back="emit('back')" @remove="remove" @save="save" />
    </div>
</template>
