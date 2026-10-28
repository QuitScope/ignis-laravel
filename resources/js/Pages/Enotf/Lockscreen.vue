<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { Button } from '@/components/ui/button';
import TabletLayout from '@/Layouts/TabletLayout.vue';

// PIN lock screen. Touch pad plus a focused field, so DUI keys (TabletLayout) and real keys work too.
defineOptions({ layout: TabletLayout });
defineProps<{ vehicle: string | null }>();

const form = useForm({ pin: '' });
const press = (d: string) => {
    if (form.pin.length < 8) form.pin += d;
};
const erase = () => (form.pin = form.pin.slice(0, -1));
const submit = () => {
    if (form.pin) form.post('/enotf/unlock', { onFinish: () => form.reset('pin') });
};
const signOut = () => router.post('/enotf/logout');
const digits = ['1', '2', '3', '4', '5', '6', '7', '8', '9'];
</script>

<template>
    <div class="mx-auto max-w-xs space-y-4 text-center">
        <h1 class="text-2xl font-semibold">{{ trans('enotf.pin.title') }}</h1>
        <p v-if="vehicle" class="text-text-2">{{ vehicle }}</p>
        <p>{{ trans('enotf.pin.lead') }}</p>
        <form class="space-y-3" @submit.prevent="submit">
            <label class="sr-only" for="enotf-pin">{{ trans('enotf.pin.label') }}</label>
            <input
                id="enotf-pin"
                v-model="form.pin"
                type="password"
                inputmode="numeric"
                autocomplete="off"
                maxlength="8"
                autofocus
                class="block min-h-11 w-full rounded-md border border-border bg-bg-2 px-3 py-2 text-center text-2xl tracking-widest text-text focus-visible:outline-2 focus-visible:outline-accent"
            />
            <p v-if="form.errors.pin" role="alert" class="rounded-md border border-danger bg-(--danger-soft) p-2 text-sm">{{ form.errors.pin }}</p>
            <div class="grid grid-cols-3 gap-2">
                <Button v-for="d in digits" :key="d" type="button" variant="secondary" class="min-h-14 text-xl" @click="press(d)">{{ d }}</Button>
                <Button type="button" variant="secondary" class="min-h-14" @click="erase">{{ trans('enotf.pin.delete') }}</Button>
                <Button type="button" variant="secondary" class="min-h-14 text-xl" @click="press('0')">0</Button>
                <Button type="submit" class="min-h-14" :disabled="form.processing || !form.pin">{{ trans('enotf.pin.submit') }}</Button>
            </div>
        </form>
        <Button variant="ghost" class="min-h-11" @click="signOut">{{ trans('enotf.logout') }}</Button>
    </div>
</template>
