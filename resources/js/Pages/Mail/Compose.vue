<script setup lang="ts">
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import ComposeForm from '@/components/mail/ComposeForm.vue';
import { Button } from '@/components/ui/button';
import { folderHref, type ComposeProps } from '@/lib/mail';

// GET /mail/compose[/{reply|reply-all|forward|draft}/{message}] (ComposeController): the form as a page.
const props = defineProps<ComposeProps>();

const unresolved = ref<string[]>([]);
function sent(missing: string[]) {
    if (missing.length === 0) return router.visit(folderHref('sent', props.mailbox.id));
    unresolved.value = missing;
}
</script>

<template>
    <div class="max-w-3xl space-y-4">
        <nav class="text-sm text-text-2" :aria-label="trans('mail.nav')">
            <Link :href="folderHref('inbox', mailbox.id)" class="hover:underline">{{ trans('mail.nav') }}</Link>
        </nav>
        <h1 class="text-2xl font-semibold">{{ trans(`mail.compose.title.${mode}`) }}</h1>
        <div v-if="unresolved.length" class="space-y-2 rounded-md border border-border p-3 text-sm" role="status">
            <p>{{ trans('mail.compose.unresolved', { addresses: unresolved.join(', ') }) }}</p>
            <Button as-child size="sm"><Link :href="folderHref('sent', mailbox.id)">{{ trans('mail.folder.sent') }}</Link></Button>
        </div>
        <ComposeForm v-else v-bind="props" @sent="sent" @discarded="router.visit(folderHref('inbox', mailbox.id))" />
    </div>
</template>
