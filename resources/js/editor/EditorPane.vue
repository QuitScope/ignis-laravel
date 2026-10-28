<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref, shallowRef, watch } from 'vue';
import { EditorContent } from '@tiptap/vue-3';
import type { JSONContent } from '@tiptap/core';
import { NodeSelection } from '@tiptap/pm/state';
import { trans } from 'laravel-vue-i18n';
import { collectOpenItems, createEditor, newId, revealItem, serialize, type BlockReason, type EditorApi, type EditorMode, type Gender, type OpenItem, type Variable } from './createEditor';
import { sectionAt } from './extensions/DocSection';
import Toolbar from './toolbar/Toolbar.vue';
import OpenItems from './OpenItems.vue';
import FieldDialog, { type FieldResult } from './FieldDialog.vue';
import SectionDialog, { type SectionResult } from './SectionDialog.vue';
import './editor.css';

/**
 * The Tiptap editor of both pages, loaded as its own chunk (defineAsyncComponent): toolbar, sheet, "Noch offen"
 * (document mode), field and section dialogs (template mode) and the snackbar for blocked input. Saving stays
 * with the page, which gets an EditorApi through `ready`.
 */
const props = defineProps<{
    mode: EditorMode;
    content: Record<string, unknown>;
    variables: Variable[];
    values?: Record<string, string>;
    missingReasons?: Record<string, string>;
    gender?: number | null;
    readOnly?: boolean;
}>();
const emit = defineEmits<{ ready: [api: EditorApi]; change: [] }>();

const BLOCK_REPEAT_MS = 3000;
const values = props.values ?? {};
const gender: Gender = props.gender === 0 ? 'male' : props.gender === 1 ? 'female' : null;

const snack = ref('');
const lastShown: Partial<Record<BlockReason, number>> = {};
let snackTimer: ReturnType<typeof setTimeout> | undefined;
function onBlocked(reason: BlockReason) {
    const now = Date.now();
    if (now - (lastShown[reason] ?? 0) < BLOCK_REPEAT_MS) return;
    lastShown[reason] = now;
    snack.value = trans(`documents.editor.blocked.${reason}`);
    clearTimeout(snackTimer);
    snackTimer = setTimeout(() => (snack.value = ''), 5000);
}

const version = ref(0);
let versionTimer: ReturnType<typeof setTimeout> | undefined;
const { editor, conforms, difference } = createEditor({
    mode: props.mode,
    content: props.content as JSONContent,
    variables: props.variables,
    values,
    gender,
    editable: !props.readOnly,
    onBlocked,
    onUpdate: () => {
        emit('change');
        clearTimeout(versionTimer);
        versionTimer = setTimeout(() => version.value++, 150);
    },
});
const editorRef = shallowRef(editor);

const preview = ref(false);
const locked = ref(!!props.readOnly);
const editable = computed(() => conforms && !locked.value && !preview.value);
watch(editable, (e) => editor.setEditable(e, false));

const items = computed(() => {
    void version.value;
    return collectOpenItems(editor, props.variables, values);
});

const fieldDialog = reactive({ open: false, edit: false, pos: -1, initial: { label: '', required: false, options: [] } as FieldResult });
function openField(pos?: number) {
    const sel = editor.state.selection;
    const at = pos ?? (sel instanceof NodeSelection && sel.node.type.name === 'docField' ? sel.from : -1);
    const node = at >= 0 ? editor.state.doc.nodeAt(at) : null;
    Object.assign(fieldDialog, node
        ? { open: true, edit: true, pos: at, initial: { label: node.attrs.label ?? '', required: node.attrs.required === true, options: node.attrs.options ?? [] } }
        : { open: true, edit: false, pos: -1, initial: { label: editor.state.doc.textBetween(sel.from, sel.to, ' ').trim(), required: false, options: [] } });
}
function submitField(r: FieldResult) {
    const attrs = { label: r.label || null, required: r.required || null, options: r.options.length ? r.options : null };
    if (fieldDialog.edit) {
        const node = editor.state.doc.nodeAt(fieldDialog.pos);
        if (node) editor.chain().focus().command(({ tr }) => (tr.setNodeMarkup(fieldDialog.pos, undefined, { ...node.attrs, ...attrs }), true)).run();
    } else {
        editor.chain().focus().insertContent({ type: 'docField', attrs: { fieldId: newId('field'), ...attrs, value: '' } }).run();
    }
}
function removeField() {
    const node = editor.state.doc.nodeAt(fieldDialog.pos);
    if (node) editor.chain().focus().deleteRange({ from: fieldDialog.pos, to: fieldDialog.pos + node.nodeSize }).run();
}

