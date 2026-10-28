<script setup lang="ts">
import { computed, type Component } from 'vue';
import type { Editor } from '@tiptap/vue-3';
import { trans } from 'laravel-vue-i18n';
import {
    AlignCenter, AlignJustify, AlignLeft, AlignRight, BetweenHorizontalEnd, BetweenVerticalEnd, Bold, Columns3, Eye, Italic,
    List, ListOrdered, Lock, Minus, Plus, Quote, Redo2, Rows3, SeparatorHorizontal, Settings2, Strikethrough, Table,
    TextCursorInput, Trash2, Underline, Undo2,
} from '@lucide/vue';
import type { EditorMode } from '../createEditor';
import { sectionAt } from '../extensions/DocSection';

const props = defineProps<{ editor: Editor; mode: EditorMode; editable: boolean; preview: boolean }>();
const emit = defineEmits<{ preview: []; field: []; section: [] }>();

type Item = { key: string; icon: Component; label: string; run: () => unknown; active?: boolean; text?: string };

const chain = () => props.editor.chain().focus();
const groups = computed<Item[][]>(() => {
    const e = props.editor;
    const inTable = e.isActive('table');
    const align = (a: string, icon: Component): Item => ({
        key: `align-${a}`, icon, label: trans(`documents.editor.tool.align_${a}`),
        run: () => chain().setTextAlign(a).run(), active: e.isActive({ textAlign: a }),
    });
    const list: Item[][] = [
        [
            { key: 'undo', icon: Undo2, label: trans('documents.editor.tool.undo'), run: () => chain().undo().run() },
            { key: 'redo', icon: Redo2, label: trans('documents.editor.tool.redo'), run: () => chain().redo().run() },
        ],
        [
            { key: 'bold', icon: Bold, label: trans('documents.editor.tool.bold'), run: () => chain().toggleBold().run(), active: e.isActive('bold') },
            { key: 'italic', icon: Italic, label: trans('documents.editor.tool.italic'), run: () => chain().toggleItalic().run(), active: e.isActive('italic') },
            { key: 'underline', icon: Underline, label: trans('documents.editor.tool.underline'), run: () => chain().toggleUnderline().run(), active: e.isActive('underline') },
            { key: 'strike', icon: Strikethrough, label: trans('documents.editor.tool.strike'), run: () => chain().toggleStrike().run(), active: e.isActive('strike') },
        ],
        [
            { key: 'bullet', icon: List, label: trans('documents.editor.tool.bullet_list'), run: () => chain().toggleBulletList().run(), active: e.isActive('bulletList') },
            { key: 'ordered', icon: ListOrdered, label: trans('documents.editor.tool.ordered_list'), run: () => chain().toggleOrderedList().run(), active: e.isActive('orderedList') },
            { key: 'quote', icon: Quote, label: trans('documents.editor.tool.quote'), run: () => chain().toggleBlockquote().run(), active: e.isActive('blockquote') },
        ],
        [align('left', AlignLeft), align('center', AlignCenter), align('right', AlignRight), align('justify', AlignJustify)],
        inTable
            ? [
                { key: 'row', icon: BetweenHorizontalEnd, label: trans('documents.editor.tool.add_row'), run: () => chain().addRowAfter().run() },
                { key: 'col', icon: BetweenVerticalEnd, label: trans('documents.editor.tool.add_column'), run: () => chain().addColumnAfter().run() },
                { key: 'del-row', icon: Rows3, label: trans('documents.editor.tool.delete_row'), run: () => chain().deleteRow().run() },
                { key: 'del-col', icon: Columns3, label: trans('documents.editor.tool.delete_column'), run: () => chain().deleteColumn().run() },
                { key: 'del-table', icon: Trash2, label: trans('documents.editor.tool.delete_table'), run: () => chain().deleteTable().run() },
            ]
            : [{ key: 'table', icon: Table, label: trans('documents.editor.tool.table'), run: () => chain().insertTable({ rows: 3, cols: 3, withHeaderRow: false }).run() }],
        [
            { key: 'hr', icon: Minus, label: trans('documents.editor.tool.horizontal_rule'), run: () => chain().setHorizontalRule().run() },
            { key: 'page', icon: SeparatorHorizontal, label: trans('documents.editor.tool.page_break'), run: () => chain().setPageBreak().run() },
        ],
    ];
    if (props.mode === 'template') {
        const section = sectionAt(e.state);
        list.push([
            { key: 'free', icon: Plus, text: trans('documents.editor.tool.add_free'), label: trans('documents.editor.tool.add_free_label'), run: () => chain().insertSection('free').run() },
            { key: 'locked', icon: Plus, text: trans('documents.editor.tool.add_locked'), label: trans('documents.editor.tool.add_locked_label'), run: () => chain().insertSection('locked').run() },
            { key: 'mode', icon: Lock, label: trans('documents.editor.tool.toggle_mode'), run: () => chain().toggleSectionMode().run(), active: section?.node.attrs.mode === 'locked' },
            { key: 'field', icon: TextCursorInput, text: trans('documents.editor.tool.field'), label: trans('documents.editor.tool.field_label'), run: () => emit('field') },
            { key: 'section', icon: Settings2, text: trans('documents.editor.tool.section'), label: trans('documents.editor.tool.section_label'), run: () => emit('section') },
        ]);
    }
    return list;
});

const block = computed(() => {
    const e = props.editor;
    return ([1, 2, 3] as const).find((level) => e.isActive('heading', { level })) ?? 0;
});
const setBlock = (v: string) => {
    const level = Number(v);
    if (level === 1 || level === 2 || level === 3) chain().setHeading({ level }).run();
    else chain().setParagraph().run();
};
</script>

<template>
    <div class="efe-toolbar" role="toolbar" :aria-label="trans('documents.editor.toolbar')">
        <template v-if="editable">
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
                    @mousedown.prevent
                    @click="item.run()"
                >
                    <component :is="item.icon" class="size-4" aria-hidden="true" />
                    <span v-if="item.text">{{ item.text }}</span>
                </button>
            </div>
        </template>
        <div class="efe-toolbar__group efe-toolbar__end">
            <button type="button" class="efe-toolbar__button" :aria-pressed="preview" :title="trans('documents.editor.tool.preview')" @click="emit('preview')">
                <Eye class="size-4" aria-hidden="true" />
                <span>{{ trans('documents.editor.tool.preview') }}</span>
            </button>
        </div>
    </div>
</template>
