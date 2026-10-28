<script setup lang="ts">
/**
 * The ONLY place of the knowledge base that binds v-html. `html` must be KbContentRenderer::html() output (built on the
 * server from the allowlisted editor JSON), never stored JSON, plainText() or user text. tests/js/kbProse.test.ts keeps
 * v-html out of every other file.
 */
defineProps<{ html: string }>();
</script>

<template>
    <!-- eslint-disable-next-line vue/no-v-html -->
    <div class="kb-prose" v-html="html" />
</template>

<style scoped>
.kb-prose { line-height: 1.55; overflow-wrap: anywhere; }
.kb-prose :deep(h1) { font-size: 1.5em; font-weight: 700; margin: 0.8em 0 0.3em; }
.kb-prose :deep(h2) { font-size: 1.25em; font-weight: 700; margin: 0.8em 0 0.3em; }
.kb-prose :deep(h3) { font-size: 1.1em; font-weight: 700; margin: 0.8em 0 0.3em; }
.kb-prose :deep(p) { margin: 0 0 0.6em; }
.kb-prose :deep(ul) { list-style: disc; padding-left: 1.5em; margin: 0 0 0.6em; }
.kb-prose :deep(ol) { list-style: decimal; padding-left: 1.5em; margin: 0 0 0.6em; }
.kb-prose :deep(blockquote) { border-left: 3px solid var(--border-strong); padding-left: 0.8em; color: var(--text-2); margin: 0 0 0.6em; }
.kb-prose :deep(hr) { border: 0; border-top: 1px solid var(--border-strong); margin: 1em 0; }
.kb-prose :deep(a) { color: var(--accent); text-decoration: underline; }
.kb-prose :deep(table) { border-collapse: collapse; width: 100%; margin: 0.5em 0; }
.kb-prose :deep(td), .kb-prose :deep(th) { border: 1px solid var(--border-strong); padding: 0.25em 0.5em; vertical-align: top; }
.kb-prose :deep(th) { font-weight: 700; background: var(--surface-2); }
.kb-prose :deep(img) { max-width: 100%; height: auto; margin: 0.5em 0; }
</style>
