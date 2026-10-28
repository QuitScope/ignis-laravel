<script setup lang="ts">
import { computed, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';

// The logo is a file, not a text setting: own upload (multipart through Inertia, which sends the XSRF token).
const page = usePage<{ branding: { name: string; logo_url: string | null } }>();
const logoUrl = computed(() => page.props.branding.logo_url);
const error = computed(() => (page.props.errors as Record<string, string> | undefined)?.logo);

const input = ref<HTMLInputElement | null>(null);
const busy = ref(false);
const confirmOpen = ref(false);

function upload() {
    const file = input.value?.files?.[0];
    if (!file) return;
    router.post('/settings/system/logo', { logo: file }, {
        forceFormData: true,
        preserveScroll: true,
        preserveState: true,
        onStart: () => (busy.value = true),
        onFinish: () => {
            busy.value = false;
            if (input.value) input.value.value = '';
        },
    });
}

function remove() {
    confirmOpen.value = false;
    router.delete('/settings/system/logo', { preserveScroll: true, preserveState: true, onStart: () => (busy.value = true), onFinish: () => (busy.value = false) });
}
</script>

<template>
    <div role="region" class="space-y-3 rounded-lg border border-border bg-card p-4 shadow-(--shadow)" aria-labelledby="logo-title">
        <h2 id="logo-title" class="text-lg font-medium">{{ trans('settings.logo.title') }}</h2>
        <p class="text-sm text-text-2">{{ trans('settings.logo.hint') }}</p>
        <img v-if="logoUrl" :src="logoUrl" :alt="page.props.branding.name" class="max-h-24 max-w-full rounded-md border border-border bg-bg-2 p-2" />
        <p v-else class="text-sm text-text-3">{{ trans('settings.logo.none') }}</p>
        <div class="flex flex-wrap items-center gap-2">
            <input
                ref="input"
                type="file"
                accept="image/png,image/jpeg,image/webp"
                :aria-label="trans('settings.logo.choose')"
                :aria-invalid="!!error"
                :aria-describedby="error ? 'logo-error' : undefined"
                class="text-sm"
            />
            <Button type="button" :disabled="busy" @click="upload">{{ trans('settings.logo.upload') }}</Button>
            <Button v-if="logoUrl" type="button" variant="destructive" :disabled="busy" @click="confirmOpen = true">{{ trans('settings.logo.remove') }}</Button>
        </div>
        <p v-if="error" id="logo-error" class="text-sm text-danger" role="alert">{{ error }}</p>

        <Dialog v-model:open="confirmOpen">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{{ trans('settings.logo.remove') }}</DialogTitle>
                    <DialogDescription>{{ trans('settings.logo.confirm') }}</DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button type="button" variant="ghost" @click="confirmOpen = false">{{ trans('dashboard.cancel') }}</Button>
                    <Button type="button" variant="destructive" @click="remove">{{ trans('settings.logo.remove') }}</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
