<script setup lang="ts">
import { computed, onBeforeUnmount, ref, type Component } from 'vue';
import { EditorContent } from '@tiptap/vue-3';
import type { JSONContent } from '@tiptap/core';
import { trans } from 'laravel-vue-i18n';
import {
    BetweenHorizontalEnd, BetweenVerticalEnd, Bold, Columns3, ImagePlus, Italic, Link2, List, ListOrdered, Minus, Quote, Redo2, Rows3,
    Strikethrough, Table, Trash2, Underline, Undo2,
} from '@lucide/vue';
import { createKbEditor, isAllowedHref, KB_IMAGE_SRC, serializeKb } from '@/editor/kbPreset';
import { xsrfToken } from '@/lib/draftSave';
import '@/editor/editor.css';

/**
 * Rich-text editor of the knowledge base (`kb` preset, schema = KbContentValidator). v-model is the Tiptap JSON with
 * null attributes dropped; the server validates it again on save. Images go through POST /lexicon/images.
 */
const props = defineProps<{ modelValue: Record<string, unknown> | null; readOnly?: boolean; label?: string }>();
const emit = defineEmits<{ 'update:modelValue': [value: JSONContent] }>();

const editor = createKbEditor({
    content: props.modelValue as JSONContent | null,
    editable: !props.readOnly,
    onUpdate: () => emit('update:modelValue', serializeKb(editor.getJSON())),
});
onBeforeUnmount(() => editor.destroy());

type Item = { key: string; icon: Component; label: string; run: () => unknown; active?: boolean };
const chain = () => editor.chain().focus();
const groups = computed<Item[][]>(() => {
    const e = editor;
    const inTable = e.isActive('table');

    return [
        [
            { key: 'undo', icon: Undo2, label: trans('documents.editor.tool.undo'), run: () => chain().undo().run() },
            { key: 'redo', icon: Redo2, label: trans('documents.editor.tool.redo'), run: () => chain().redo().run() },
        ],
        [
            { key: 'bold', icon: Bold, label: trans('documents.editor.tool.bold'), run: () => chain().toggleBold().run(), active: e.isActive('bold') },
            { key: 'italic', icon: Italic, label: trans('documents.editor.tool.italic'), run: () => chain().toggleItalic().run(), active: e.isActive('italic') },
            { key: 'underline', icon: Underline, label: trans('documents.editor.tool.underline'), run: () => chain().toggleUnderline().run(), active: e.isActive('underline') },
            { key: 'strike', icon: Strikethrough, label: trans('documents.editor.tool.strike'), run: () => chain().toggleStrike().run(), active: e.isActive('strike') },
            { key: 'link', icon: Link2, label: trans('knowledgebase.editor.link'), run: openLink, active: e.isActive('link') },
        ],
        [
            { key: 'bullet', icon: List, label: trans('documents.editor.tool.bullet_list'), run: () => chain().toggleBulletList().run(), active: e.isActive('bulletList') },
            { key: 'ordered', icon: ListOrdered, label: trans('documents.editor.tool.ordered_list'), run: () => chain().toggleOrderedList().run(), active: e.isActive('orderedList') },
            { key: 'quote', icon: Quote, label: trans('documents.editor.tool.quote'), run: () => chain().toggleBlockquote().run(), active: e.isActive('blockquote') },
            { key: 'hr', icon: Minus, label: trans('documents.editor.tool.horizontal_rule'), run: () => chain().setHorizontalRule().run() },
            { key: 'image', icon: ImagePlus, label: trans('knowledgebase.editor.image'), run: () => fileInput.value?.click() },
        ],
        inTable
            ? [
                { key: 'row', icon: BetweenHorizontalEnd, label: trans('documents.editor.tool.add_row'), run: () => chain().addRowAfter().run() },
                { key: 'col', icon: BetweenVerticalEnd, label: trans('documents.editor.tool.add_column'), run: () => chain().addColumnAfter().run() },
                { key: 'del-row', icon: Rows3, label: trans('documents.editor.tool.delete_row'), run: () => chain().deleteRow().run() },
                { key: 'del-col', icon: Columns3, label: trans('documents.editor.tool.delete_column'), run: () => chain().deleteColumn().run() },
                { key: 'del-table', icon: Trash2, label: trans('documents.editor.tool.delete_table'), run: () => chain().deleteTable().run() },
            ]
            : [{ key: 'table', icon: Table, label: trans('documents.editor.tool.table'), run: () => chain().insertTable({ rows: 3, cols: 3, withHeaderRow: true }).run() }],
    ];
});

const block = computed(() => ([1, 2, 3] as const).find((level) => editor.isActive('heading', { level })) ?? 0);
function setBlock(v: string) {
    const level = Number(v);
    if (level === 1 || level === 2 || level === 3) chain().setHeading({ level }).run();
    else chain().setParagraph().run();
}

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
    if (!isAllowedHref(href)) {
        linkError.value = trans('knowledgebase.editor.link_invalid');
        return;
    }
    chain().extendMarkRange('link').setLink({ href }).run();
    linkOpen.value = false;
}
function removeLink() {
    chain().extendMarkRange('link').unsetLink().run();
    linkOpen.value = false;
}

