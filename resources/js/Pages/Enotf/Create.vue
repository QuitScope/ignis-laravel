<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import TabletLayout from '@/Layouts/TabletLayout.vue';
import { xsrfToken } from '@/lib/draftSave';

defineOptions({ layout: TabletLayout });

const enr = ref('');
const busy = ref(false);
const error = ref<string | null>(null);
const forceable = ref(false);
const protBy = ref<0 | 1>(0);

const send = async (type: 0 | 1, force = false) => {
    if (busy.value || enr.value === '') return;
    busy.value = true;
    protBy.value = type;
    const res = await fetch('/enotf/protocols', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-XSRF-TOKEN': xsrfToken(),
        },
        body: JSON.stringify({ enr: enr.value, prot_by: type, force }),
    }).catch(() => null);
    busy.value = false;
    const json = (await res?.json().catch(() => null)) as {
        url?: string;
        message?: string;
        forceable?: boolean;
        errors?: Record<string, string[]>;
    } | null;
    if (res?.ok && json?.url) {
        window.location.href = json.url;
        return;
    }
    forceable.value = res?.status === 409 && json?.forceable === true;
    error.value =
        res?.status === 401 || res?.status === 419
            ? trans('enotf.create.session')
            : ((json?.errors ? Object.values(json.errors)[0]?.[0] : json?.message) ?? trans('enotf.create.error'));
};
</script>

<template>
    <div class="mx-auto max-w-xl space-y-4">
        <header class="flex items-center justify-between gap-2">
            <h1 class="text-2xl font-semibold">
                {{ trans('enotf.create.title') }}
            </h1>
            <Link href="/enotf/overview" class="inline-flex min-h-11 items-center text-accent">{{ trans('enotf.create.back') }}</Link>
        </header>
        <p class="text-text-2">{{ trans('enotf.create.lead') }}</p>

        <div>
            <label class="mb-1 block text-sm text-text-2" for="enr">{{ trans('enotf.create.enr') }}</label>
            <input
                id="enr"
                v-model="enr"
                inputmode="numeric"
                maxlength="64"
                autocomplete="off"
                class="block min-h-11 w-full rounded-md border border-border bg-bg-2 px-3 py-2 font-mono text-text focus-visible:outline-2 focus-visible:outline-accent"
                @input="((enr = enr.replace(/[^0-9_]/g, '')), (error = null), (forceable = false))"
            />
        </div>

        <div class="grid gap-2 sm:grid-cols-2">
            <Button class="min-h-11" :disabled="busy || enr === ''" @click="send(0)">{{ trans('enotf.create.type_nf') }}</Button>
            <Button class="min-h-11" :disabled="busy || enr === ''" @click="send(1)">{{ trans('enotf.create.type_na') }}</Button>
        </div>

        <div v-if="error" role="alert" class="space-y-2 rounded-md border border-danger bg-(--danger-soft) p-3 text-sm">
            <p>{{ error }}</p>
            <div v-if="forceable" class="flex flex-wrap gap-2">
                <Button variant="destructive" class="min-h-11" :disabled="busy" @click="send(protBy, true)">{{ trans('enotf.create.force') }}</Button>
                <Button variant="secondary" class="min-h-11" @click="((error = null), (forceable = false))">{{ trans('enotf.create.cancel') }}</Button>
            </div>
        </div>
    </div>
</template>
