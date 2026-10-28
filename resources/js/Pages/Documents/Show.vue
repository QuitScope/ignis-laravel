<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { Button } from '@/components/ui/button';

const props = defineProps<{
    document: {
        id: number;
        public_id: string;
        title: string;
        issued_at: string | null;
        issuer: string | null;
        personnel: { id: number; name: string };
        pdf_url: string | null;
    };
    can: { delete: boolean; view_personnel: boolean };
}>();

const date = (iso: string | null) => (iso ? new Date(iso).toLocaleString() : '–');
const destroy = () => {
    if (confirm(trans('documents.delete_confirm', { title: props.document.title }))) {
        router.delete(`/documents/${props.document.id}`);
    }
};
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-start justify-between gap-2">
            <div>
                <h1 class="text-2xl font-semibold">{{ document.title }}</h1>
                <p class="mt-1 text-sm text-text-2">
                    {{ trans('documents.col.public_id') }} {{ document.public_id }} · {{ document.personnel.name }} ·
                    {{
                        document.issuer
                            ? trans('documents.issued_by', { date: date(document.issued_at), name: document.issuer })
                            : trans('documents.issued_on', { date: date(document.issued_at) })
                    }}
                </p>
            </div>
            <div class="flex gap-2">
                <Button v-if="can.view_personnel" as-child variant="ghost">
                    <Link :href="`/personnel/${document.personnel.id}?tab=documents`">{{ trans('documents.back') }}</Link>
                </Button>
                <Button v-if="document.pdf_url" as-child variant="secondary">
                    <a :href="document.pdf_url" target="_blank" rel="noopener">{{ trans('documents.pdf_open') }}</a>
                </Button>
                <Button v-if="can.delete" variant="destructive" @click="destroy">{{ trans('documents.delete') }}</Button>
            </div>
        </div>

        <iframe
            v-if="document.pdf_url"
            :src="document.pdf_url"
            :title="document.title"
            class="h-[80vh] w-full rounded-lg border border-border bg-card"
        />
        <p v-else class="rounded-lg border border-border bg-card p-4 text-sm text-text-2">{{ trans('documents.pdf_missing') }}</p>
    </div>
</template>
