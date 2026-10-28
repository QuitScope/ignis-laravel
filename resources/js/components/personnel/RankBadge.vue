<script setup lang="ts">
// Archive ranks (dismissed people) get a muted chip.
defineProps<{ label: string; archive?: boolean }>();
</script>

<template>
    <span
        class="inline-block rounded-full px-2 py-0.5 text-xs font-medium"
        :class="archive ? 'bg-(--fill-3) text-text-2' : 'bg-(--accent-soft) text-accent'"
    >{{ label }}</span>
</template>
