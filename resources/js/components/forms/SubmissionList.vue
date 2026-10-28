<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import Pagination from '@/components/Pagination.vue';
import StatusBadge from '@/components/forms/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formIcon } from '@/lib/formIcons';

export type Row = { public_id: string; type: string; icon: string | null; applicant_name: string; status: string; submitted_at: string | null; decided_by_name: string | null; href: string };
export type Paginated = { data: Row[]; from: number | null; to: number | null; total: number; prev_page_url: string | null; next_page_url: string | null };

// The list of the admin page (`admin`: links to the processing page) and of "Meine Anträge"; a plain GET form filters.
const props = defineProps<{ action: string; submissions: Paginated; filters: { status: string; q: string }; statuses: string[]; admin: boolean }>();

const href = (r: Row) => (props.admin ? `/forms/admin/${r.public_id}` : r.href);
const date = (iso: string | null) => (iso ? new Date(iso).toLocaleDateString('de-DE') : '–');
const select = 'h-9 rounded-md border border-border bg-card px-3 text-sm';
</script>

<template>
    <div class="space-y-4">
        <form method="get" :action="action" class="flex flex-wrap gap-2" role="search">
            <select name="status" :value="filters.status" :class="select" :aria-label="trans('forms.admin.col.status')">
                <option value="">{{ trans('forms.admin.all') }}</option>
                <option value="open">{{ trans('forms.status.open') }}</option>
                <option v-for="s in statuses" :key="s" :value="s">{{ trans(`forms.status.${s}`) }}</option>
            </select>
            <Input name="q" type="search" :default-value="filters.q" maxlength="100" :placeholder="trans('forms.admin.search')" :aria-label="trans('forms.admin.search')" class="max-w-xs" />
            <Button type="submit" variant="secondary">{{ trans('forms.admin.submit') }}</Button>
            <Button v-if="filters.q || filters.status" as-child variant="ghost">
                <Link :href="action">{{ trans('forms.admin.reset') }}</Link>
            </Button>
        </form>

        <div class="overflow-x-auto rounded-lg border border-border bg-card shadow-(--shadow)">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-border text-text-2">
                    <tr>
                        <th class="px-3 py-2 font-medium">{{ trans('forms.admin.col.id') }}</th>
                        <th class="px-3 py-2 font-medium">{{ trans('forms.admin.col.type') }}</th>
                        <th v-if="admin" class="px-3 py-2 font-medium">{{ trans('forms.admin.col.applicant') }}</th>
                        <th class="px-3 py-2 font-medium">{{ trans('forms.admin.col.status') }}</th>
                        <th class="px-3 py-2 font-medium">{{ trans('forms.admin.col.submitted') }}</th>
                        <th class="px-3 py-2 font-medium">{{ trans('forms.admin.col.decided_by') }}</th>
                        <th class="px-3 py-2"><span class="sr-only">{{ trans('forms.admin.open') }}</span></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="r in submissions.data" :key="r.public_id" class="border-b border-border last:border-0">
                        <td class="px-3 py-2 tabular-nums">{{ r.public_id }}</td>
                        <td class="px-3 py-2">
                            <span class="flex items-center gap-2">
                                <component :is="formIcon(r.icon)" class="size-4 shrink-0 text-text-2" aria-hidden="true" />
                                {{ r.type }}
                            </span>
                        </td>
                        <td v-if="admin" class="px-3 py-2">{{ r.applicant_name }}</td>
                        <td class="px-3 py-2"><StatusBadge :status="r.status" /></td>
                        <td class="px-3 py-2 tabular-nums">{{ date(r.submitted_at) }}</td>
                        <td class="px-3 py-2">{{ r.decided_by_name ?? '–' }}</td>
                        <td class="px-3 py-2 text-right">
                            <Link :href="href(r)" class="underline">{{ trans('forms.admin.open') }}</Link>
                        </td>
                    </tr>
                    <tr v-if="submissions.data.length === 0">
                        <td :colspan="admin ? 7 : 6" class="px-3 py-6 text-center text-text-2"><slot name="empty">{{ trans('forms.admin.empty') }}</slot></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Pagination :from="submissions.from" :to="submissions.to" :total="submissions.total" :prev-url="submissions.prev_page_url" :next-url="submissions.next_page_url" />
    </div>
</template>
