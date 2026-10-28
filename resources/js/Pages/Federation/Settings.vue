<script setup lang="ts">
import { computed, reactive, ref } from 'vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { xsrfToken } from '@/lib/draftSave';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';

const flags = ['consume_personnel', 'consume_enotf', 'consume_fire', 'provide_personnel', 'provide_enotf', 'provide_fire'] as const;
type Flag = (typeof flags)[number];
type Run = { type: string; status: string; records: number; error: string | null; at: string | null };
type FedLink = Record<Flag, boolean> & {
    id: number;
    instance_id: string;
    name: string;
    url: string;
    sync_interval_minutes: number;
    is_active: boolean;
    confirmed: boolean;
    last_sync_at: string | null;
    last_sync_status: string;
    last_sync_error: string | null;
    runs: Run[];
};

const props = defineProps<{ instance: { id: string; name: string }; links: FedLink[] }>();

const page = usePage();
const errors = computed(() => (page.props.errors ?? {}) as Record<string, string>);
const card = 'rounded-lg border border-border bg-card p-4 shadow-(--shadow)';
const when = (iso: string | null) => (iso ? new Date(iso).toLocaleString() : trans('federation.settings.never'));
const types = ['personnel', 'enotf', 'fire'] as const;
const errorText = (code: string) => {
    const t = trans(`federation.error.${code}`);
    return t === `federation.error.${code}` ? trans('federation.error.generic', { code }) : t;
};

// ----- invite: the token comes back once as JSON, never part of the page -----
const token = ref<string | null>(null);
const minutes = ref(15);
const inviteBusy = ref(false);
const inviteError = ref(false);
const copied = ref(false);

async function invite() {
    inviteBusy.value = true;
    inviteError.value = false;
    copied.value = false;
    try {
        const res = await fetch('/settings/federation/invite', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrfToken() },
        });
        if (!res.ok) throw new Error(String(res.status));
        const body = (await res.json()) as { token: string; minutes: number };
        token.value = body.token;
        minutes.value = body.minutes;
    } catch {
        inviteError.value = true;
    } finally {
        inviteBusy.value = false;
    }
}

async function copyToken() {
    if (!token.value) return;
    try {
        await navigator.clipboard.writeText(token.value);
        copied.value = true;
    } catch {
        inviteError.value = true;
    }
}

// ----- pair with a token from another instance -----
const pairForm = useForm({ token: '' });
const pair = () => pairForm.post('/settings/federation/pair', { preserveScroll: true, onSuccess: () => pairForm.reset() });

// ----- links: one editable copy per link -----
const edits = reactive<Record<number, Record<Flag, boolean> & { sync_interval_minutes: number; is_active: boolean }>>({});
for (const l of props.links) {
    edits[l.id] = { ...Object.fromEntries(flags.map((f) => [f, l[f]])), sync_interval_minutes: l.sync_interval_minutes, is_active: l.is_active } as Record<Flag, boolean> & {
        sync_interval_minutes: number;
        is_active: boolean;
    };
}
const busy = ref<number | null>(null);
const opts = (id: number) => ({ preserveScroll: true, onStart: () => (busy.value = id), onFinish: () => (busy.value = null) });
const save = (l: FedLink) => router.put(`/settings/federation/links/${l.id}`, { ...edits[l.id] }, opts(l.id));
const sync = (l: FedLink) => router.post(`/settings/federation/links/${l.id}/sync`, {}, opts(l.id));

const deleting = ref<FedLink | null>(null);
function destroy() {
    const l = deleting.value;
    deleting.value = null;
    if (l) router.delete(`/settings/federation/links/${l.id}`, opts(l.id));
}
</script>

