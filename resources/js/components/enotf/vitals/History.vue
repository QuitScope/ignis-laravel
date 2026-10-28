<script setup lang="ts">
/**
 * Vital signs table grouped by point in time (legacy list.php), in the order given. Values are text (no raw HTML).
 * Delete takes two taps (no native confirm dialog): the first arms the button, the second emits.
 */
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';
import { type VitalRow, vitalGroups } from '@/lib/enotfVitals';

const props = defineProps<{ rows: VitalRow[]; deletable?: boolean }>();
const emit = defineEmits<{ remove: [row: VitalRow] }>();
const groups = computed(() => vitalGroups(props.rows));
const armed = ref<number | null>(null);
const tap = (r: VitalRow) => {
    if (armed.value !== r.id) {
        armed.value = r.id;
        return;
    }
    armed.value = null;
    emit('remove', r);
};
</script>

<template>
    <table class="w-full text-sm">
        <thead>
            <tr class="border-b border-[rgba(128,128,128,0.4)] text-left">
                <th class="py-1 pe-2">{{ trans('enotf.vitals.parameter') }}</th>
                <th class="py-1 pe-2">{{ trans('enotf.vitals.value') }}</th>
                <th v-if="deletable" class="py-1"><span class="sr-only">{{ trans('enotf.vitals.delete') }}</span></th>
            </tr>
        </thead>
        <tbody v-for="g in groups" :key="g.at">
            <tr class="border-t-2 border-[rgba(128,128,128,0.4)] bg-[rgba(128,128,128,0.12)]">
                <td :colspan="deletable ? 3 : 2" class="px-2 py-2 font-semibold">
                    {{ trans('enotf.ui.vl.group', { time: g.at.slice(11), date: `${g.at.slice(8, 10)}.${g.at.slice(5, 7)}.`, count: String(g.rows.length) }) }}
                </td>
            </tr>
            <tr v-for="r in g.rows" :key="r.id" class="border-b border-[rgba(128,128,128,0.25)]">
                <td class="px-2 py-1">{{ trans(`enotf.vitals.p.${r.parameter}`) }}</td>
                <td class="py-1 pe-2 break-words">{{ r.display }} {{ r.value !== null ? r.unit : '' }}</td>
                <td v-if="deletable" class="py-1 text-right">
                    <button type="button" class="min-h-11 rounded px-3 text-white" :class="armed === r.id ? 'bg-(--enotf-red)' : 'bg-[rgba(220,53,69,0.6)]'" @click="tap(r)">
                        {{ trans(armed === r.id ? 'enotf.ui.vl.delete_sure' : 'enotf.vitals.delete') }}
                    </button>
                </td>
            </tr>
        </tbody>
        <tbody v-if="!rows.length">
            <tr>
                <td :colspan="deletable ? 3 : 2" class="py-4 text-center opacity-70">{{ trans('enotf.vitals.none') }}</td>
            </tr>
        </tbody>
    </table>
</template>
