<script setup lang="ts">
/**
 * Verlauf "Werte hinzufügen" (legacy verlauf/add.php, chromeless): value boxes like the first-finding mask, NumberPad
 * with the range strip of the active field, remark and an optional time (empty = server time; a date needs a time).
 * Values stay local until "Speichern" = one POST to the 9b vitals rows API; "Abbrechen" discards. The draft survives a
 * PIN lock (useFocusDraft).
 */
import { trans } from 'laravel-vue-i18n';
import { reactive, ref } from 'vue';
import FocusFooter from '@/components/enotf/ui/FocusFooter.vue';
import MaskedDate from '@/components/enotf/ui/MaskedDate.vue';
import MaskedTime from '@/components/enotf/ui/MaskedTime.vue';
import NumberPad from '@/components/enotf/ui/NumberPad.vue';
import { rowRequest } from '@/lib/enotfRows';
import { medTime, nowWall, useEnotfForm, useFocusDraft } from '@/lib/enotfUi';
import { type BzUnit, grade, NUMERIC, press, type VitalRow } from '@/lib/enotfVitals';

const props = defineProps<{ url: string; bzUnit: BzUnit }>();
const emit = defineEmits<{ rows: [rows: VitalRow[]]; back: [] }>();
const form = useEnotfForm();
const t = (k: string) => trans(`enotf.ui.eb.${k}`);

const FIELDS = [...NUMERIC, 'bemerkung'];
const blank = (): Record<string, string | null> => ({ ...Object.fromEntries(FIELDS.map((c) => [c, ''])), date: null, time: null });
const entry = reactive(blank());
useFocusDraft(() => 'add', entry, blank);
const active = ref<string>('spo2');
const timeNeeded = ref(false);
const busy = ref(false);
const error = ref('');
const UNITS: Record<string, string> = { spo2: '%', atemfreq: '/min', etco2: 'mmHg', herzfreq: '/min', rrsys: 'mmHg', rrdias: 'mmHg', bz: props.bzUnit, temp: '°C' };
const tone = (code: string) => `enotf-tone--${grade(code, entry[code] ?? '', props.bzUnit) ?? 'none'}`;
const now = () => nowWall(form.clock, form.timezone);

async function save() {
    if (busy.value) return;
    const values = Object.fromEntries(FIELDS.map((c) => [c, entry[c] ?? '']).filter(([, v]) => v.trim() !== ''));
    if (!Object.keys(values).length) {
        error.value = trans('enotf.vitals.empty');
        return;
    }
    timeNeeded.value = !!entry.date && !entry.time;
    if (timeNeeded.value) {
        error.value = trans('enotf.vitals.time_needed');
        return;
    }
    busy.value = true;
    error.value = '';
    const at = entry.time ? (entry.date ? `${entry.date} ${entry.time}` : medTime(entry.time, now())) : null;
    const res = await rowRequest<{ vitals: VitalRow[] }>(props.url, 'POST', { values, measured_at: at });
    busy.value = false;
    if (!res.ok) {
        error.value = res.message || trans('enotf.vitals.failed');
        return;
    }
    emit('rows', res.data.vitals);
    emit('back');
}
</script>

<template>
    <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]">
        <div>
            <div class="grid grid-cols-3 gap-3">
                <template v-for="c in ['spo2', 'atemfreq', 'etco2', 'herzfreq', 'rrsys', 'bz', 'temp']" :key="c">
                    <label :class="['enotf-box', c === 'rrsys' ? 'col-span-2' : '']">
                        <span class="enotf-label flex justify-between">
                            <span>{{ t(`v.${c === 'rrsys' ? 'rr' : c}`) }}</span><span>{{ UNITS[c] }}</span>
                        </span>
                        <span class="flex items-center gap-2">
                            <input
                                v-model="entry[c]"
                                type="text"
                                inputmode="decimal"
                                autocomplete="off"
                                maxlength="7"
                                :aria-label="t(`v.${c}`)"
                                :class="['enotf-input text-2xl', active === c ? 'border-(--enotf-accent)!' : '', tone(c)]"
                                @focus="active = c"
                            />
                            <template v-if="c === 'rrsys'">
                                <span class="text-2xl">/</span>
                                <input
                                    v-model="entry.rrdias"
                                    type="text"
                                    inputmode="decimal"
                                    autocomplete="off"
                                    maxlength="7"
                                    :aria-label="t('v.rrdias')"
                                    :class="['enotf-input text-2xl', active === 'rrdias' ? 'border-(--enotf-accent)!' : '', tone('rrdias')]"
                                    @focus="active = 'rrdias'"
                                />
                            </template>
                        </span>
                    </label>
                </template>
                <label class="enotf-box col-span-3">
                    <span class="enotf-label">{{ trans('enotf.vitals.p.bemerkung') }}</span>
                    <input v-model="entry.bemerkung" type="text" maxlength="50" autocomplete="off" class="enotf-input" @focus="active = 'bemerkung'" />
                </label>
                <div class="enotf-box col-span-3">
                    <span class="enotf-label">{{ trans('enotf.vitals.manual_time') }}</span>
                    <span class="grid grid-cols-2 gap-2">
                        <MaskedDate v-model="entry.date" :label="trans('enotf.ui.vl.date')" />
                        <MaskedTime v-model="entry.time" :data-mark="timeNeeded ? 'invalid' : null" :label="trans('enotf.ui.vl.time')" :fill-now="() => now().slice(11, 16)" />
                    </span>
                </div>
            </div>
            <p v-if="error" role="alert" class="mt-2 text-(--enotf-red)">{{ error }}</p>
            <FocusFooter :busy="busy" :save-label="t('save')" @back="emit('back')" @save="save" />
        </div>
        <NumberPad :disabled="active === 'bemerkung'" :code="active" :value="entry[active] ?? ''" :bz-unit="bzUnit" @press="(k) => (entry[active] = press(entry[active] ?? '', k))" />
    </div>
</template>
