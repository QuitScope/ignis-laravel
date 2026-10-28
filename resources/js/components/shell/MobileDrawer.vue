<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { DialogClose, DialogContent, DialogOverlay, DialogPortal, DialogRoot, DialogTitle } from 'reka-ui';
import { onUnmounted } from 'vue';
import BrandMark from '@/components/BrandMark.vue';
import FaIcon from '@/components/FaIcon.vue';
import { faIcons } from '@/lib/faIcons';

// Navigation below 900 px (legacy: the sidebar slides in over a scrim): a modal dialog from the left, closed by Esc,
// the scrim, its close button, any navigation or widening the window past 900 px (AppLayout).
const open = defineModel<boolean>('open', { required: true });
onUnmounted(router.on('start', () => (open.value = false)));
</script>

<template>
    <DialogRoot v-model:open="open">
        <DialogPortal>
            <DialogOverlay class="ignis-drawer-scrim" />
            <DialogContent id="ignis-drawer" class="ignis-drawer" :aria-describedby="undefined">
                <div class="ignis-drawer__head">
                    <DialogTitle class="sr-only">{{ trans('nav.main') }}</DialogTitle>
                    <BrandMark />
                    <DialogClose class="ignis-btn ignis-btn--ghost ignis-btn--icon" :aria-label="trans('nav.close')">
                        <FaIcon :icon="faIcons.xmark" />
                    </DialogClose>
                </div>
                <slot />
            </DialogContent>
        </DialogPortal>
    </DialogRoot>
</template>