<template>
    <div class="max-w-4xl space-y-4">
        <div>
            <h1 class="text-2xl font-semibold">{{ trans('federation.settings.title') }}</h1>
            <p class="mt-1 text-sm text-text-2">{{ trans('federation.settings.lead') }}</p>
        </div>

        <dl :class="[card, 'grid gap-2 text-sm sm:grid-cols-[12rem_1fr]']">
            <dt class="text-text-2">{{ trans('federation.settings.instance_id') }}</dt>
            <dd class="break-all font-mono">{{ instance.id || trans('federation.settings.instance_id_none') }}</dd>
            <dt class="text-text-2">{{ trans('federation.settings.instance_name') }}</dt>
            <dd>
                {{ instance.name }}
                <Link href="/settings/system/config?tab=federation" class="ml-2 text-accent">{{ trans('federation.settings.edit_name') }}</Link>
            </dd>
        </dl>

        <div class="grid gap-4 md:grid-cols-2">
            <section :class="[card, 'space-y-3']" aria-labelledby="fed-invite">
                <h2 id="fed-invite" class="text-lg font-medium">{{ trans('federation.settings.invite.title') }}</h2>
                <p class="text-sm text-text-2">{{ trans('federation.settings.invite.lead', { minutes: String(minutes) }) }}</p>
                <template v-if="token">
                    <p class="text-sm" role="note">{{ trans('federation.settings.invite.once') }}</p>
                    <code class="block break-all rounded-md bg-(--fill-2) px-3 py-2 font-mono text-xs" data-testid="federation-token">{{ token }}</code>
                    <div class="flex flex-wrap gap-2">
                        <Button type="button" variant="outline" @click="copyToken">{{ trans(copied ? 'federation.settings.invite.copied' : 'federation.settings.invite.copy') }}</Button>
                        <Button type="button" variant="ghost" @click="token = null">{{ trans('federation.settings.invite.hide') }}</Button>
                    </div>
                </template>
                <Button v-else type="button" :disabled="inviteBusy" @click="invite">{{ trans('federation.settings.invite.button') }}</Button>
                <p v-if="inviteError" class="text-sm text-danger" role="alert">{{ trans('federation.settings.invite.error') }}</p>
            </section>

            <form :class="[card, 'space-y-3']" aria-labelledby="fed-pair" @submit.prevent="pair">
                <h2 id="fed-pair" class="text-lg font-medium">{{ trans('federation.settings.pair.title') }}</h2>
                <p class="text-sm text-text-2">{{ trans('federation.settings.pair.lead') }}</p>
                <label for="fed-token" class="block text-sm text-text-2">{{ trans('federation.settings.pair.token') }}</label>
                <textarea
                    id="fed-token"
                    v-model="pairForm.token"
                    rows="3"
                    maxlength="4000"
                    autocomplete="off"
                    spellcheck="false"
                    class="block w-full rounded-md border border-border bg-bg-2 px-3 py-2 font-mono text-xs"
                />
                <p v-if="errors.token" class="text-sm text-danger" role="alert">{{ errors.token }}</p>
                <Button type="submit" :disabled="pairForm.processing || pairForm.token.trim() === ''">{{ trans('federation.settings.pair.button') }}</Button>
            </form>
        </div>

        <section class="space-y-3" aria-labelledby="fed-links">
            <h2 id="fed-links" class="text-lg font-medium">{{ trans('federation.settings.links.title') }}</h2>
            <p v-if="links.length === 0" class="text-sm text-text-2">{{ trans('federation.settings.links.empty') }}</p>

            <form v-for="l in links" :key="l.id" :class="[card, 'space-y-3']" @submit.prevent="save(l)">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h3 class="font-medium">
                        {{ l.name }}
                        <span class="ml-1 text-xs text-text-2">{{ trans(l.confirmed ? 'federation.settings.confirmed' : 'federation.settings.unconfirmed') }}</span>
                    </h3>
                    <span class="break-all text-xs text-text-2">{{ l.url }}</span>
                </div>
                <p class="text-sm">
                    {{ trans('federation.settings.last_sync') }}: {{ when(l.last_sync_at) }} ·
                    <span :class="l.last_sync_status === 'error' ? 'text-danger' : ''">{{ trans(`federation.settings.status.${l.last_sync_status}`) }}</span>
                    <span v-if="l.last_sync_error" class="text-danger"> – {{ errorText(l.last_sync_error) }}</span>
                </p>

                <div class="grid gap-3 sm:grid-cols-2">
                    <fieldset v-for="dir in ['consume', 'provide']" :key="dir" class="space-y-1">
                        <legend class="mb-1 text-sm text-text-2">{{ trans(`federation.settings.${dir}`) }}</legend>
                        <label v-for="t in types" :key="t" class="flex items-center gap-2 text-sm">
                            <input v-model="edits[l.id][`${dir}_${t}` as Flag]" type="checkbox" />
                            {{ trans(`federation.settings.type.${t}`) }}
                        </label>
                    </fieldset>
                </div>

                <div class="flex flex-wrap items-end gap-4">
                    <label class="text-sm">
                        <span class="mb-1 block text-text-2">{{ trans('federation.settings.interval') }}</span>
                        <input
                            v-model.number="edits[l.id].sync_interval_minutes"
                            type="number"
                            min="5"
                            max="1440"
                            class="h-9 w-28 rounded-md border border-border bg-bg-2 px-3"
                        />
                    </label>
                    <label class="flex items-center gap-2 text-sm"><input v-model="edits[l.id].is_active" type="checkbox" /> {{ trans('federation.settings.active') }}</label>
                </div>
                <p v-if="errors.sync_interval_minutes && busy === null" class="text-sm text-danger" role="alert">{{ errors.sync_interval_minutes }}</p>

                <details v-if="l.runs.length" class="text-sm">
                    <summary class="cursor-pointer text-text-2">{{ trans('federation.settings.runs') }}</summary>
                    <ul class="mt-1 space-y-0.5">
                        <li v-for="(r, i) in l.runs" :key="i" class="tabular-nums">
                            {{ when(r.at) }} · {{ trans(`federation.settings.type.${r.type}`) }} · {{ trans(`federation.settings.status.${r.status}`) }} · {{ r.records }}
                            <span v-if="r.error" class="text-danger"> – {{ errorText(r.error) }}</span>
                        </li>
                    </ul>
                </details>

                <div class="flex flex-wrap gap-2">
                    <Button type="submit" :disabled="busy === l.id">{{ trans('federation.settings.save') }}</Button>
                    <Button type="button" variant="outline" :disabled="busy === l.id || !l.is_active" @click="sync(l)">{{ trans('federation.settings.sync') }}</Button>
                    <Button type="button" variant="destructive" :disabled="busy === l.id" @click="deleting = l">{{ trans('federation.settings.delete') }}</Button>
                </div>
            </form>
        </section>

        <Dialog :open="deleting !== null" @update:open="(o: boolean) => (o ? null : (deleting = null))">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{{ trans('federation.settings.delete') }}</DialogTitle>
                    <DialogDescription>{{ trans('federation.settings.delete_confirm', { name: deleting?.name ?? '' }) }}</DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button type="button" variant="ghost" @click="deleting = null">{{ trans('federation.settings.cancel') }}</Button>
                    <Button type="button" variant="destructive" @click="destroy">{{ trans('federation.settings.delete') }}</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
