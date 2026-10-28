<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { localDate } from '@/lib/utils';
import Pagination from '@/components/Pagination.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type Row = { kind: 'legacy' | 'editor'; public_id: string; type: number | null; type_label: string | null; title: string | null; issuer: string; issued_on: string | null; url: string };

const props = defineProps<{
    documents: { data: Row[]; from: number | null; to: number | null; total: number; prev_page_url: string | null; next_page_url: string | null };
    filters: { q: string; sort: string; dir: 'asc' | 'desc' };
}>();

const sortUrl = (sort: string) => {
    const dir = props.filters.sort === sort && props.filters.dir === 'asc' ? 'desc' : 'asc';

    return `/me/documents?${new URLSearchParams({ ...(props.filters.q ? { q: props.filters.q } : {}), sort, dir })}`;
};
const heads = [
    ['typ', 'documents.col.type'],
    ['nr', 'documents.col.number'],
    ['ersteller', 'documents.col.issuer'],
    ['ausgestellt', 'documents.col.issued_on'],
] as const;
const label = (d: Row) => (d.kind === 'legacy' ? d.type_label : d.title);
</script>

<template>
    <div class="space-y-4">
        <div>
            <h1 class="text-2xl font-semibold">{{ trans('documents.mine_title') }}</h1>
            <p class="mt-1 text-sm text-text-2">{{ trans('documents.mine_intro') }}</p>
        </div>

        <form method="get" action="/me/documents" class="flex flex-wrap gap-2" role="search">
            <input type="hidden" name="sort" :value="filters.sort" />
            <input type="hidden" name="dir" :value="filters.dir" />
            <Input
                name="q"
                type="search"
                :default-value="filters.q"
                :placeholder="trans('documents.mine_search')"
                :aria-label="trans('documents.mine_search_label')"
                class="max-w-xs"
            />
            <Button type="submit" variant="secondary">{{ trans('documents.mine_submit') }}</Button>
            <Button v-if="filters.q" as-child variant="ghost">
                <Link href="/me/documents">{{ trans('documents.mine_reset') }}</Link>
            </Button>
        </form>

        <p v-if="!documents.data.length" class="text-sm text-text-2">{{ trans('documents.mine_empty') }}</p>
        <table v-else class="w-full text-sm">
            <thead class="text-left text-text-2">
                <tr>
                    <th
                        v-for="[key, text] in heads"
                        :key="key"
                        class="py-1 font-medium"
                        :aria-sort="filters.sort === key ? (filters.dir === 'asc' ? 'ascending' : 'descending') : undefined"
                    >
                        <Link :href="sortUrl(key)" preserve-scroll>{{ trans(text) }}</Link>
                    </th>
                    <th class="py-1"><span class="sr-only">{{ trans('documents.open') }}</span></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="d in documents.data" :key="`${d.kind}-${d.public_id}`" class="border-t border-border">
                    <td class="py-1">{{ label(d) }}</td>
                    <td class="py-1 tabular-nums">{{ d.public_id }}</td>
                    <td class="py-1">{{ d.issuer }}</td>
                    <td class="py-1">{{ d.issued_on ? localDate(d.issued_on) : '–' }}</td>
                    <td class="py-1 text-right">
                        <Link :href="d.url" class="underline">{{ trans('documents.open') }}</Link>
                    </td>
                </tr>
            </tbody>
        </table>
        <Pagination
            v-if="documents.total > documents.data.length"
            :from="documents.from"
            :to="documents.to"
            :total="documents.total"
            :prev-url="documents.prev_page_url"
            :next-url="documents.next_page_url"
        />
    </div>
</template>
