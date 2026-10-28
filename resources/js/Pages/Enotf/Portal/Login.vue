<script setup lang="ts">
/** Clinic portal sign-in with the hospital's access code (no account). Plain text only. */
import { useForm } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import GuestLayout from '@/Layouts/GuestLayout.vue';

defineOptions({ layout: GuestLayout });
defineProps<{ hospitals: { id: number; name: string }[] }>();

const form = useForm({ poi_id: null as number | null, code: '' });
const submit = () => form.post('/enotf/schnittstelle/login', { onFinish: () => form.reset('code') });
</script>

<template>
    <div class="mx-auto max-w-md space-y-4">
        <h1 class="text-2xl font-semibold">{{ trans('enotf.portal.login_title') }}</h1>
        <p class="text-text-2">{{ trans('enotf.portal.login_lead') }}</p>
        <form class="space-y-3" @submit.prevent="submit">
            <div class="space-y-1">
                <label for="portal-poi" class="text-sm">{{ trans('enotf.portal.hospital') }}</label>
                <select id="portal-poi" v-model="form.poi_id" required class="min-h-11 w-full rounded-md border border-border bg-bg px-2">
                    <option :value="null" disabled>{{ trans('enotf.portal.pick_hospital') }}</option>
                    <option v-for="h in hospitals" :key="h.id" :value="h.id">{{ h.name }}</option>
                </select>
            </div>
            <div class="space-y-1">
                <label for="portal-code" class="text-sm">{{ trans('enotf.portal.code') }}</label>
                <input
                    id="portal-code"
                    v-model="form.code"
                    type="password"
                    required
                    maxlength="255"
                    autocomplete="off"
                    class="min-h-11 w-full rounded-md border border-border bg-bg px-2"
                    :aria-invalid="form.errors.code ? 'true' : undefined"
                    aria-describedby="portal-error"
                />
            </div>
            <p id="portal-error" role="alert" class="text-sm text-danger">{{ form.errors.code ?? form.errors.poi_id ?? '' }}</p>
            <button type="submit" class="min-h-11 rounded-md bg-accent px-4 text-white" :disabled="form.processing">{{ trans('enotf.portal.login') }}</button>
        </form>
        <a href="/enotf/schnittstelle/klinikcode" class="inline-flex min-h-11 items-center text-accent">{{ trans('enotf.clinic_code.enter_link') }}</a>
    </div>
</template>
