<script setup lang="ts">
import { defineAsyncComponent, shallowRef } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { EditorApi } from '@/editor/createEditor';

const EditorPane = defineAsyncComponent(() => import('@/editor/EditorPane.vue'));

const props = defineProps<{
    template: { id: number; name: string; category: string | null; is_active: boolean } | null;
    content: Record<string, unknown>;
    openDrafts: number;
    variables: { key: string; label: string }[];
}>();

const BASE = '/settings/documents/editor-templates';
const form = useForm({
    name: props.template?.name ?? '',
    category: props.template?.category ?? '',
    content: JSON.stringify(props.content),
});

const api = shallowRef<EditorApi | null>(null);

function save() {
    if (api.value) form.content = JSON.stringify(api.value.getJSON());
    if (props.template) form.put(`${BASE}/${props.template.id}`);
    else form.post(`${BASE}/create`);
}
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-start justify-between gap-2">
            <h1 class="text-2xl font-semibold">{{ template ? template.name : trans('documents.template.new_title') }}</h1>
            <Button as-child variant="ghost">
                <Link :href="BASE">{{ trans('documents.template.back') }}</Link>
            </Button>
        </div>

        <p v-if="openDrafts > 0" class="rounded-md border border-border bg-card p-3 text-sm" role="status">
            {{ trans('documents.template.drafts_notice', { count: String(openDrafts) }) }}
        </p>

        <form class="space-y-4" @submit.prevent="save">
            <div class="max-w-xl space-y-4 rounded-lg border border-border bg-card p-4 shadow-(--shadow)">
                <div class="space-y-1">
                    <Label for="tpl-name">{{ trans('documents.template.field.name') }} *</Label>
                    <Input id="tpl-name" v-model="form.name" required maxlength="150" :aria-invalid="!!form.errors.name" />
                    <p v-if="form.errors.name" class="text-sm text-danger">{{ form.errors.name }}</p>
                </div>
                <div class="space-y-1">
                    <Label for="tpl-category">{{ trans('documents.template.field.category') }}</Label>
                    <Input id="tpl-category" v-model="form.category" maxlength="100" :aria-invalid="!!form.errors.category" />
                    <p v-if="form.errors.category" class="text-sm text-danger">{{ form.errors.category }}</p>
                </div>
            </div>

            <div id="template-editor">
                <EditorPane mode="template" :content="content" :variables="variables" @ready="(a) => (api = a)" />
            </div>
            <p v-if="form.errors.content" class="whitespace-pre-line text-sm text-danger" role="alert">{{ form.errors.content }}</p>

            <Button type="submit" :disabled="form.processing">{{ trans('documents.template.save') }}</Button>
        </form>
    </div>
</template>
