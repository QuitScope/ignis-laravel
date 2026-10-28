<script setup lang="ts">
import { computed, reactive, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { initialValue, payload, type ConfigField, type FieldValue } from '@/lib/settingFields';
import { xsrfToken } from '@/lib/draftSave';
import LogoUpload from '@/components/settings/LogoUpload.vue';
import SettingField from '@/components/settings/SettingField.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';

const props = defineProps<{ tabs: string[]; tab: string; groups: Record<string, ConfigField[]>; apiKeySet: boolean; hosts: string[] }>();

const page = usePage();
const errors = computed(() => (page.props.errors ?? {}) as Record<string, string>);
const card = 'rounded-lg border border-border bg-card p-4 shadow-(--shadow)';

// ----- tabs: the active one lives in the `tab` query so a reload or a link lands on it -----
const active = ref(props.tab);
function openTab(tab: string) {
    active.value = tab;
    window.history.replaceState(window.history.state, '', `/settings/system/config?tab=${tab}`);
}

function tabKey(e: KeyboardEvent) {
    const i = props.tabs.indexOf(active.value);
    const next = { ArrowRight: (i + 1) % props.tabs.length, ArrowLeft: (i - 1 + props.tabs.length) % props.tabs.length, Home: 0, End: props.tabs.length - 1 }[e.key];
    if (next === undefined) return;
    e.preventDefault();
    openTab(props.tabs[next]);
    document.getElementById(`tab-${props.tabs[next]}`)?.focus();
}

// ----- one form per group, saved on its own -----
const values = reactive<Record<string, FieldValue>>({});
for (const fields of Object.values(props.groups)) for (const f of fields) values[f.key] = initialValue(f);
const saving = ref<string | null>(null);

function save(group: string) {
    router.put(`/settings/system/config/${group}`, payload(props.groups[group], values) as Record<string, never>, {
        preserveScroll: true,
        preserveState: true,
        onStart: () => (saving.value = group),
        onFinish: () => (saving.value = null),
        onSuccess: () => {
            for (const f of props.groups[group]) if (f.secret || f.deletable_only) values[f.key] = initialValue(f);
        },
    });
}

// ----- API key: fetched on click, never part of the page -----
const key = ref<string | null>(null);
const keyShown = ref(false);
const keySet = ref(props.apiKeySet);
const keyBusy = ref(false);
const keyError = ref(false);
const copied = ref(false);
const confirmOpen = ref(false);

async function keyRequest(method: 'GET' | 'POST') {
    keyBusy.value = true;
    keyError.value = false;
    try {
        const res = await fetch('/settings/system/api-key', {
            method,
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrfToken() },
        });
        if (!res.ok) throw new Error(String(res.status));
        key.value = ((await res.json()) as { key: string | null }).key;
        keySet.value = key.value !== null;
        keyShown.value = key.value !== null;
    } catch {
        keyError.value = true;
    } finally {
        keyBusy.value = false;
    }
}

function hideKey() {
    key.value = null;
    keyShown.value = false;
    copied.value = false;
}

async function copyKey() {
    if (!key.value) return;
    try {
        await navigator.clipboard.writeText(key.value);
        copied.value = true;
    } catch {
        keyError.value = true;
    }
}

async function regenerate() {
    confirmOpen.value = false;
    await keyRequest('POST');
}
</script>

<template>
    <div class="space-y-4">
        <div>
            <h1 class="text-2xl font-semibold">{{ trans('settings.system.title') }}</h1>
            <p class="mt-1 text-sm text-text-2">{{ trans('settings.system.lead') }}</p>
        </div>

        <div role="tablist" :aria-label="trans('settings.system.title')" class="flex flex-wrap gap-1 border-b border-border">
            <button
                v-for="t in tabs"
                :id="`tab-${t}`"
                :key="t"
                type="button"
                role="tab"
                :aria-selected="active === t"
                :tabindex="active === t ? 0 : -1"
                :aria-controls="`panel-${t}`"
                class="-mb-px border-b-2 px-3 py-2 text-sm"
                :class="active === t ? 'border-accent font-medium text-text' : 'border-transparent text-text-2 hover:text-text'"
                @click="openTab(t)"
                @keydown="tabKey"
            >
                {{ trans('settings.tab.' + t) }}
            </button>
        </div>

        <section v-for="t in tabs" v-show="active === t" :id="`panel-${t}`" :key="t" role="tabpanel" :aria-labelledby="`tab-${t}`" class="max-w-2xl space-y-4">
            <p class="text-sm text-text-2">{{ trans('settings.tab.' + t + '.lead') }}</p>
            <p v-if="t === 'privacy'" class="rounded-md bg-(--info-soft) px-3 py-2 text-sm" role="note">{{ trans('announcements.privacy', { hosts: hosts.join(', ') }) }}</p>

            <form v-if="groups[t].length" :class="[card, 'space-y-4']" @submit.prevent="save(t)">
                <SettingField v-for="f in groups[t]" :key="f.key" v-model="values[f.key]" :field="f" :error="errors[f.key]" />
                <Button type="submit" :disabled="saving === t">{{ trans('lookup.save') }}</Button>
            </form>

            <LogoUpload v-if="t === 'general'" />

            <div v-if="t === 'interfaces'" role="region" :class="[card, 'space-y-3']" aria-labelledby="api-key-title">
                <h2 id="api-key-title" class="text-lg font-medium">{{ trans('settings.api.key.label') }}</h2>
                <p class="text-sm text-text-2">{{ trans('settings.api.key.hint') }}</p>
                <p class="text-sm">{{ trans(keySet ? 'settings.secret.set' : 'settings.secret.unset') }}</p>
                <code v-if="keyShown && key" class="block break-all rounded-md bg-(--fill-2) px-3 py-2 font-mono text-sm" data-testid="api-key">{{ key }}</code>
                <div class="flex flex-wrap gap-2">
                    <Button v-if="!keyShown" type="button" variant="outline" :disabled="keyBusy || !keySet" @click="keyRequest('GET')">{{ trans('settings.api.show') }}</Button>
                    <template v-else>
                        <Button type="button" variant="outline" @click="copyKey">{{ trans(copied ? 'settings.api.copied' : 'settings.api.copy') }}</Button>
                        <Button type="button" variant="ghost" @click="hideKey">{{ trans('settings.api.hide') }}</Button>
                    </template>
                    <Button type="button" variant="destructive" :disabled="keyBusy" @click="confirmOpen = true">{{ trans('settings.api.regenerate') }}</Button>
                </div>
                <p v-if="keyError" class="text-sm text-danger" role="alert">{{ trans('settings.api.failed') }}</p>
            </div>
        </section>

        <Dialog v-model:open="confirmOpen">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{{ trans('settings.api.regenerate') }}</DialogTitle>
                    <DialogDescription>{{ trans('settings.api.confirm') }}</DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button type="button" variant="ghost" @click="confirmOpen = false">{{ trans('dashboard.cancel') }}</Button>
                    <Button type="button" variant="destructive" @click="regenerate">{{ trans('settings.api.regenerate') }}</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
