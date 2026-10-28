<script setup lang="ts">
import FaIcon from '@/components/FaIcon.vue';
import { faIcons, type FaIconName } from '@/lib/faIcons';
import type { Tone } from '@/lib/dashboardWidgets';

// Legacy templates/partials/empty.php, variant sm: glyph, title, text, optional command line.
defineProps<{ icon: FaIconName; title: string; text?: string; tone?: Tone; code?: string }>();
</script>

<template>
    <div class="ignis-empty ignis-empty--sm" :data-tone="tone" role="status">
        <span class="ignis-glyph" :data-tone="tone"><FaIcon :icon="faIcons[icon]" /></span>
        <h3 class="ignis-empty__title">{{ title }}</h3>
        <p v-if="text" class="ignis-empty__text">{{ text }}</p>
        <p v-if="code" class="ignis-empty__code">{{ code }}</p>
    </div>
</template>
