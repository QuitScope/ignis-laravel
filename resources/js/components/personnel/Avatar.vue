<script setup lang="ts">
import { computed } from 'vue';
import { trans } from 'laravel-vue-i18n';

// Profile picture, or the first letters of the first and last name when there is none.
const props = defineProps<{ src: string | null; name: string }>();

const initials = computed(() => {
    const parts = props.name.trim().split(/\s+/).filter(Boolean);
    return ((parts[0]?.[0] ?? '') + (parts.length > 1 ? parts[parts.length - 1][0] : '')).toUpperCase();
});
</script>

<template>
    <img v-if="src" :src="src" :alt="trans('personnel.picture.alt', { name })" class="size-32 rounded-full object-cover" />
    <span
        v-else
        role="img"
        :aria-label="trans('personnel.picture.alt', { name })"
        class="inline-flex size-32 items-center justify-center rounded-full bg-(--accent-soft) text-3xl font-semibold text-accent"
    >{{ initials }}</span>
</template>
