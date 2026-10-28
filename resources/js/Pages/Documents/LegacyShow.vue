<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { localDate } from '@/lib/utils';
import { Button } from '@/components/ui/button';

defineProps<{
    document: {
        public_id: string;
        type: number;
        type_label: string;
        recipient: string | null;
        issuer: string;
        issued_on: string | null;
        is_archived: boolean;
        pdf_url: string | null;
    };
    can: { manage: boolean; view_personnel: boolean; personnel_id: number | null };
}>();

const date = (iso: string | null) => (iso ? localDate(iso) : '–');
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-start justify-between gap-2">
            <div>
                <h1 class="text-2xl font-semibold">{{ document.type_label }}</h1>
                <p class="mt-1 text-sm text-text-2">{{ trans('documents.col.public_id') }} {{ document.public_id }}</p>
            </div>
            <div class="flex gap-2">
                <Button v-if="can.view_personnel" as-child variant="ghost">
                    <Link :href="`/personnel/${can.personnel_id}?tab=documents`">{{ trans('documents.back') }}</Link>
                </Button>
                <Button v-if="document.pdf_url" as-child variant="secondary">
                    <a :href="document.pdf_url" target="_blank" rel="noopener">{{ trans('documents.pdf_open') }}</a>
                </Button>
            </div>
        </div>

        <iframe v-if="document.pdf_url" :src="document.pdf_url" :title="document.public_id" class="h-[80vh] w-full rounded-lg border border-border bg-card" />
        <div v-else class="rounded-lg border border-border bg-card p-4">
            <p class="mb-3 text-sm text-text-2">{{ trans('documents.legacy_no_pdf') }}</p>
            <dl class="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                <div>
                    <dt class="text-text-2">{{ trans('documents.col.recipient') }}</dt>
                    <dd>{{ document.recipient || '–' }}</dd>
                </div>
                <div>
                    <dt class="text-text-2">{{ trans('documents.col.issuer') }}</dt>
                    <dd>{{ document.issuer }}</dd>
                </div>
                <div>
                    <dt class="text-text-2">{{ trans('documents.col.issued') }}</dt>
                    <dd>{{ date(document.issued_on) }}</dd>
                </div>
                <div v-if="document.is_archived">
                    <dt class="text-text-2">{{ trans('documents.col.archived') }}</dt>
                    <dd>{{ trans('documents.yes') }}</dd>
                </div>
            </dl>
        </div>
    </div>
</template>
