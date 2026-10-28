<script setup lang="ts">
/** GCS step (legacy neurologie GCS): three answer columns (stored as the distance to the maximum) and the sum. */
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';
import TileColumn from '@/components/enotf/ui/TileColumn.vue';
import { catalog, gcsSum, useEnotfForm } from '@/lib/enotfUi';

const form = useEnotfForm();
const sum = computed(() => gcsSum(form.values.d_gcs_1, form.values.d_gcs_2, form.values.d_gcs_3));
</script>

<template>
    <TileColumn field="d_gcs_1" :options="catalog('d_gcs_augen')" :header="trans('enotf.ui.eb.gcs_eyes')" />
    <TileColumn field="d_gcs_2" :options="catalog('d_gcs_verbal')" :header="trans('enotf.ui.eb.gcs_verbal')" />
    <TileColumn field="d_gcs_3" :options="catalog('d_gcs_motorik')" :header="trans('enotf.ui.eb.gcs_motor')" />
    <div class="enotf-col">
        <div class="enotf-col__head">{{ trans('enotf.ui.eb.gcs') }}</div>
        <output class="enotf-tile justify-center text-3xl" role="status" :aria-label="`${trans('enotf.ui.eb.gcs')}: ${sum ?? '-'}`">{{ sum ?? '-' }}</output>
    </div>
</template>
