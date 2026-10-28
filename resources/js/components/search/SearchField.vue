<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { trans } from 'laravel-vue-i18n';
import FaIcon from '@/components/FaIcon.vue';
import { faIcons } from '@/lib/faIcons';
import CommandPalette from '@/components/search/CommandPalette.vue';
import { isMac, isPaletteShortcut, modalOpen, type PaletteAction } from '@/lib/searchPalette';

// Topbar search: a field-like button that opens the palette, as does Ctrl K (⌘K on a Mac) anywhere on the page.
defineProps<{ actions: PaletteAction[] }>();
const open = ref(false);
const mac = isMac(navigator.platform || navigator.userAgent);
const shortcut = computed(() => (mac ? '⌘K' : trans('search.shortcut')));

function onKeydown(e: KeyboardEvent) {
    if (!isPaletteShortcut(e, mac)) return;
    if (open.value) {
        e.preventDefault();
        open.value = false;
    } else if (!modalOpen(document)) {
        e.preventDefault();
        open.value = true;
    }
}
onMounted(() => window.addEventListener('keydown', onKeydown));
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown));
</script>

<template>
    <div role="search" class="flex min-w-0">
        <button
            type="button"
            class="ignis-search"
            aria-haspopup="dialog"
            :aria-label="trans('search.open')"
            :aria-keyshortcuts="mac ? 'Meta+K' : 'Control+K'"
            @click="open = true"
        >
            <FaIcon :icon="faIcons['magnifying-glass']" />
            <span class="ignis-search__label">{{ trans('search.topbar') }}</span>
            <kbd class="ignis-kbd" aria-hidden="true">{{ shortcut }}</kbd>
        </button>
        <CommandPalette v-model:open="open" :actions="actions" />
    </div>
</template>
