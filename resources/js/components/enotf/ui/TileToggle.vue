<script setup lang="ts">
/** Switch tile (legacy btn-check checkbox): on = `on` (default true), off = `off` (default false). */
import { Square, SquareCheck } from '@lucide/vue';
import { computed } from 'vue';
import { useEnotfForm } from '@/lib/enotfUi';

const props = withDefaults(defineProps<{ field: string; on?: boolean | number; off?: boolean | number | null }>(), { on: true, off: false });
const form = useEnotfForm();
const checked = computed(() => form.values[props.field] === props.on);
</script>

<template>
    <button type="button" role="checkbox" class="enotf-tile" :aria-checked="checked" :disabled="form.readonly" @click="form.set(field, checked ? off : on)">
        <span><slot /></span>
        <component :is="checked ? SquareCheck : Square" class="size-5 shrink-0" aria-hidden="true" />
    </button>
</template>
