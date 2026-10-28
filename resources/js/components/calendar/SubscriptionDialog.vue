<script setup lang="ts">
import { ref, watch } from 'vue';
import { trans } from 'laravel-vue-i18n';
import { Check, Copy } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { calendarRequest } from '@/lib/calendarApi';

// The personal iCal link. The server shows it once, in the answer to the POST; it lives only in this component's
// state until the dialog closes (never in props, the URL or storage).
const props = defineProps<{ open: boolean }>();
const emit = defineEmits<{ close: [] }>();

const exists = ref<boolean | null>(null);
const url = ref('');
const replaced = ref(false);
const revoked = ref(false);
const copied = ref(false);
const copyFailed = ref(false);
const error = ref('');
const busy = ref(false);

watch(
    () => props.open,
    async (open) => {
        url.value = '';
        replaced.value = revoked.value = copied.value = copyFailed.value = false;
        error.value = '';
        exists.value = null;
        if (!open) return;
        const res = await calendarRequest<{ has_subscription: boolean }>('GET', '/calendar/subscription');
        if (res.ok) exists.value = res.data.has_subscription;
        else error.value = res.message;
    },
    { immediate: true },
);

async function generate() {
    if (busy.value) return;
    busy.value = true;
    error.value = '';
    const had = exists.value === true;
    const res = await calendarRequest<{ url: string }>('POST', '/calendar/subscription');
    busy.value = false;
    if (!res.ok) {
        error.value = res.message;

        return;
    }
    url.value = res.data.url;
    exists.value = true;
    replaced.value = had;
    revoked.value = copied.value = copyFailed.value = false;
}

async function revoke() {
    if (busy.value) return;
    busy.value = true;
    error.value = '';
    const res = await calendarRequest('DELETE', '/calendar/subscription');
    busy.value = false;
    if (!res.ok) {
        error.value = res.message;

        return;
    }
    url.value = '';
    exists.value = false;
    revoked.value = true;
}

async function copy() {
    try {
        await navigator.clipboard.writeText(url.value);
        copied.value = true;
        copyFailed.value = false;
    } catch {
        copyFailed.value = true;
    }
}
</script>

<template>
    <Dialog :open="open" @update:open="(v) => !v && emit('close')">
        <DialogContent class="sm:max-w-lg" data-test="subscription-dialog">
            <DialogHeader>
                <DialogTitle>{{ trans('calendar.subscription.title') }}</DialogTitle>
                <DialogDescription>{{ trans('calendar.subscription.description') }}</DialogDescription>
            </DialogHeader>

            <div class="space-y-3 text-sm">
                <p role="status" data-test="subscription-state">
                    <template v-if="exists === null && !error">{{ trans('calendar.subscription.loading') }}</template>
                    <template v-else-if="exists !== null">{{ trans(exists ? 'calendar.subscription.active' : 'calendar.subscription.none') }}</template>
                </p>

                <p v-if="error" class="rounded-md bg-(--danger-soft) px-3 py-2 text-danger" role="alert">{{ error }}</p>
                <p v-if="revoked" class="rounded-md bg-(--fill-2) px-3 py-2" role="status">{{ trans('calendar.subscription.revoked') }}</p>

                <div v-if="url" class="space-y-2" data-test="subscription-url">
                    <label for="calendar-subscription-url" class="font-medium">{{ trans('calendar.subscription.url_label') }}</label>
                    <div class="flex gap-2">
                        <Input id="calendar-subscription-url" :model-value="url" readonly class="font-mono text-xs" @focus="($event.target as HTMLInputElement).select()" />
                        <Button variant="outline" :aria-label="trans(copied ? 'calendar.subscription.copied' : 'calendar.subscription.copy')" data-test="subscription-copy" @click="copy">
                            <Check v-if="copied" aria-hidden="true" /><Copy v-else aria-hidden="true" />
                        </Button>
                    </div>
                    <p class="text-text-2">{{ trans('calendar.subscription.once') }}</p>
                    <p v-if="replaced" class="rounded-md bg-(--danger-soft) px-3 py-2" role="alert">{{ trans('calendar.subscription.replaced') }}</p>
                    <p v-if="copyFailed" role="alert">{{ trans('calendar.subscription.copy_failed') }}</p>
                    <p v-if="copied" class="sr-only" role="status">{{ trans('calendar.subscription.copied') }}</p>
                </div>

                <p v-else-if="exists" class="text-text-2">{{ trans('calendar.subscription.regenerate_hint') }}</p>
            </div>

            <DialogFooter>
                <Button variant="ghost" @click="emit('close')">{{ trans('calendar.close') }}</Button>
                <Button v-if="exists" variant="outline" :disabled="busy" data-test="subscription-revoke" @click="revoke">{{ trans('calendar.subscription.revoke') }}</Button>
                <Button :disabled="busy || exists === null" data-test="subscription-generate" @click="generate">{{ trans(exists ? 'calendar.subscription.regenerate' : 'calendar.subscription.create') }}</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
