<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';

export type LogRow = { action: string; description: string | null; at: string | null; person: string | null; vehicle: string | null };

defineProps<{ log: LogRow[] }>();

function actionLabel(action: string): string {
    const key = `firetab.log.${action}`;
    const label = trans(key);
    return label === key ? action : label;
}
</script>

<template>
    <div class="space-y-3">
        <h2 class="text-lg font-semibold">{{ trans('firetab.log.title') }}</h2>
        <ul v-if="log.length" class="divide-y divide-border rounded-md border border-border">
            <li v-for="(l, i) in log" :key="i" class="px-4 py-2">
                <p class="text-sm text-text-2">{{ l.at }} · {{ [l.person, l.vehicle].filter(Boolean).join(' · ') }}</p>
                <p>
                    {{ actionLabel(l.action) }}<span v-if="l.description"> · {{ l.description }}</span>
                </p>
            </li>
        </ul>
        <p v-else class="text-text-2">{{ trans('firetab.log.empty') }}</p>
    </div>
</template>
