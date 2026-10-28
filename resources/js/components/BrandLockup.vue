<script setup lang="ts">
// Mark (accent) + wordmark (text colour) of ıgnıs, paths of the old system's ignis-mark.svg / ignis-wordmark.svg as inline SVG.
const MARK = 'M24 0H96V72L72 96H0V24ZM36 80H52V44L36 60ZM40 24L50 34L60 24L50 14Z';
const WORDMARK = 'M1 29.5L13 17.5V75.5H1ZM7.5 0L15 7.5L7.5 15L0 7.5ZM22 34.5L33 23.5H65V86.5L54 97.5H25L35 87.5H48.5L53 83V75.5H33L22 64.5ZM34 38V61L38.5 65.5H53V33.5H38.5ZM75.5 23.5H106.5L117.5 34.5V75.5H105.5V38L101 33.5H87.5V75.5H75.5ZM128 29.5L140 17.5V75.5H128ZM134.5 0L142 7.5L134.5 15L127 7.5ZM148 34.5L159 23.5H188L178 33.5H164.5L160 38V44H177L188 55V64.5L177 75.5H148L158 65.5H171.5L176 61V53H159L148 42Z';

// Topbar and sign-in as in the legacy lockup: the mark as high as the wordmark's x-height (76 of 97.5), top-aligned
// (sign-in: --lockup-h 92 * 76 / 283 ≈ 25 px, the same 32 px as the topbar).
defineProps<{ size: 'topbar' | 'login' | 'footer' }>();
</script>

<template>
    <span class="inline-flex text-text" role="img" aria-label="ignis" :class="size === 'footer' ? 'h-8 items-center gap-2' : 'h-8 items-start gap-1.5'">
        <svg viewBox="0 0 96 96" class="w-auto" :class="size === 'footer' ? 'h-full' : 'h-[78%]'" aria-hidden="true"><path fill="var(--accent-fill, var(--accent))" fill-rule="evenodd" :d="MARK" /></svg>
        <svg viewBox="0 0 188 97.5" class="h-full w-auto" aria-hidden="true"><path fill="currentColor" fill-rule="evenodd" :d="WORDMARK" /></svg>
    </span>
</template>
