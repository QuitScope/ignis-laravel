<script setup lang="ts">
import { ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Row = {
    id: number;
    label: string | null;
    url: string;
    personnel: string | null;
    status: 'unused' | 'used' | 'expired';
    used_by: string | null;
    created_by: string | null;
    expires_at: string | null;
};

defineProps<{ codes: Row[]; freePersonnel: { id: number; full_name: string; service_number: string }[] }>();

const chip: Record<Row['status'], string> = {
    unused: 'bg-(--ok-soft) text-ok',
    used: 'bg-(--fill-3) text-text-2',
    expired: 'bg-(--danger-soft) text-danger',
};

const form = useForm({ label: '', expires_in_days: 14 as number | string, personnel_id: null as number | null });
function create() {
    form.post('/users/invitations', { preserveScroll: true, onSuccess: () => form.reset('label', 'personnel_id') });
}

const copied = ref<number | null>(null);
async function copy(row: Row) {
    try {
        await navigator.clipboard.writeText(row.url);
        copied.value = row.id;
    } catch {
        copied.value = null;
    }
}
function destroy(row: Row) {
    router.delete(`/users/invitations/${row.id}`, { preserveScroll: true });
}
</script>

<template>
    <div class="space-y-4">
        <div>
            <h1 class="text-2xl font-semibold">{{ trans('invitations.title') }}</h1>
            <p class="mt-1 text-sm text-text-2">{{ trans('invitations.lead') }}</p>
        </div>

        <form
            class="flex flex-wrap items-end gap-3 rounded-lg border border-border bg-card p-4 shadow-(--shadow)"
            @submit.prevent="create"
        >
            <div class="space-y-1">
                <Label for="label">{{ trans('invitations.field.label') }}</Label>
                <Input id="label" v-model="form.label" maxlength="255" class="w-64" />
                <p v-if="form.errors.label" class="text-sm text-danger">{{ form.errors.label }}</p>
            </div>
            <div class="space-y-1">
                <Label for="days">{{ trans('invitations.field.days') }}</Label>
                <Input id="days" v-model.number="form.expires_in_days" type="number" min="1" max="365" class="w-28" />
                <p v-if="form.errors.expires_in_days" class="text-sm text-danger">{{ form.errors.expires_in_days }}</p>
            </div>
            <div class="space-y-1">
                <Label for="personnel_id">{{ trans('invitations.field.personnel') }}</Label>
                <select id="personnel_id" v-model="form.personnel_id" class="h-9 w-64 rounded-md border border-border bg-card px-3 text-sm">
                    <option :value="null">{{ trans('invitations.no_personnel') }}</option>
                    <option v-for="p in freePersonnel" :key="p.id" :value="p.id">{{ p.full_name }} ({{ p.service_number }})</option>
                </select>
                <p v-if="form.errors.personnel_id" class="text-sm text-danger">{{ form.errors.personnel_id }}</p>
            </div>
            <Button type="submit" :disabled="form.processing">{{ trans('invitations.create') }}</Button>
        </form>

        <div class="overflow-x-auto rounded-lg border border-border bg-card shadow-(--shadow)">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-border text-text-2">
                    <tr>
                        <th class="px-3 py-2 font-medium">{{ trans('invitations.col.label') }}</th>
                        <th class="px-3 py-2 font-medium">{{ trans('invitations.col.personnel') }}</th>
                        <th class="px-3 py-2 font-medium">{{ trans('invitations.col.link') }}</th>
                        <th class="px-3 py-2 font-medium">{{ trans('invitations.col.status') }}</th>
                        <th class="px-3 py-2 font-medium">{{ trans('invitations.col.created_by') }}</th>
                        <th class="px-3 py-2 font-medium">{{ trans('invitations.col.expires') }}</th>
                        <th class="px-3 py-2" />
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="c in codes" :key="c.id" class="border-b border-border last:border-0">
                        <td class="px-3 py-2">{{ c.label ?? '–' }}</td>
                        <td class="px-3 py-2">{{ c.personnel ?? '–' }}</td>
                        <td class="max-w-xs px-3 py-2">
                            <code class="block truncate text-xs">{{ c.url }}</code>
                        </td>
                        <td class="px-3 py-2">
                            <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="chip[c.status]">
                                {{
                                    c.status === 'used'
                                        ? trans('invitations.status.used', { name: c.used_by ?? '–' })
                                        : trans(`invitations.status.${c.status}`)
                                }}
                            </span>
                        </td>
                        <td class="px-3 py-2">{{ c.created_by ?? '–' }}</td>
                        <td class="px-3 py-2 tabular-nums">{{ c.expires_at ?? trans('invitations.never') }}</td>
                        <td class="whitespace-nowrap px-3 py-2 text-right">
                            <template v-if="c.status !== 'used'">
                                <Button v-if="c.status === 'unused'" variant="ghost" size="sm" @click="copy(c)">
                                    {{ trans(copied === c.id ? 'invitations.copied' : 'invitations.copy') }}
                                </Button>
                                <Button variant="ghost" size="sm" @click="destroy(c)">
                                    {{ trans('users.delete') }}
                                </Button>
                            </template>
                        </td>
                    </tr>
                    <tr v-if="!codes.length">
                        <td colspan="7" class="px-3 py-6 text-center text-text-2">{{ trans('invitations.empty') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
