<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';

export type PreviewDefect = { id: number; title: string; status: string; is_blocking: boolean; created_at: string | null };

// Row preview of the list (replaces the legacy /preview fragment): the newest open defects and the blocking count.
defineProps<{ id: number; openCount: number; blocking: number; defects: PreviewDefect[]; canManage: boolean }>();
</script>

<template>
    <div class="space-y-2 text-sm">
        <p class="text-text-2">
            {{ trans('vehicles.preview.summary', { open: String(openCount), blocking: String(blocking) }) }}
        </p>
        <ul v-if="defects.length" class="space-y-1">
            <li v-for="d in defects" :key="d.id" class="flex flex-wrap items-center gap-2">
                <span v-if="d.is_blocking" class="rounded-full bg-(--danger-soft) px-2 py-0.5 text-xs font-medium text-danger">
                    {{ trans('vehicles.preview.blocking') }}
                </span>
                <span class="font-medium">{{ d.title }}</span>
                <span class="text-xs text-text-2">{{ trans(`vehicles.defect_status.${d.status}`) }}</span>
                <span v-if="d.created_at" class="text-xs text-text-2">{{ new Date(d.created_at).toLocaleDateString() }}</span>
            </li>
        </ul>
        <p v-else class="text-text-2">{{ trans('vehicles.preview.no_defects') }}</p>
        <div class="flex gap-3">
            <Link :href="`/vehicles/${id}`" class="underline">{{ trans('vehicles.open') }}</Link>
            <Link v-if="canManage" :href="`/vehicles/${id}/edit`" class="underline">{{ trans('vehicles.edit') }}</Link>
        </div>
    </div>
</template>
