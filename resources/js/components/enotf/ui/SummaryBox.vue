<script setup lang="ts">
/** Overview box (legacy edivi__box): title, read-only summary in the slot; the whole box opens its topic. */
import { CircleAlert } from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';
import { tileState, useEnotfForm, useEnotfWizard } from '@/lib/enotfUi';

const props = defineProps<{ title: string; t?: string; q?: number; requires?: string[]; marks?: string[] }>();
const form = useEnotfForm();
const wizard = useEnotfWizard();
const fill = computed(() => (props.requires ? tileState(props.requires, form.values) : null));
const marked = computed(() => form.invalid.concat(form.conflicts).some((k) => props.requires?.includes(k) || props.marks?.includes(k)));
const clickable = computed(() => !!props.t && !form.readonly);
</script>

<template>
    <component :is="clickable ? 'button' : 'section'" :type="clickable ? 'button' : undefined" class="enotf-box" @click="clickable && wizard.open(t ?? null, q)">
        <span class="enotf-box__title">
            {{ title }}
            <CircleAlert v-if="fill === 'red' || fill === 'yellow' || marked" class="size-5 text-(--enotf-red)" :aria-label="trans('enotf.ui.required_open')" />
        </span>
        <slot />
    </component>
</template>
