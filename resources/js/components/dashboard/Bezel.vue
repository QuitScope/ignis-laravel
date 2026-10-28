<script setup lang="ts">
import FaIcon from '@/components/FaIcon.vue';
import { faIcons, type FaIconName } from '@/lib/faIcons';

// Legacy .ignis-bezel: a shell with a head (icon, title, actions), a well for the content and an optional foot.
defineProps<{ icon: FaIconName; titleId: string; list?: boolean }>();
</script>

<template>
    <section class="ignis-bezel" :aria-labelledby="titleId">
        <div class="ignis-bezel__head">
            <FaIcon :icon="faIcons[icon]" class="ignis-bezel__icon" />
            <h2 :id="titleId" class="ignis-bezel__title"><slot name="title" /></h2>
            <div v-if="$slots.actions" class="ignis-bezel__actions"><slot name="actions" /></div>
        </div>
        <div class="ignis-bezel__well" :class="{ 'ignis-bezel__well--list': list }"><slot /></div>
        <div v-if="$slots.foot" class="ignis-bezel__foot"><slot name="foot" /></div>
    </section>
</template>
