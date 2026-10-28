<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { Button } from '@/components/ui/button';
import GuestLayout from '@/Layouts/GuestLayout.vue';

defineOptions({ layout: GuestLayout });

type Check = { ok: boolean; detail: string };

const props = defineProps<{
    requirements: Record<'php' | 'extensions' | 'storage' | 'database', Check>;
    modules: string[];
    preselected: string[];
    locales: string[];
    tokenRequired: boolean;
    redirectUri: string;
}>();

const form = useForm({
    orgName: '',
    locale: 'de',
    discordClientId: '',
    discordClientSecret: '',
    adminDiscordId: '',
    modules: [...props.preselected],
    installToken: '',
});

const describedBy = (field: keyof typeof form.errors, hint?: string) =>
    [hint, form.errors[field] ? `${field}-error` : undefined].filter(Boolean).join(' ') || undefined;

const submit = () =>
    form
        .transform((data) => (props.tokenRequired ? data : { ...data, installToken: undefined }))
        .post('/install');

const input =
    'mt-1 block w-full rounded-md border border-border bg-bg-2 px-3 py-2 text-text placeholder:text-text-3 focus-visible:outline-2 focus-visible:outline-accent aria-invalid:border-danger';
</script>

<template>
    <form class="mx-auto max-w-2xl space-y-6" novalidate @submit.prevent="submit">
        <header>
            <h1 class="text-2xl font-semibold">{{ trans('install.title') }}</h1>
            <p class="mt-1 text-text-2">{{ trans('install.intro') }}</p>
        </header>

        <p
            v-if="!tokenRequired"
            role="alert"
            class="rounded-md border border-warn bg-(--warn-soft) p-3 text-sm text-text"
        >
            {{ trans('install.token.warning') }}
        </p>

        <section class="rounded-lg border border-border bg-card p-5" aria-labelledby="sec-req">
            <h2 id="sec-req" class="text-lg font-semibold">{{ trans('install.section.requirements') }}</h2>
            <ul class="mt-3 space-y-2">
                <li v-for="(check, key) in requirements" :key="key" class="flex items-start justify-between gap-4">
                    <span>
                        {{ trans(`install.req.${key}`) }}
                        <span class="block font-mono text-xs text-text-3">{{ check.detail }}</span>
                    </span>
                    <span :class="check.ok ? 'text-ok' : 'text-danger'" class="shrink-0 font-medium">
                        {{ trans(check.ok ? 'install.req.ok' : 'install.req.failed') }}
                    </span>
                </li>
            </ul>
        </section>

        <section class="space-y-4 rounded-lg border border-border bg-card p-5" aria-labelledby="sec-org">
            <h2 id="sec-org" class="text-lg font-semibold">{{ trans('install.section.organisation') }}</h2>
            <div>
                <label for="orgName">{{ trans('install.field.orgName') }}</label>
                <input
                    id="orgName"
                    v-model="form.orgName"
                    :class="input"
                    maxlength="100"
                    required
                    autocomplete="organization"
                    :aria-invalid="!!form.errors.orgName"
                    :aria-describedby="describedBy('orgName')"
                />
                <p v-if="form.errors.orgName" id="orgName-error" class="mt-1 text-sm text-danger">
                    {{ form.errors.orgName }}
                </p>
            </div>
            <div>
                <label for="locale">{{ trans('install.field.locale') }}</label>
                <select
                    id="locale"
                    v-model="form.locale"
                    :class="input"
                    :aria-invalid="!!form.errors.locale"
                    :aria-describedby="describedBy('locale')"
                >
                    <option v-for="l in locales" :key="l" :value="l">{{ trans(`install.locale.${l}`) }}</option>
                </select>
                <p v-if="form.errors.locale" id="locale-error" class="mt-1 text-sm text-danger">
                    {{ form.errors.locale }}
                </p>
            </div>
        </section>

        <section class="space-y-4 rounded-lg border border-border bg-card p-5" aria-labelledby="sec-discord">
            <h2 id="sec-discord" class="text-lg font-semibold">{{ trans('install.section.discord') }}</h2>
            <p id="discord-hint" class="text-sm text-text-2">
                {{ trans('install.discord.hint') }}
                <code class="mt-1 block rounded-sm bg-surface-2 px-2 py-1 font-mono break-all text-text">{{
                    redirectUri
                }}</code>
            </p>
            <div>
                <label for="discordClientId">{{ trans('install.field.discordClientId') }}</label>
                <input
                    id="discordClientId"
                    v-model="form.discordClientId"
                    :class="input"
                    inputmode="numeric"
                    required
                    autocomplete="off"
                    :aria-invalid="!!form.errors.discordClientId"
                    :aria-describedby="describedBy('discordClientId', 'discord-hint')"
                />
                <p v-if="form.errors.discordClientId" id="discordClientId-error" class="mt-1 text-sm text-danger">
                    {{ form.errors.discordClientId }}
                </p>
            </div>
            <div>
                <label for="discordClientSecret">{{ trans('install.field.discordClientSecret') }}</label>
                <input
                    id="discordClientSecret"
                    v-model="form.discordClientSecret"
                    type="password"
                    :class="input"
                    required
                    autocomplete="off"
                    :aria-invalid="!!form.errors.discordClientSecret"
                    :aria-describedby="describedBy('discordClientSecret')"
                />
                <p
                    v-if="form.errors.discordClientSecret"
                    id="discordClientSecret-error"
                    class="mt-1 text-sm text-danger"
                >
                    {{ form.errors.discordClientSecret }}
                </p>
            </div>
        </section>

        <section class="space-y-4 rounded-lg border border-border bg-card p-5" aria-labelledby="sec-admin">
            <h2 id="sec-admin" class="text-lg font-semibold">{{ trans('install.section.admin') }}</h2>
            <div>
                <label for="adminDiscordId">{{ trans('install.field.adminDiscordId') }}</label>
                <input
                    id="adminDiscordId"
                    v-model="form.adminDiscordId"
                    :class="input"
                    inputmode="numeric"
                    required
                    autocomplete="off"
                    :aria-invalid="!!form.errors.adminDiscordId"
                    :aria-describedby="describedBy('adminDiscordId', 'admin-hint')"
                />
                <p id="admin-hint" class="mt-1 text-sm text-text-3">{{ trans('install.admin.hint') }}</p>
                <p v-if="form.errors.adminDiscordId" id="adminDiscordId-error" class="mt-1 text-sm text-danger">
                    {{ form.errors.adminDiscordId }}
                </p>
            </div>
        </section>

        <fieldset
            class="rounded-lg border border-border bg-card p-5"
            :aria-invalid="!!form.errors.modules"
            :aria-describedby="describedBy('modules')"
        >
            <legend class="px-1 text-lg font-semibold">{{ trans('install.section.modules') }}</legend>
            <p v-if="modules.length === 0" class="text-sm text-text-3">{{ trans('install.modules.none') }}</p>
            <label v-for="m in modules" :key="m" class="mt-2 flex items-center gap-2">
                <input v-model="form.modules" type="checkbox" :value="m" class="size-4 accent-accent" />
                {{ m }}
            </label>
            <p v-if="form.errors.modules" id="modules-error" class="mt-1 text-sm text-danger">
                {{ form.errors.modules }}
            </p>
        </fieldset>

        <section
            v-if="tokenRequired"
            class="space-y-4 rounded-lg border border-border bg-card p-5"
            aria-labelledby="sec-token"
        >
            <h2 id="sec-token" class="text-lg font-semibold">{{ trans('install.section.token') }}</h2>
            <div>
                <label for="installToken">{{ trans('install.field.installToken') }}</label>
                <input
                    id="installToken"
                    v-model="form.installToken"
                    type="password"
                    :class="input"
                    required
                    autocomplete="off"
                    :aria-invalid="!!form.errors.installToken"
                    :aria-describedby="describedBy('installToken')"
                />
                <p v-if="form.errors.installToken" id="installToken-error" class="mt-1 text-sm text-danger">
                    {{ form.errors.installToken }}
                </p>
            </div>
        </section>

        <Button type="submit" size="lg" :disabled="form.processing">{{ trans('install.submit') }}</Button>
    </form>
</template>
