<script setup lang="ts">
import { ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import Pagination from '@/components/Pagination.vue';
import { Input } from '@/components/ui/input';

type Row = {
    id: number;
    time: string | null;
    module: string | null;
    action: string;
    details: string | null;
    user: string | null;
};

const props = defineProps<{
    entries: {
        data: Row[];
        from: number | null;
        to: number | null;
        total: number;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    modules: string[];
    filters: { search: string; module: string };
}>();

const search = ref(props.filters.search);
const module = ref(props.filters.module);

function load() {
    router.get(
        '/users/audit-log',
        { search: search.value || undefined, module: module.value || undefined },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}
let timer: ReturnType<typeof setTimeout> | undefined;
watch(search, () => {
    clearTimeout(timer);
    timer = setTimeout(load, 300);
});
watch(module, load);
</script>

<template>
    <div class="space-y-4">
        <div>
            <h1 class="text-2xl font-semibold">{{ trans('audit.title') }}</h1>
            <p class="mt-1 text-sm text-text-2">{{ trans('audit.lead') }}</p>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <Input
                v-model="search"
                type="search"
                class="max-w-xs"
                :placeholder="trans('audit.search_placeholder')"
                :aria-label="trans('audit.search')"
            />
            <select
                v-model="module"
                class="h-9 rounded-md border border-border bg-card px-3 text-sm"
                :aria-label="trans('audit.module')"
            >
                <option value="">{{ trans('audit.filter.all') }}</option>
                <option v-for="m in modules" :key="m" :value="m">{{ m }}</option>
            </select>
        </div>

        <div class="overflow-x-auto rounded-lg border border-border bg-card shadow-(--shadow)">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-border text-text-2">
                    <tr>
                        <th class="px-3 py-2 font-medium">{{ trans('audit.col.time') }}</th>
                        <th class="px-3 py-2 font-medium">{{ trans('audit.col.module') }}</th>
                        <th class="px-3 py-2 font-medium">{{ trans('audit.col.action') }}</th>
                        <th class="px-3 py-2 font-medium">{{ trans('audit.col.user') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="e in entries.data" :key="e.id" class="border-b border-border last:border-0">
                        <td class="whitespace-nowrap px-3 py-2 tabular-nums">{{ e.time }}</td>
                        <td class="px-3 py-2">{{ e.module }}</td>
                        <td class="px-3 py-2">
                            <div class="font-medium">{{ e.action }}</div>
                            <div v-if="e.details" class="text-xs text-text-2">{{ e.details }}</div>
                        </td>
                        <td class="px-3 py-2">{{ e.user ?? trans('audit.system') }}</td>
                    </tr>
                    <tr v-if="!entries.data.length">
                        <td colspan="4" class="px-3 py-6 text-center text-text-2">{{ trans('audit.empty') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Pagination
            :from="entries.from"
            :to="entries.to"
            :total="entries.total"
            :prev-url="entries.prev_page_url"
            :next-url="entries.next_page_url"
        />
    </div>
</template>
