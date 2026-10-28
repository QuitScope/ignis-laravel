<script setup lang="ts">
/**
 * Page "Verlauf" (legacy protokoll/verlauf.php): overview = "Verlauf bearbeiten" / "Werte hinzufügen", statistics,
 * chart (tap = add), table in time order; ?t=list = grouped table newest first with delete; ?t=add = chromeless keypad
 * mask (VitalsSection). Rows come from the 9b vitals API; read only = overview only (legacy add.php redirect).
 */
import { ChartLine, List, Plus } from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';
import Chart from '@/components/enotf/vitals/Chart.vue';
import History from '@/components/enotf/vitals/History.vue';
import VitalsSection from '@/components/enotf/vitals/VitalsSection.vue';
import { rowRequest } from '@/lib/enotfRows';
import { useEnotfForm, useEnotfWizard } from '@/lib/enotfUi';
import type { BzUnit, VitalRow } from '@/lib/enotfVitals';

const props = defineProps<{ url: string; rows: VitalRow[]; bzUnit: BzUnit }>();
const emit = defineEmits<{ rows: [rows: VitalRow[]] }>();
const form = useEnotfForm();
const wizard = useEnotfWizard();
const t = (k: string, r?: Record<string, string>) => trans(`enotf.ui.vl.${k}`, r);

const topic = computed(() => (wizard.topic.value === 'list' || (wizard.topic.value === 'add' && !form.readonly) ? wizard.topic.value : null));
const times = computed(() => new Set(props.rows.map((r) => r.measured_at)).size);
const ascending = computed(() => [...props.rows].reverse());
const error = ref('');

async function remove(row: VitalRow) {
    const res = await rowRequest<{ vitals: VitalRow[] }>(`${props.url}/${row.id}`, 'DELETE');
    if (res.ok) emit('rows', res.data.vitals);
    else error.value = res.message || trans('enotf.vitals.failed');
}
</script>

<template>
    <VitalsSection v-if="topic === 'add'" :url="url" :bz-unit="bzUnit" @rows="(r) => emit('rows', r)" @back="wizard.open(null)" />

    <div v-else class="space-y-4">
        <div class="flex flex-wrap gap-2">
            <button v-if="topic === 'list'" type="button" class="enotf-tile w-auto" @click="wizard.open(null)">{{ trans('enotf.ui.back') }}</button>
            <button v-else type="button" class="enotf-tile w-auto" @click="wizard.open('list')"><List class="size-5" aria-hidden="true" />{{ t('edit') }}</button>
            <button v-if="!form.readonly" type="button" class="enotf-tile w-auto" @click="wizard.open('add')"><Plus class="size-5" aria-hidden="true" />{{ t('add') }}</button>
        </div>

        <section class="enotf-box">
            <h2 class="enotf-box__title"><ChartLine class="size-5" aria-hidden="true" />{{ t('stats') }}</h2>
            <p class="flex flex-wrap gap-3">
                <span class="rounded-full bg-[rgba(0,123,255,0.2)] px-4 py-1">{{ t('values', { count: String(rows.length) }) }}</span>
                <span class="rounded-full bg-[rgba(0,123,255,0.2)] px-4 py-1">{{ t('times', { count: String(times) }) }}</span>
            </p>
        </section>

        <p v-if="error" role="alert" class="text-(--enotf-red)">{{ error }}</p>

        <section v-if="topic === 'list'" class="enotf-box">
            <History :rows="rows" :deletable="!form.readonly" @remove="remove" />
        </section>
        <template v-else>
            <component :is="form.readonly ? 'section' : 'button'" :type="form.readonly ? undefined : 'button'" class="enotf-box" @click="!form.readonly && wizard.open('add')">
                <span class="enotf-box__title">{{ t('chart') }}</span>
                <Chart v-if="rows.length" :rows="rows" />
                <span v-if="!form.readonly" class="enotf-label">{{ t('chart_hint') }}</span>
            </component>
            <section class="enotf-box">
                <h2 class="enotf-box__title">{{ t('detail') }}</h2>
                <History :rows="ascending" />
            </section>
        </template>
    </div>
</template>
