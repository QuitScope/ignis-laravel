<script setup lang="ts">
/**
 * Resuscitation as a column tree (legacy _partials/reanimation.php): status -> reason column ("nicht durchgeführt,
 * weil …") or details column (links with required dots, switch "erfolglos") -> options of the detail (radio, wrapped
 * from 9 options) or a time tile (tap empty = server time). Opening columns writes nothing; only tiles write via set.
 */
import { ChevronRight } from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';
import { computed, ref, watch } from 'vue';
import MaskedTime from '@/components/enotf/ui/MaskedTime.vue';
import TileColumn from '@/components/enotf/ui/TileColumn.vue';
import TileToggle from '@/components/enotf/ui/TileToggle.vue';
import { catalog, nowWall, reaPanel, useEnotfForm } from '@/lib/enotfUi';

const form = useEnotfForm();
const t = (k: string) => trans(`enotf.ui.ab.rea.${k}`);

const DETAILS: { field: string; type: 'radio' | 'time'; required: boolean; catalog?: string }[] = [
    { field: 'rea_ursache', type: 'radio', required: true, catalog: 'rea_ursache' },
    { field: 'rea_sport', type: 'radio', required: false, catalog: 'rea_ja_nein' },
    { field: 'rea_fr_eintreffen', type: 'time', required: false },
    { field: 'rea_kollaps', type: 'radio', required: true, catalog: 'rea_kollaps' },
    { field: 'rea_hdm_durch', type: 'radio', required: true, catalog: 'rea_hdm_durch' },
    { field: 'rea_hdm_zeit', type: 'time', required: true },
    { field: 'rea_defi', type: 'radio', required: false, catalog: 'rea_defi' },
    { field: 'rea_rosc', type: 'radio', required: true, catalog: 'rea_rosc' },
    { field: 'rea_kh_aufnahme', type: 'radio', required: true, catalog: 'rea_kh_aufnahme' },
    { field: 'rea_tod_zeit', type: 'time', required: false },
];
const REQUIRED = DETAILS.filter((d) => d.required).map((d) => d.field);
const status = computed(() => catalog('rea_status'));
const panel = ref(reaPanel(form.values.rea_status));
const detail = ref<string | null>(null);
watch(
    () => form.values.rea_status,
    (s) => {
        const p = reaPanel(s);
        if (p || Number(s) === 1) panel.value = p;
        detail.value = null;
    },
);
const open = (p: 'details' | 'nicht') => {
    panel.value = p;
    detail.value = null;
};
const dot = (fields: string[]) => {
    const n = fields.filter((f) => form.values[f] !== null && form.values[f] !== undefined && form.values[f] !== '').length;
    return n === fields.length ? 'green' : n > 0 ? 'yellow' : 'red';
};
const def = computed(() => DETAILS.find((d) => d.field === detail.value) ?? null);
const chunks = (name: string) => {
    const o = catalog(name);
    return Array.from({ length: Math.ceil(o.length / 9) }, (_, i) => o.slice(i * 9, i * 9 + 9));
};
</script>

<template>
    <div class="flex min-w-0 flex-1 gap-4">
        <div class="enotf-col w-56 shrink-0">
            <TileColumn field="rea_status" :options="status.slice(0, 2)" />
            <button type="button" class="enotf-tile" :aria-current="panel === 'nicht' ? 'step' : undefined" @click="open('nicht')">
                <span>{{ t('nicht') }}</span><ChevronRight class="size-5 shrink-0" aria-hidden="true" />
            </button>
            <button
                v-if="Number(form.values.rea_status) === 2"
                type="button"
                class="enotf-tile enotf-stripe"
                :data-fill="dot(REQUIRED)"
                :aria-current="panel === 'details' ? 'step' : undefined"
                @click="open('details')"
            >
                <span>{{ t('details') }}</span>
                <span v-if="dot(REQUIRED) !== 'green'" class="sr-only">{{ trans('enotf.ui.required_open') }}</span>
                <ChevronRight class="size-5 shrink-0" aria-hidden="true" />
            </button>
        </div>

        <TileColumn v-if="panel === 'nicht'" class="w-72 shrink-0" field="rea_status" :options="status.slice(2)" />

        <template v-else-if="panel === 'details'">
            <div class="enotf-col w-56 shrink-0">
                <button
                    v-for="d in DETAILS"
                    :key="d.field"
                    type="button"
                    class="enotf-tile enotf-stripe"
                    :data-fill="d.required ? dot([d.field]) : undefined"
                    :aria-current="detail === d.field ? 'step' : undefined"
                    @click="detail = d.field"
                >
                    <span>{{ t(d.field) }}</span>
                    <span v-if="d.required && dot([d.field]) !== 'green'" class="sr-only">{{ trans('enotf.ui.required_open') }}</span>
                    <ChevronRight class="size-5 shrink-0" aria-hidden="true" />
                </button>
                <TileToggle field="rea_erfolglos" :on="1" :off="null">{{ t('rea_erfolglos') }}</TileToggle>
            </div>
            <template v-if="def">
                <template v-if="def.type === 'radio'">
                    <TileColumn v-for="(c, i) in chunks(def.catalog ?? '')" :key="`${def.field}-${i}`" class="w-60 shrink-0" :field="def.field" :options="c" />
                </template>
                <label v-else class="enotf-col w-56 shrink-0">
                    <span class="enotf-col__head">{{ t(def.field === 'rea_tod_zeit' ? 'tod' : 'uhrzeit') }}</span>
                    <MaskedTime
                        :model-value="(form.values[def.field] as string | null) ?? null"
                        :label="t(def.field)"
                        :disabled="form.readonly"
                        :fill-now="() => nowWall(form.clock, form.timezone).slice(11, 16)"
                        class="min-h-[74px] text-2xl"
                        @update:model-value="(v) => form.set(def!.field, v)"
                    />
                </label>
            </template>
        </template>
    </div>
</template>
