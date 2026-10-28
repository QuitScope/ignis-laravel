<script setup lang="ts">
/** Quick tile ("ohne path. Befund", "keine"): sets all values in one tick (one PATCH); pressed while all match. */
import { computed } from 'vue';
import { useEnotfForm } from '@/lib/enotfUi';

const props = defineProps<{ values: Record<string, unknown> }>();
const form = useEnotfForm();
const active = computed(() => Object.entries(props.values).every(([k, v]) => String(form.values[k] ?? '') === String(v ?? '')));
const fill = () => Object.entries(props.values).forEach(([k, v]) => form.set(k, v));
</script>

<template>
    <button type="button" class="enotf-tile enotf-tile--ok" :aria-pressed="active" :disabled="form.readonly" @click="fill">
        <span><slot /></span>
    </button>
</template>
