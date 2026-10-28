<script setup lang="ts">
import { computed } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { Button } from '@/components/ui/button';

type Row = { id: number; name: string; category: string | null; is_active: boolean; documents: number };

defineProps<{ templates: Row[] }>();

const BASE = '/settings/documents/editor-templates';
const deleteError = computed(() => (usePage().props.errors as Record<string, string>)?.delete);

const toggle = (t: Row) => router.post(`${BASE}/${t.id}/${t.is_active ? 'deactivate' : 'reactivate'}`, {}, { preserveScroll: true });
const destroy = (t: Row) => {
    if (confirm(trans('documents.template.delete_confirm', { name: t.name }))) router.delete(`${BASE}/${t.id}`, { preserveScroll: true });
};
</script>

<template>
    <div class="space-y-4">
        <div class="flex items-start justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold">{{ trans('documents.template.title') }}</h1>
                <p class="mt-1 text-sm text-text-2">{{ trans('documents.template.lead') }}</p>
            </div>
            <Button as-child>
                <Link :href="`${BASE}/create`">{{ trans('documents.template.create') }}</Link>
            </Button>
        </div>

        <p v-if="deleteError" class="rounded-md bg-(--danger-soft) px-3 py-2 text-sm text-danger" role="alert">{{ deleteError }}</p>

        <div class="overflow-x-auto rounded-lg border border-border bg-card shadow-(--shadow)">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-border text-text-2">
                    <tr>
                        <th class="px-3 py-2 font-medium">{{ trans('documents.template.col.name') }}</th>
                        <th class="px-3 py-2 font-medium">{{ trans('documents.template.col.category') }}</th>
                        <th class="px-3 py-2 font-medium">{{ trans('documents.template.col.status') }}</th>
                        <th class="px-3 py-2 font-medium">{{ trans('documents.template.col.documents') }}</th>
                        <th class="px-3 py-2" />
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="t in templates" :key="t.id" class="border-b border-border last:border-0">
                        <td class="px-3 py-2">{{ t.name }}</td>
                        <td class="px-3 py-2">{{ t.category ?? '' }}</td>
                        <td class="px-3 py-2">{{ t.is_active ? trans('documents.template.active') : trans('documents.template.inactive') }}</td>
                        <td class="px-3 py-2 tabular-nums">{{ t.documents }}</td>
                        <td class="whitespace-nowrap px-3 py-2 text-right">
                            <Button as-child variant="ghost" size="sm">
                                <Link :href="`${BASE}/${t.id}`" :aria-label="`${trans('documents.template.edit')}: ${t.name}`">{{ trans('documents.template.edit') }}</Link>
                            </Button>
                            <Button variant="ghost" size="sm" @click="toggle(t)">
                                {{ t.is_active ? trans('documents.template.deactivate') : trans('documents.template.reactivate') }}
                            </Button>
                            <Button
                                variant="ghost"
                                size="sm"
                                :disabled="t.documents > 0"
                                :title="t.documents > 0 ? trans('documents.template.delete_blocked') : undefined"
                                @click="destroy(t)"
                            >
                                {{ trans('documents.template.delete') }}
                            </Button>
                        </td>
                    </tr>
                    <tr v-if="!templates.length">
                        <td colspan="5" class="px-3 py-6 text-center text-text-2">{{ trans('documents.template.empty') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
