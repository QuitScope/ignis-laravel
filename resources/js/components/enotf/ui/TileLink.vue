<script setup lang="ts">
/** Topic/sub-topic tile (legacy edivi__interactbutton-more): opens ?t=&q=, required stripe from the fields it leads to. */
import { ChevronRight } from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';
import { tileState, useEnotfForm, useEnotfWizard } from '@/lib/enotfUi';

const props = defineProps<{ t: string; q?: number; requires?: string[]; covers?: number[] }>();
const form = useEnotfForm();
const wizard = useEnotfWizard();
const fill = computed(() => (props.requires ? tileState(props.requires, form.values) : null));
const active = computed(
    () => wizard.topic.value === props.t && (props.q === undefined || wizard.step.value === props.q || (wizard.step.value !== null && !!props.covers?.includes(wizard.step.value))),
);
</script>

<template>
    <button type="button" :class="['enotf-tile enotf-stripe']" :data-fill="fill ?? undefined" :aria-current="active ? 'step' : undefined" @click="wizard.open(t, q)">
        <span><slot /></span>
        <span v-if="fill === 'red' || fill === 'yellow'" class="sr-only">{{ trans('enotf.ui.required_open') }}</span>
        <ChevronRight class="size-5 shrink-0" aria-hidden="true" />
    </button>
</template>
