<script setup lang="ts">
import { ref, watch } from 'vue';
import { trans } from 'laravel-vue-i18n';
import { renderSymbol } from '@/lib/tacticalSymbol';

// Draws a symbol with the library (a lazy chunk). Nothing here throws: an unknown value or a failed load shows a
// placeholder, and the SVG goes into an <img>, never into the DOM as markup.
const props = withDefaults(defineProps<{ symbol: unknown; name?: string | null; size?: number }>(), { name: null, size: 64 });

const state = ref<'empty' | 'loading' | 'ok' | 'failed'>('empty');
const src = ref('');
let run = 0;

watch(
    () => JSON.stringify([props.symbol, props.name]),
    async () => {
        const mine = ++run;
        state.value = 'loading';
        try {
            const url = await renderSymbol(props.symbol, props.name);
            if (mine !== run) return;
            src.value = url ?? '';
            state.value = url === null ? 'empty' : 'ok';
        } catch {
            if (mine === run) state.value = 'failed';
        }
    },
    { immediate: true },
);
</script>

<template>
    <div class="inline-flex items-center justify-center" :style="{ minWidth: `${size}px`, minHeight: `${size}px` }" data-testid="symbol-preview">
        <img v-if="state === 'ok'" :src="src" :alt="trans('symbols.alt', { name: name ?? '' })" :style="{ maxWidth: `${size * 2}px`, maxHeight: `${size}px` }" @error="state = 'failed'" />
        <span v-else-if="state === 'failed'" class="text-sm text-danger" role="status">{{ trans('symbols.preview_failed') }}</span>
        <span v-else-if="state === 'loading'" class="text-sm text-text-2" role="status">{{ trans('symbols.loading') }}</span>
        <span v-else class="text-sm text-text-2">{{ trans('symbols.no_symbol') }}</span>
    </div>
</template>
