<script setup lang="ts">
import { ref } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import type { JSONContent } from '@tiptap/core';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import MailEditor from '@/components/mail/MailEditor.vue';
import type { Variable } from '@/editor/createEditor';

type MailSettings = { domain: string; address_pattern: string; allowed_domains: string; send_cooldown: number };

/** Mail settings (mail.admin): domains, address pattern, send pause and the default signature, each value checked. */
const props = defineProps<{
    settings: MailSettings;
    patterns: string[];
    canChooseDomain: boolean;
    signature: Record<string, unknown>;
    variables: Variable[];
    values: Record<string, string>;
}>();

const body = ref<Record<string, unknown>>(props.signature);
const previewKey = ref(0);
const form = useForm({ ...props.settings, signature: '' });

function onChange(value: JSONContent) {
    body.value = value as Record<string, unknown>;
    previewKey.value++;
}

function save() {
    form.signature = JSON.stringify(body.value);
    form.put('/settings/mail', { preserveScroll: true });
}

const error = (key: string) => (form.errors as Record<string, string | undefined>)[key];
const label = 'block text-sm font-medium';
</script>

<template>
    <form class="mx-auto w-full max-w-3xl space-y-5" @submit.prevent="save">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold">{{ trans('mail.settings.title') }}</h1>
                <p class="mt-1 text-sm text-text-2">{{ trans('mail.settings.intro') }}</p>
            </div>
            <Button as-child variant="ghost"><Link href="/settings/mail/mailboxes">{{ trans('mail.admin.title') }}</Link></Button>
        </div>

        <div>
            <label :class="label" for="mail-domain">{{ trans('settings.mail.domain.label') }}</label>
            <Input id="mail-domain" v-model="form.domain" :disabled="!canChooseDomain" maxlength="100" class="mt-1" aria-describedby="mail-domain-hint" />
            <p id="mail-domain-hint" class="mt-1 text-xs text-text-2">
                {{ trans(canChooseDomain ? 'settings.mail.domain.hint' : 'mail.settings.domain_locked') }}
            </p>
            <p v-if="error('domain')" class="mt-1 text-sm text-danger" role="alert">{{ error('domain') }}</p>
        </div>

        <div>
            <label :class="label" for="mail-pattern">{{ trans('settings.mail.address_pattern.label') }}</label>
            <select id="mail-pattern" v-model="form.address_pattern" class="mt-1 block h-9 w-full rounded-md border border-border bg-card px-3 text-sm">
                <option v-for="p in patterns" :key="p" :value="p">{{ trans(`mail.settings.pattern.${p}`) }}</option>
            </select>
            <p class="mt-1 text-xs text-text-2">{{ trans('settings.mail.address_pattern.hint') }}</p>
            <p v-if="error('address_pattern')" class="mt-1 text-sm text-danger" role="alert">{{ error('address_pattern') }}</p>
        </div>

        <div>
            <label :class="label" for="mail-allowed">{{ trans('settings.mail.allowed_domains.label') }}</label>
            <textarea id="mail-allowed" v-model="form.allowed_domains" rows="2" class="mt-1 block w-full rounded-md border border-border bg-card px-3 py-2 text-sm" aria-describedby="mail-allowed-hint" />
            <p id="mail-allowed-hint" class="mt-1 text-xs text-text-2">{{ trans('mail.settings.allowed_hint') }}</p>
            <p v-if="error('allowed_domains')" class="mt-1 text-sm text-danger" role="alert">{{ error('allowed_domains') }}</p>
        </div>

        <div>
            <label :class="label" for="mail-cooldown">{{ trans('settings.mail.send_cooldown.label') }}</label>
            <Input id="mail-cooldown" v-model="form.send_cooldown" type="number" min="0" max="3600" step="1" class="mt-1 w-32" aria-describedby="mail-cooldown-hint" />
            <p id="mail-cooldown-hint" class="mt-1 text-xs text-text-2">{{ trans('settings.mail.send_cooldown.hint') }}</p>
            <p v-if="error('send_cooldown')" class="mt-1 text-sm text-danger" role="alert">{{ error('send_cooldown') }}</p>
        </div>

        <section class="space-y-2">
            <h2 class="text-lg font-semibold">{{ trans('mail.settings.signature') }}</h2>
            <p class="text-sm text-text-2">{{ trans('mail.settings.signature_hint') }}</p>
            <MailEditor :model-value="body" :variables="variables" mode="template" signature :label="trans('mail.settings.signature')" @update:model-value="onChange" />
            <p v-if="error('signature')" class="text-sm text-danger" role="alert">{{ error('signature') }}</p>
            <h3 class="pt-2 text-sm font-medium">{{ trans('mail.signature.preview') }}</h3>
            <MailEditor :key="previewKey" :model-value="body" :variables="variables" :values="values" mode="document" read-only :label="trans('mail.signature.preview')" />
        </section>

        <Button type="submit" :disabled="form.processing">{{ trans('mail.lists.save') }}</Button>
    </form>
</template>
