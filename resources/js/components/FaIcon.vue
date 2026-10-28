<script setup lang="ts">
import type { IconDefinition } from '@fortawesome/fontawesome-common-types';
import { computed } from 'vue';

// Font Awesome icon as inline SVG (no masks, no font): path data of a named import from lib/faIcons.ts.
const props = defineProps<{ icon: IconDefinition; class?: string }>();
const paths = computed(() => [props.icon.icon[4]].flat());
</script>

<template>
    <svg :viewBox="`0 0 ${icon.icon[0]} ${icon.icon[1]}`" fill="currentColor" aria-hidden="true" :class="props.class ?? 'size-4'">
        <path v-for="d in paths" :key="d" :d="d" />
    </svg>
</template>
