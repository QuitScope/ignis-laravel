<script setup lang="ts">
import { computed, onBeforeUnmount, ref, type Component } from 'vue';
import { EditorContent } from '@tiptap/vue-3';
import type { JSONContent } from '@tiptap/core';
import { trans } from 'laravel-vue-i18n';
import { Bold, Braces, Italic, Link2, List, ListOrdered, Quote, Redo2, Strikethrough, Undo2 } from '@lucide/vue';
import { createMailEditor, isMailHref, serializeMail } from '@/editor/mailPreset';
import type { EditorMode, Variable } from '@/editor/createEditor';
import '@/editor/editor.css';

/**
 * Rich-text editor of mail texts and signatures (`mail` preset, schema = MailContentValidator). v-model is the
 * Tiptap JSON with null attributes dropped; the server validates it again. With `variables` it edits a signature
 * template (`{{` inserts a placeholder); `values` + readOnly shows the template filled in (preview).
 */
const props = defineProps<{
    modelValue: Record<string, unknown> | null;
    readOnly?: boolean;
    label?: string;
    variables?: Variable[];
    values?: Record<string, string>;
    mode?: EditorMode;
    signature?: boolean;
}>();
const emit = defineEmits<{ 'update:modelValue': [value: JSONContent] }>();

const editor = createMailEditor({
    content: props.modelValue as JSONContent | null,
    editable: !props.readOnly,
    label: props.label,
    variables: props.variables ? { mode: props.mode ?? 'template', list: props.variables, values: props.values } : undefined,
    onUpdate: () => emit('update:modelValue', serializeMail(editor.getJSON())),
});
onBeforeUnmount(() => editor.destroy());

type Item = { key: string; icon: Component; label: string; run: () => unknown; active?: boolean };
const chain = () => editor.chain().focus();
const groups = computed<Item[][]>(() => {
    const e = editor;
    const marks: Item[] = [
        { key: 'bold', icon: Bold, label: trans('documents.editor.tool.bold'), run: () => chain().toggleBold().run(), active: e.isActive('bold') },
        { key: 'italic', icon: Italic, label: trans('documents.editor.tool.italic'), run: () => chain().toggleItalic().run(), active: e.isActive('italic') },
        { key: 'strike', icon: Strikethrough, label: trans('documents.editor.tool.strike'), run: () => chain().toggleStrike().run(), active: e.isActive('strike') },
        { key: 'link', icon: Link2, label: trans('knowledgebase.editor.link'), run: openLink, active: e.isActive('link') },
    ];
    return [
        [
            { key: 'undo', icon: Undo2, label: trans('documents.editor.tool.undo'), run: () => chain().undo().run() },
            { key: 'redo', icon: Redo2, label: trans('documents.editor.tool.redo'), run: () => chain().redo().run() },
        ],
        marks,
        props.signature
            ? [{ key: 'variable', icon: Braces, label: trans('mail.signature.insert'), run: () => chain().insertContent('{{').run() }]
            : [
                { key: 'bullet', icon: List, label: trans('documents.editor.tool.bullet_list'), run: () => chain().toggleBulletList().run(), active: e.isActive('bulletList') },
                { key: 'ordered', icon: ListOrdered, label: trans('documents.editor.tool.ordered_list'), run: () => chain().toggleOrderedList().run(), active: e.isActive('orderedList') },
                { key: 'quote', icon: Quote, label: trans('documents.editor.tool.quote'), run: () => chain().toggleBlockquote().run(), active: e.isActive('blockquote') },
            ],
    ];
});

const linkOpen = ref(false);
const linkHref = ref('');
const linkError = ref('');
function openLink() {
    linkHref.value = String(editor.getAttributes('link').href ?? '');
    linkError.value = '';
    linkOpen.value = true;
}
function applyLink() {
    const href = linkHref.value.trim();
    if (href === '') return removeLink();
    if (!isMailHref(href)) {
        linkError.value = trans('mail.editor.link_invalid');
        return;
    }
    chain().extendMarkRange('link').setLink({ href }).run();
    linkOpen.value = false;
}
function removeLink() {
    chain().extendMarkRange('link').unsetLink().run();
    linkOpen.value = false;
}
</script>

<template>
    <div class="efe-frame mail-editor">
        <div v-if="!readOnly" class="efe-toolbar" role="toolbar" :aria-label="trans('mail.editor.toolbar')">
            <div v-for="(group, i) in groups" :key="i" class="efe-toolbar__group">
                <button
                    v-for="item in group"
                    :key="item.key"
                    type="button"
                    class="efe-toolbar__button"
                    :aria-label="item.label"
                    :title="item.label"
                    :aria-pressed="item.active === undefined ? undefined : item.active"
                    @mousedown.prevent
                    @click="item.run()"
                >
                    <component :is="item.icon" class="size-4" aria-hidden="true" />
                </button>
            </div>
        </div>
        <form v-if="linkOpen" class="flex flex-wrap items-center gap-2 border-b border-border px-3 py-2" @submit.prevent="applyLink">
            <label class="sr-only" for="mail-link-href">{{ trans('knowledgebase.editor.link') }}</label>
            <input
                id="mail-link-href"
                v-model="linkHref"
                type="text"
                inputmode="url"
                class="h-8 min-w-0 flex-1 rounded-md border border-border bg-transparent px-2 text-sm"
                :placeholder="trans('knowledgebase.editor.link_prompt')"
                :aria-invalid="linkError !== '' || undefined"
                @keydown.esc.prevent="linkOpen = false"
            />
            <button type="submit" class="efe-toolbar__button px-2 text-sm">{{ trans('knowledgebase.editor.link_apply') }}</button>
            <button type="button" class="efe-toolbar__button px-2 text-sm" @click="removeLink">{{ trans('knowledgebase.editor.link_remove') }}</button>
            <p v-if="linkError" class="w-full text-sm text-danger" role="alert">{{ linkError }}</p>
        </form>
        <EditorContent :editor="editor" class="mail-editor__surface" />
    </div>
</template>

<style scoped>
.mail-editor__surface :deep(.ProseMirror) { min-height: 8rem; padding: 0.75rem 1rem; outline: none; line-height: 1.5; }
.mail-editor__surface :deep(.ProseMirror:focus-visible) { box-shadow: inset 0 0 0 2px var(--accent); }
.mail-editor__surface :deep(p) { margin: 0 0 0.5em; }
.mail-editor__surface :deep(ul) { list-style: disc; padding-left: 1.5em; }
.mail-editor__surface :deep(ol) { list-style: decimal; padding-left: 1.5em; }
.mail-editor__surface :deep(blockquote) { border-left: 3px solid var(--border-strong); padding-left: 0.8em; color: var(--text-2); }
.mail-editor__surface :deep(a) { color: var(--accent); text-decoration: underline; }
.mail-editor__surface :deep(.mail-badge) { display: inline; height: 1.2em; width: auto; margin-right: 0.3em; vertical-align: text-bottom; }
</style>
