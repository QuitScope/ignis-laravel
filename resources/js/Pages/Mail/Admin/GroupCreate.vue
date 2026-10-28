<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

/** A new group mailbox: name and address by hand, members afterwards on its edit page. */
const props = defineProps<{ defaultDomain: string; domains: string[]; canChooseDomain: boolean }>();

const form = useForm({ name: '', local: '', domain: props.defaultDomain });
const error = (key: string) => (form.errors as Record<string, string | undefined>)[key];
</script>

<template>
    <form class="mx-auto w-full max-w-2xl space-y-5" @submit.prevent="form.post('/settings/mail/mailboxes/groups')">
        <div>
            <h1 class="text-2xl font-semibold">{{ trans('mail.admin.new_group') }}</h1>
            <p class="mt-1 text-sm text-text-2">{{ trans('mail.admin.group_intro') }}</p>
        </div>
        <div>
            <label class="block text-sm font-medium" for="group-name">{{ trans('mail.admin.name') }}</label>
            <Input id="group-name" v-model="form.name" required maxlength="150" class="mt-1" />
            <p v-if="error('name')" class="mt-1 text-sm text-danger" role="alert">{{ error('name') }}</p>
        </div>
        <div>
            <label class="block text-sm font-medium" for="group-local">{{ trans('mail.admin.address') }}</label>
            <div class="mt-1 flex items-center gap-2">
                <Input id="group-local" v-model="form.local" required maxlength="64" autocomplete="off" />
                <span aria-hidden="true">@</span>
                <select v-if="canChooseDomain" v-model="form.domain" class="h-9 rounded-md border border-border bg-card px-3 text-sm" :aria-label="trans('mail.admin.domain')">
                    <option v-for="d in domains" :key="d" :value="d">{{ d }}</option>
                </select>
                <span v-else class="text-sm">{{ defaultDomain }}</span>
            </div>
            <p v-if="error('local')" class="mt-1 text-sm text-danger" role="alert">{{ error('local') }}</p>
        </div>
        <div class="flex gap-2">
            <Button type="submit" :disabled="form.processing">{{ trans('mail.admin.create') }}</Button>
            <Button as-child variant="ghost"><Link href="/settings/mail/mailboxes">{{ trans('mail.lists.cancel') }}</Link></Button>
        </div>
    </form>
</template>
