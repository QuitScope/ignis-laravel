<script setup lang="ts">
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import type { JSONContent } from '@tiptap/core';
import { Button } from '@/components/ui/button';
import MailEditor from '@/components/mail/MailEditor.vue';
import type { Variable } from '@/editor/createEditor';

type MailboxRef = { id: number; address: string; display_name: string; kind: 'personal' | 'group' };

/** The signature of one mailbox: template editor with placeholders, a preview with the writer's details. */
const props = defineProps<{ mailbox: MailboxRef; body: Record<string, unknown>; hasOwn: boolean; variables: Variable[]; values: Record<string, string> }>();

const body = ref<Record<string, unknown>>(props.body);
const previewKey = ref(0);
const form = useForm({ mailbox_id: props.mailbox.id, body_json: '' });

function onChange(value: JSONContent) {
    body.value = value as Record<string, unknown>;
    previewKey.value++;
}

function save() {
    form.body_json = JSON.stringify(body.value);
    form.put('/mail/signature', { preserveScroll: true });
}
</script>

<template>
    <div class="max-w-3xl space-y-4">
        <div>
            <h1 class="text-2xl font-semibold">{{ trans('mail.signature.for', { name: mailbox.display_name }) }}</h1>
            <p class="mt-1 text-sm text-text-2">{{ trans('mail.signature.intro') }}</p>
            <p v-if="!hasOwn" class="mt-1 text-sm text-text-2">{{ trans('mail.signature.default_hint') }}</p>
        </div>
        <form class="space-y-3" @submit.prevent="save">
            <MailEditor :model-value="body" :variables="variables" mode="template" signature :label="trans('mail.signature.title')" @update:model-value="onChange" />
            <p class="text-xs text-text-2">{{ trans('mail.signature.none_hint') }}</p>
            <p v-if="form.errors.body_json" class="text-sm text-danger" role="alert">{{ form.errors.body_json }}</p>
            <Button type="submit" :disabled="form.processing">{{ trans('mail.signature.save') }}</Button>
        </form>
        <section class="space-y-2">
            <h2 class="text-lg font-semibold">{{ trans('mail.signature.preview') }}</h2>
            <MailEditor :key="previewKey" :model-value="body" :variables="variables" :values="values" mode="document" read-only :label="trans('mail.signature.preview')" />
        </section>
    </div>
</template>