const fileInput = ref<HTMLInputElement>();
const uploading = ref(false);
const uploadError = ref('');
async function upload(event: Event) {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    input.value = '';
    if (!file) return;
    uploading.value = true;
    uploadError.value = '';
    try {
        const body = new FormData();
        body.append('image', file);
        const res = await fetch('/lexicon/images', { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrfToken() }, body });
        const json = await res.json().catch(() => null);
        if (res.ok && typeof json?.src === 'string' && KB_IMAGE_SRC.test(json.src)) {
            chain().insertContent({ type: 'image', attrs: { src: json.src, alt: typeof json.alt === 'string' ? json.alt : '' } }).run();
        } else {
            uploadError.value = (json?.errors?.image?.[0] as string | undefined) ?? trans('knowledgebase.editor.upload_failed');
        }
    } catch {
        uploadError.value = trans('knowledgebase.editor.upload_failed');
    } finally {
        uploading.value = false;
    }
}
</script>

<template>
    <div class="efe-frame kb-editor">
        <div v-if="!readOnly" class="efe-toolbar" role="toolbar" :aria-label="trans('knowledgebase.editor.toolbar')">
            <select class="efe-toolbar__select" :aria-label="trans('documents.editor.tool.block')" :value="block" @change="setBlock(($event.target as HTMLSelectElement).value)">
                <option :value="0">{{ trans('documents.editor.tool.paragraph') }}</option>
                <option v-for="l in [1, 2, 3]" :key="l" :value="l">{{ trans('documents.editor.tool.heading', { level: String(l) }) }}</option>
            </select>
            <div v-for="(group, i) in groups" :key="i" class="efe-toolbar__group">
                <button
                    v-for="item in group"
                    :key="item.key"
                    type="button"
                    class="efe-toolbar__button"
                    :aria-label="item.label"
                    :title="item.label"
                    :aria-pressed="item.active === undefined ? undefined : item.active"
                    :disabled="item.key === 'image' && uploading"
                    @mousedown.prevent
                    @click="item.run()"
                >
                    <component :is="item.icon" class="size-4" aria-hidden="true" />
                </button>
            </div>
            <input ref="fileInput" type="file" class="hidden" accept="image/jpeg,image/png,image/webp,image/gif" @change="upload" />
        </div>
        <form v-if="linkOpen" class="flex flex-wrap items-center gap-2 border-b border-border px-3 py-2" @submit.prevent="applyLink">
            <label class="sr-only" for="kb-link-href">{{ trans('knowledgebase.editor.link') }}</label>
            <input
                id="kb-link-href"
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
        <p v-if="uploading" class="px-3 py-1 text-sm text-text-2" role="status">{{ trans('knowledgebase.editor.uploading') }}</p>
        <p v-if="uploadError" class="px-3 py-1 text-sm text-danger" role="alert">{{ uploadError }}</p>
        <EditorContent :editor="editor" class="kb-editor__surface" :aria-label="label" />
    </div>
</template>

<style scoped>
.kb-editor__surface :deep(.ProseMirror) { min-height: 12rem; padding: 0.75rem 1rem; outline: none; line-height: 1.5; }
.kb-editor__surface :deep(.ProseMirror:focus-visible) { box-shadow: inset 0 0 0 2px var(--accent); }
.kb-editor__surface :deep(h1) { font-size: 1.5em; font-weight: 700; margin: 0.6em 0 0.3em; }
.kb-editor__surface :deep(h2) { font-size: 1.25em; font-weight: 700; margin: 0.6em 0 0.3em; }
.kb-editor__surface :deep(h3) { font-size: 1.1em; font-weight: 700; margin: 0.6em 0 0.3em; }
.kb-editor__surface :deep(p) { margin: 0 0 0.5em; }
.kb-editor__surface :deep(ul) { list-style: disc; padding-left: 1.5em; }
.kb-editor__surface :deep(ol) { list-style: decimal; padding-left: 1.5em; }
.kb-editor__surface :deep(blockquote) { border-left: 3px solid var(--border-strong); padding-left: 0.8em; color: var(--text-2); }
.kb-editor__surface :deep(hr) { border: 0; border-top: 1px solid var(--border-strong); margin: 0.8em 0; }
.kb-editor__surface :deep(a) { color: var(--accent); text-decoration: underline; }
.kb-editor__surface :deep(table) { border-collapse: collapse; width: 100%; margin: 0.4em 0; }
.kb-editor__surface :deep(td), .kb-editor__surface :deep(th) { border: 1px solid var(--border-strong); padding: 0.25em 0.4em; vertical-align: top; }
.kb-editor__surface :deep(th) { font-weight: 700; background: var(--surface-2); }
.kb-editor__surface :deep(.selectedCell) { background: var(--accent-soft); }
.kb-editor__surface :deep(.kb-image) { max-width: 100%; height: auto; margin: 0.5em 0; }
.kb-editor__surface :deep(.kb-image.ProseMirror-selectednode) { outline: 2px solid var(--accent); }
</style>
