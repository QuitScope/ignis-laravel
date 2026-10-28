<script setup lang="ts">
/** Clinic code entry (no account): opens the one protocol the code was made for, read only, until the code expires. */
import { useForm } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import GuestLayout from '@/Layouts/GuestLayout.vue';

defineOptions({ layout: GuestLayout });

const form = useForm({ code: '' });
const submit = () => form.post('/enotf/schnittstelle/klinikcode', { onFinish: () => form.reset('code') });
</script>

<template>
    <div class="mx-auto max-w-md space-y-4">
        <h1 class="text-2xl font-semibold">{{ trans('enotf.clinic_code.enter_title') }}</h1>
        <p class="text-text-2">{{ trans('enotf.clinic_code.enter_lead') }}</p>
        <form class="space-y-3" @submit.prevent="submit">
            <label for="clinic-code" class="text-sm">{{ trans('enotf.clinic_code.code') }}</label>
            <input
                id="clinic-code"
                v-model="form.code"
                required
                maxlength="64"
                autocomplete="off"
                autocapitalize="characters"
                class="min-h-11 w-full rounded-md border border-border bg-bg px-2 font-mono"
                :aria-invalid="form.errors.code ? 'true' : undefined"
                aria-describedby="clinic-code-error"
            />
            <p id="clinic-code-error" role="alert" class="text-sm text-danger">{{ form.errors.code ?? '' }}</p>
            <button type="submit" class="min-h-11 rounded-md bg-accent px-4 text-white" :disabled="form.processing">{{ trans('enotf.clinic_code.open') }}</button>
        </form>
    </div>
</template>
