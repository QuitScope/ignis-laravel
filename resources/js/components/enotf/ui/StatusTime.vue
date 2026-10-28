<script setup lang="ts">
/**
 * Status time (field type `datetime`, legacy times bar): tapping the empty field sets the server's "now";
 * a set time is corrected with the time and date masks. One value `YYYY-MM-DDTHH:MM` (wall clock, the server reads it in
 * ignis.timezone); emptying the time or the date clears the field.
 */
import { CircleAlert } from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';
import { computed, useId } from 'vue';
import MaskedDate from '@/components/enotf/ui/MaskedDate.vue';
import MaskedTime from '@/components/enotf/ui/MaskedTime.vue';
import { fieldMark, nowWall, useEnotfForm, wallOf } from '@/lib/enotfUi';

const props = defineProps<{ field: string; label: string; required?: boolean }>();
const form = useEnotfForm();
const id = useId();
const wall = computed(() => wallOf(form.values[props.field], form.timezone));
const setPart = (date: string | null, time: string | null) => form.set(props.field, date && time ? `${date}T${time}` : null);
</script>

<template>
    <div class="enotf-col gap-1" role="group" :aria-labelledby="id" :data-mark="fieldMark(form, field)">
        <span :id="id" class="enotf-col__head gap-1 px-0">
            {{ label }}
            <CircleAlert v-if="required && !wall" class="size-4 text-(--enotf-red)" :aria-label="trans('enotf.ui.required_open')" />
        </span>
        <button v-if="!wall" type="button" class="enotf-tile justify-center" :aria-label="`${label}: ${trans('enotf.ui.set_now')}`" :disabled="form.readonly" @click="form.set(field, nowWall(form.clock, form.timezone))">
            {{ trans('enotf.ui.tap_now') }}
        </button>
        <template v-else>
            <MaskedTime :model-value="wall.slice(11, 16)" :label="`${label} ${trans('enotf.ui.time')}`" :disabled="form.readonly" @update:model-value="setPart(wall.slice(0, 10), $event)" />
            <MaskedDate :model-value="wall.slice(0, 10)" :label="`${label} ${trans('enotf.ui.date')}`" :disabled="form.readonly" @update:model-value="setPart($event, wall.slice(11, 16))" />
        </template>
    </div>
</template>
