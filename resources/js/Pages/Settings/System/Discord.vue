<script setup lang="ts">
import { computed, reactive, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';

type Bot = { enabled: boolean; connected: boolean; tokenLost: boolean; id: string; name: string; avatarUrl: string | null; inviteUrl: string | null; dmKinds: string[] };

const props = defineProps<{ bot: Bot; kinds: { key: string; label: string }[]; reach: { users: number; reachable: number; optedOut: number }; ownDiscordId: boolean; webhooks: { kind: string; label: string; set: boolean }[] }>();

const page = usePage();
const errors = computed(() => (page.props.errors ?? {}) as Record<string, string>);
const kindsError = computed(() => Object.entries(errors.value).find(([k]) => k === 'dm_kinds' || k.startsWith('dm_kinds.'))?.[1]);
const card = 'rounded-lg border border-border bg-card p-4 shadow-(--shadow)';

// The token is write-only: never prefilled, blank keeps the stored one. Kinds of a switched-off module are not sent.
const form = reactive({
    token: '',
    enabled: props.bot.enabled,
    dm_kinds: props.bot.dmKinds.filter((k) => props.kinds.some((kind) => kind.key === k)),
});
// Webhook URLs are write-only too: the field is never prefilled, empty keeps the stored one.
const webhookUrls = reactive<Record<string, string>>(Object.fromEntries(props.webhooks.map((w) => [w.kind, ''])));
const webhookError = computed(() => errors.value.webhook_url ?? errors.value.webhook_test);
const busy = ref(false);
const confirmOpen = ref(false);

const options = { preserveScroll: true, onStart: () => (busy.value = true), onFinish: () => (busy.value = false) };

function toggleKind(key: string, on: boolean) {
    form.dm_kinds = on ? [...form.dm_kinds, key] : form.dm_kinds.filter((k) => k !== key);
}

function save() {
    router.put('/settings/system/discord', { ...form }, { ...options, onSuccess: () => (form.token = '') });
}

function disconnect() {
    confirmOpen.value = false;
    router.delete('/settings/system/discord', options);
}

function saveWebhook(kind: string) {
    router.put(`/settings/system/discord/webhooks/${kind}`, { webhook_url: webhookUrls[kind] }, { ...options, onSuccess: () => (webhookUrls[kind] = '') });
}

function removeWebhook(kind: string) {
    router.delete(`/settings/system/discord/webhooks/${kind}`, options);
}

function testWebhook(kind: string) {
    router.post(`/settings/system/discord/webhooks/${kind}/test`, {}, options);
}

function sendTest() {
    router.post('/settings/system/discord/test', {}, options);
}
</script>

<template>
    <div class="space-y-4">
        <div>
            <h1 class="text-2xl font-semibold">{{ trans('settings.discord.title') }}</h1>
            <p class="mt-1 text-sm text-text-2">{{ trans('settings.discord.lead') }}</p>
        </div>

        <div class="max-w-2xl space-y-4">
            <section :class="[card, 'space-y-3']" aria-labelledby="discord-status">
                <h2 id="discord-status" class="text-lg font-medium">{{ trans('settings.discord.status.title') }}</h2>
                <p v-if="bot.tokenLost" class="rounded-md bg-(--danger-soft) px-3 py-2 text-sm" role="alert">{{ trans('settings.discord.token_lost') }}</p>
                <div class="flex items-center gap-3">
                    <img v-if="bot.connected && bot.avatarUrl" :src="bot.avatarUrl" :alt="trans('settings.discord.bot.avatar.label')" class="size-12 rounded-full" width="48" height="48" />
                    <div class="text-sm">
                        <p class="font-medium">
                            {{ trans(bot.connected ? 'settings.discord.status.connected' : 'settings.discord.status.not_connected') }}
                            · {{ trans(bot.enabled ? 'settings.discord.status.on' : 'settings.discord.status.off') }}
                        </p>
                        <p v-if="bot.connected && bot.name">{{ bot.name }}</p>
                        <p v-if="bot.connected && bot.id" class="text-text-2">{{ trans('settings.discord.bot.id.label') }}: <code>{{ bot.id }}</code></p>
                    </div>
                </div>
                <template v-if="bot.connected && bot.inviteUrl">
                    <a :href="bot.inviteUrl" target="_blank" rel="noopener noreferrer" class="text-sm text-accent underline">{{ trans('settings.discord.invite') }}</a>
                    <p class="text-sm text-text-2">{{ trans('settings.discord.invite_hint') }}</p>
                </template>
                <Button v-if="bot.connected || bot.tokenLost" type="button" variant="destructive" :disabled="busy" @click="confirmOpen = true">{{ trans('settings.discord.disconnect') }}</Button>
            </section>

            <form :class="[card, 'space-y-4']" @submit.prevent="save">
                <h2 class="text-lg font-medium">{{ trans('settings.discord.form.title') }}</h2>
                <div class="space-y-1">
                    <Label for="discord-token">{{ trans('settings.discord.bot.token.label') }}</Label>
                    <Input
                        id="discord-token"
                        v-model="form.token"
                        type="password"
                        autocomplete="new-password"
                        :placeholder="trans(bot.connected ? 'settings.secret.set' : 'settings.secret.unset')"
                        aria-describedby="discord-token-hint"
                        :aria-invalid="errors.token ? true : undefined"
                    />
                    <p id="discord-token-hint" class="text-sm text-text-2">{{ trans('settings.discord.bot.token.hint') }}</p>
                    <p v-if="errors.token" class="text-sm text-danger" role="alert">{{ errors.token }}</p>
                </div>
                <div class="space-y-1">
                    <div class="flex items-center gap-2">
                        <Checkbox id="discord-enabled" :model-value="form.enabled" @update:model-value="(v) => (form.enabled = v === true)" />
                        <Label for="discord-enabled" class="font-normal">{{ trans('settings.discord.bot.enabled.label') }}</Label>
                    </div>
                    <p v-if="errors.enabled" class="text-sm text-danger" role="alert">{{ errors.enabled }}</p>
                </div>
                <fieldset class="space-y-2" aria-describedby="discord-kinds-hint">
                    <legend class="text-sm font-medium">{{ trans('settings.discord.dm_kinds.label') }}</legend>
                    <div v-for="k in kinds" :key="k.key" class="flex items-center gap-2">
                        <Checkbox :id="`discord-kind-${k.key}`" :model-value="form.dm_kinds.includes(k.key)" @update:model-value="(v) => toggleKind(k.key, v === true)" />
                        <Label :for="`discord-kind-${k.key}`" class="font-normal">{{ k.label }}</Label>
                    </div>
                    <p id="discord-kinds-hint" class="text-sm text-text-2">{{ trans('settings.discord.dm_kinds.hint') }}</p>
                    <p v-if="kindsError" class="text-sm text-danger" role="alert">{{ kindsError }}</p>
                </fieldset>
                <Button type="submit" :disabled="busy">{{ trans('lookup.save') }}</Button>
            </form>

            <section :class="[card, 'space-y-3']" aria-labelledby="discord-reach">
                <h2 id="discord-reach" class="text-lg font-medium">{{ trans('settings.discord.reach.title') }}</h2>
                <dl class="grid grid-cols-3 gap-3 text-sm">
                    <div>
                        <dt class="text-text-2">{{ trans('settings.discord.reach.users') }}</dt>
                        <dd class="text-xl font-semibold">{{ reach.users }}</dd>
                    </div>
                    <div>
                        <dt class="text-text-2">{{ trans('settings.discord.reach.reachable') }}</dt>
                        <dd class="text-xl font-semibold">{{ reach.reachable }}</dd>
                    </div>
                    <div>
                        <dt class="text-text-2">{{ trans('settings.discord.reach.opted_out') }}</dt>
                        <dd class="text-xl font-semibold">{{ reach.optedOut }}</dd>
                    </div>
                </dl>
            </section>

            <section :class="[card, 'space-y-3']" aria-labelledby="discord-test">
                <h2 id="discord-test" class="text-lg font-medium">{{ trans('settings.discord.test.title') }}</h2>
                <p class="text-sm text-text-2">{{ trans(ownDiscordId ? 'settings.discord.test.hint' : 'settings.discord.test.no_id') }}</p>
                <p v-if="errors.test" class="text-sm text-danger" role="alert">{{ errors.test }}</p>
                <Button type="button" variant="outline" :disabled="busy || !bot.connected || !ownDiscordId" @click="sendTest">{{ trans('settings.discord.test.send') }}</Button>
            </section>

            <section :class="[card, 'space-y-4']" aria-labelledby="discord-webhooks">
                <div>
                    <h2 id="discord-webhooks" class="text-lg font-medium">{{ trans('settings.discord.webhooks.title') }}</h2>
                    <p class="mt-1 text-sm text-text-2">{{ trans('settings.discord.webhooks.lead') }}</p>
                </div>
                <p v-if="webhookError" class="text-sm text-danger" role="alert">{{ webhookError }}</p>
                <form v-for="w in webhooks" :key="w.kind" class="space-y-2" @submit.prevent="saveWebhook(w.kind)">
                    <Label :for="`discord-webhook-${w.kind}`">{{ w.label }} · {{ trans(w.set ? 'settings.secret.set' : 'settings.secret.unset') }}</Label>
                    <Input
                        :id="`discord-webhook-${w.kind}`"
                        v-model="webhookUrls[w.kind]"
                        type="password"
                        autocomplete="off"
                        :placeholder="trans(w.set ? 'settings.secret.set' : 'settings.secret.unset')"
                        :aria-label="`${trans('settings.discord.webhooks.url.label')}: ${w.label}`"
                    />
                    <div class="flex flex-wrap gap-2">
                        <Button type="submit" :disabled="busy">{{ trans('settings.discord.webhooks.save') }}</Button>
                        <Button type="button" variant="outline" :disabled="busy || !w.set" @click="testWebhook(w.kind)">{{ trans('settings.discord.webhooks.test') }}</Button>
                        <Button type="button" variant="ghost" :disabled="busy || !w.set" @click="removeWebhook(w.kind)">{{ trans('settings.discord.webhooks.remove') }}</Button>
                    </div>
                </form>
                <p class="text-sm text-text-2">{{ trans('settings.discord.webhooks.url.hint') }}</p>
            </section>
        </div>

        <Dialog v-model:open="confirmOpen">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{{ trans('settings.discord.disconnect') }}</DialogTitle>
                    <DialogDescription>{{ trans('settings.discord.disconnect_confirm') }}</DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button type="button" variant="ghost" @click="confirmOpen = false">{{ trans('lookup.cancel') }}</Button>
                    <Button type="button" variant="destructive" @click="disconnect">{{ trans('settings.discord.disconnect') }}</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
