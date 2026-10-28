<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type PersonnelRef = { id: number; full_name: string; service_number: string };

const props = defineProps<{
    target: { id: number; username: string; name: string | null; role_id: number | null; rank: number | null; is_active: boolean };
    assignableRoles: { id: number; name: string; priority: number; color: string }[];
    actorRank: number;
    personnel: PersonnelRef | null;
    freePersonnel: PersonnelRef[];
}>();

const form = useForm({ role: props.target.role_id });
const save = () => form.put(`/users/${props.target.id}`, { preserveScroll: true });
const rank = (r: number | null) => (r === null ? '–' : String(r));

const linkForm = useForm({ personnel_id: null as number | null });
const link = () => linkForm.post(`/users/${props.target.id}/personnel`, { preserveScroll: true, onSuccess: () => linkForm.reset() });
const unlinkForm = useForm({ unlink: 1, personnel_id: null as number | null });
const unlink = () => {
    if (confirm(trans('account_link.unlink_confirm'))) {
        unlinkForm.post(`/users/${props.target.id}/personnel`, { preserveScroll: true });
    }
};
</script>

<template>
    <div class="max-w-xl space-y-4">
        <h1 class="text-2xl font-semibold">{{ trans('users.edit_title') }}: {{ target.username }}</h1>

        <form class="space-y-4 rounded-lg border border-border bg-card p-4 shadow-(--shadow)" @submit.prevent="save">
            <div class="space-y-1">
                <Label for="user-name">{{ trans('users.field.name') }}</Label>
                <Input id="user-name" :model-value="target.name ?? ''" readonly disabled />
                <p class="text-xs text-text-2">{{ trans('users.name_hint') }}</p>
            </div>
            <div class="space-y-1">
                <Label for="user-role">{{ trans('users.field.role') }}</Label>
                <select
                    id="user-role"
                    v-model="form.role"
                    class="h-9 w-full rounded-md border border-border bg-card px-3 text-sm"
                    :disabled="!assignableRoles.length"
                    required
                >
                    <option v-if="form.role === null" :value="null" disabled>{{ trans('users.no_role') }}</option>
                    <option v-for="r in assignableRoles" :key="r.id" :value="r.id">{{ r.name }} ({{ r.priority }})</option>
                </select>
                <p v-if="form.errors.role" class="text-sm text-danger" role="alert">{{ form.errors.role }}</p>
                <p v-if="!assignableRoles.length" class="text-sm text-text-2">{{ trans('users.no_assignable') }}</p>
                <p class="text-xs text-text-2">
                    {{ trans('users.rank_info', { rank: rank(target.rank), own: String(actorRank) }) }}
                </p>
            </div>
            <div class="flex items-center justify-between gap-2">
                <Button as-child variant="ghost">
                    <Link href="/users">{{ trans('users.back') }}</Link>
                </Button>
                <Button type="submit" :disabled="form.processing || form.role === null">{{ trans('users.save') }}</Button>
            </div>
        </form>

        <section class="space-y-3 rounded-lg border border-border bg-card p-4 shadow-(--shadow)" aria-labelledby="personnel-link">
            <h2 id="personnel-link" class="text-lg font-medium">{{ trans('account_link.title') }}</h2>
            <div v-if="personnel" class="flex flex-wrap items-center gap-2 text-sm">
                <span>{{ trans('account_link.linked_with') }}</span>
                <Link :href="`/personnel/${personnel.id}`" class="font-medium underline">{{ personnel.full_name }} ({{ personnel.service_number }})</Link>
                <Button variant="ghost" size="sm" :disabled="unlinkForm.processing" @click="unlink">{{ trans('account_link.unlink') }}</Button>
            </div>
            <form v-else-if="freePersonnel.length" class="flex flex-wrap items-end gap-2" @submit.prevent="link">
                <div class="min-w-0 flex-1 space-y-1">
                    <Label for="personnel_id">{{ trans('account_link.pick') }}</Label>
                    <select id="personnel_id" v-model="linkForm.personnel_id" class="h-9 w-full rounded-md border border-border bg-card px-3 text-sm" required>
                        <option :value="null" disabled>{{ trans('account_link.pick_placeholder') }}</option>
                        <option v-for="p in freePersonnel" :key="p.id" :value="p.id">{{ p.full_name }} ({{ p.service_number }})</option>
                    </select>
                </div>
                <Button type="submit" :disabled="linkForm.processing || linkForm.personnel_id === null">{{ trans('account_link.link') }}</Button>
            </form>
            <p v-else class="text-sm text-text-2">{{ trans('account_link.no_free') }}</p>
            <p v-if="linkForm.errors.personnel_id || unlinkForm.errors.personnel_id" class="text-sm text-danger" role="alert">{{ linkForm.errors.personnel_id || unlinkForm.errors.personnel_id }}</p>
        </section>
    </div>
</template>