const sectionDialog = reactive({ open: false, pos: -1, initial: { title: '', hint: '', repeatable: false } as SectionResult });
function openSection() {
    const at = sectionAt(editor.state);
    if (!at) return;
    Object.assign(sectionDialog, { open: true, pos: at.pos, initial: { title: at.node.attrs.title ?? '', hint: at.node.attrs.hint ?? '', repeatable: at.node.attrs.repeatable === true } });
}
function submitSection(r: SectionResult) {
    const node = editor.state.doc.nodeAt(sectionDialog.pos);
    if (!node) return;
    const attrs: Record<string, unknown> = { ...node.attrs, title: r.title || null, hint: r.hint || null, repeatable: r.repeatable || null };
    if (r.repeatable && !node.attrs.templateSectionId) attrs.templateSectionId = node.attrs.sectionId;
    editor.chain().focus().command(({ tr }) => (tr.setNodeMarkup(sectionDialog.pos, undefined, attrs), true)).run();
}

const sheet = ref<HTMLElement>();
const onFieldEdit = (e: Event) => openField((e as CustomEvent<{ pos: number }>).detail.pos);

onMounted(() => {
    if (props.mode === 'template') sheet.value?.addEventListener('efe-field-edit', onFieldEdit);
    if (conforms) {
        emit('ready', {
            getJSON: () => serialize(editor.getJSON()),
            openItems: () => collectOpenItems(editor, props.variables, values),
            reveal: (item: OpenItem) => {
                preview.value = false;
                revealItem(editor, item.pos);
            },
            lock: () => (locked.value = true),
        });
    }
});
onBeforeUnmount(() => {
    sheet.value?.removeEventListener('efe-field-edit', onFieldEdit);
    clearTimeout(snackTimer);
    clearTimeout(versionTimer);
    editor.destroy();
});
</script>

<template>
    <div class="space-y-3">
        <div v-if="!conforms" class="rounded-md border border-danger bg-card p-3 text-sm" role="alert">
            <p class="font-medium text-danger">{{ trans('documents.editor.error.schema') }}</p>
            <p class="mt-1 text-text-2">{{ trans('documents.editor.error.schema_where', { path: difference ?? '' }) }}</p>
        </div>
        <template v-else>
            <OpenItems v-if="mode === 'document' && !locked" :items="items" :missing-reasons="missingReasons ?? {}" @reveal="(i) => { preview = false; revealItem(editor, i.pos); }" />
            <div class="efe-frame">
                <Toolbar v-if="!locked" :editor="editorRef" :mode="mode" :editable="editable" :preview="preview" @preview="preview = !preview" @field="openField()" @section="openSection" />
                <div ref="sheet" class="efe-surround">
                    <EditorContent :editor="editorRef" class="efe-page" :class="{ 'efe-preview': preview || locked, [`efe-mode-${mode}`]: true }" />
                </div>
            </div>
        </template>
        <FieldDialog v-if="mode === 'template'" v-model:open="fieldDialog.open" :edit="fieldDialog.edit" :initial="fieldDialog.initial" @submit="submitField" @remove="removeField" />
        <SectionDialog v-if="mode === 'template'" v-model:open="sectionDialog.open" :initial="sectionDialog.initial" @submit="submitSection" />
        <div class="efe-snackbar" role="status" aria-live="polite">
            <p v-if="snack">{{ snack }}</p>
        </div>
    </div>
</template>
