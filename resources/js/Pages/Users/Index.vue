<script setup lang="ts">
import { ref, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import HoverCard from '@/components/personnel/HoverCard.vue';
import Pagination from '@/components/Pagination.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';

type Row = {
    id: number;
    username: string;
    name: string | null;
    role: { name: string; color: string } | null;
    is_active: boolean;
    created_at: string | null;
    can: { update: boolean; delete: boolean; toggle: boolean };
};

const props = defineProps<{
    users: {
        data: Row[];
        from: number | null;
        to: number | null;
        total: number;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    filters: { search: string; status: '' | 'active' | 'inactive' };
}>();

const chip: Record<string, string> = {
    primary: 'bg-(--accent-soft) text-accent',
    success: 'bg-(--ok-soft) text-ok',
    danger: 'bg-(--danger-soft) text-danger',
    warning: 'bg-(--warn-soft) text-warn',
    info: 'bg-(--info-soft) text-info',
    secondary: 'bg-(--fill-3) text-text-2',
};

const search = ref(props.filters.search);
const status = ref(props.filters.status);

function load() {
    router.get(
        '/users',
        { search: search.value || undefined, status: status.value || undefined },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}
let timer: ReturnType<typeof setTimeout> | undefined;
watch(search, () => {
    clearTimeout(timer);
    timer = setTimeout(load, 300);
});
watch(status, load);

const deleting = ref<Row | null>(null);
function toggle(u: Row) {
    router.post(`/users/${u.id}/toggle-active`, {}, { preserveScroll: true });
}
function destroy() {
    if (!deleting.value) return;
    router.delete(`/users/${deleting.value.id}`, { preserveScroll: true, onFinish: () => (deleting.value = null) });
}
</script>

<template>
    <div class="space-y-4">
        <div>
            <h1 class="text-2xl font-semibold">{{ trans('users.title') }}</h1>
            <p class="mt-1 text-sm text-text-2">{{ trans('users.lead') }}</p>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <Input
                v-model="search"
                type="search"
                class="max-w-xs"
                :placeholder="trans('users.search_placeholder')"
                :aria-label="trans('users.search')"
            />
            <select
                v-model="status"
                class="h-9 rounded-md border border-border bg-card px-3 text-sm"
                :aria-label="trans('users.col.status')"
            >
                <option value="">{{ trans('users.filter.all') }}</option>
                <option value="active">{{ trans('users.filter.active') }}</option>
                <option value="inactive">{{ trans('users.filter.inactive') }}</option>
            </select>
        </div>

        <div class="overflow-x-auto rounded-lg border border-border bg-card shadow-(--shadow)">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-border text-text-2">
                    <tr>
                        <th class="px-3 py-2 font-medium">{{ trans('users.col.username') }}</th>
                        <th class="px-3 py-2 font-medium">{{ trans('users.col.name') }}</th>
                        <th class="px-3 py-2 font-medium">{{ trans('users.col.role') }}</th>
                        <th class="px-3 py-2 font-medium">{{ trans('users.col.status') }}</th>
                        <th class="px-3 py-2 font-medium">{{ trans('users.col.created') }}</th>
                        <th class="px-3 py-2" />
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="u in users.data" :key="u.id" class="border-b border-border last:border-0">
                        <td class="px-3 py-2 font-medium"><HoverCard :url="`/api/users/${u.id}/card`">{{ u.username }}</HoverCard></td>
                        <td class="px-3 py-2">{{ u.name ?? '–' }}</td>
                        <td class="px-3 py-2">
                            <span
                                v-if="u.role"
                                class="rounded-full px-2 py-0.5 text-xs font-medium"
                                :class="chip[u.role.color] ?? chip.secondary"
                            >
                                {{ u.role.name }}
                            </span>
                            <span v-else class="text-xs text-text-2">{{ trans('users.no_role') }}</span>
                        </td>
                        <td class="px-3 py-2">
                            <span
                                class="rounded-full px-2 py-0.5 text-xs font-medium"
                                :class="u.is_active ? chip.success : chip.danger"
                            >
                                {{ trans(u.is_active ? 'users.status.active' : 'users.status.inactive') }}
                            </span>
                        </td>
                        <td class="px-3 py-2 tabular-nums">{{ u.created_at }}</td>
                        <td class="whitespace-nowrap px-3 py-2 text-right">
                            <Button v-if="u.can.update" as-child variant="ghost" size="sm">
                                <Link :href="`/users/${u.id}/edit`">{{ trans('users.edit') }}</Link>
                            </Button>
                            <Button v-if="u.can.toggle" variant="ghost" size="sm" @click="toggle(u)">
                                {{ trans(u.is_active ? 'users.deactivate' : 'users.reactivate') }}
                            </Button>
                            <Button v-if="u.can.delete" variant="ghost" size="sm" @click="deleting = u">
                                {{ trans('users.delete') }}
                            </Button>
                        </td>
                    </tr>
                    <tr v-if="!users.data.length">
                        <td colspan="6" class="px-3 py-6 text-center text-text-2">{{ trans('users.empty') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Pagination
            :from="users.from"
            :to="users.to"
            :total="users.total"
            :prev-url="users.prev_page_url"
            :next-url="users.next_page_url"
        />

        <Dialog :open="deleting !== null" @update:open="(v) => !v && (deleting = null)">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{{ trans('users.delete') }}</DialogTitle>
                </DialogHeader>
                <p class="text-sm">{{ trans('users.delete_confirm', { name: deleting?.username ?? '' }) }}</p>
                <DialogFooter>
                    <Button variant="ghost" @click="deleting = null">{{ trans('users.cancel') }}</Button>
                    <Button variant="destructive" @click="destroy">{{ trans('users.delete') }}</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
