<script setup lang="ts">
import { computed, ref } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type RoleRow = {
    id: number;
    name: string;
    priority: number;
    color: string;
    is_default: boolean;
    users_count: number;
    permissions: string[];
};

defineProps<{
    roles: RoleRow[];
    permissionGroups: Record<string, string[]>;
    can: { manage: boolean };
}>();

const page = usePage<{ errors: Record<string, string> }>();

const colors = ['primary', 'secondary', 'success', 'danger', 'warning', 'info'];
const chip: Record<string, string> = {
    primary: 'bg-(--accent-soft) text-accent',
    success: 'bg-(--ok-soft) text-ok',
    danger: 'bg-(--danger-soft) text-danger',
    warning: 'bg-(--warn-soft) text-warn',
    info: 'bg-(--info-soft) text-info',
    secondary: 'bg-(--fill-3) text-text-2',
};

const editing = ref<RoleRow | null>(null);
const open = ref(false);
const form = useForm({ name: '', priority: 500, color: 'secondary', permissions: [] as string[] });

function openForm(role: RoleRow | null) {
    editing.value = role;
    form.clearErrors();
    form.name = role?.name ?? '';
    form.priority = role?.priority ?? 500;
    form.color = role?.color ?? 'secondary';
    form.permissions = role ? [...role.permissions] : [];
    open.value = true;
}

function save() {
    const options = { preserveScroll: true, onSuccess: () => (open.value = false) };
    if (editing.value) form.put(`/users/roles/${editing.value.id}`, options);
    else form.post('/users/roles', options);
}

function toggle(name: string, on: boolean | 'indeterminate') {
    form.permissions = on === true ? [...form.permissions, name] : form.permissions.filter((p) => p !== name);
}

const deleting = ref<RoleRow | null>(null);
const permLabel = (name: string) => {
    const label = trans('permissions.' + name);
    return label === 'permissions.' + name ? name : label;
};
const permissionsError = computed(() => Object.entries(form.errors).find(([k]) => k.startsWith('permissions'))?.[1]);
const deleteError = computed(() => page.props.errors?.role);
function destroy() {
    if (!deleting.value) return;
    router.delete(`/users/roles/${deleting.value.id}`, { preserveScroll: true, onFinish: () => (deleting.value = null) });
}
</script>

<template>
    <div class="space-y-4">
        <div class="flex items-start justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold">{{ trans('roles.title') }}</h1>
                <p class="mt-1 text-sm text-text-2">{{ trans('roles.lead') }}</p>
            </div>
            <Button v-if="can.manage" @click="openForm(null)">{{ trans('roles.create') }}</Button>
        </div>

        <p v-if="deleteError" class="rounded-md bg-(--danger-soft) px-3 py-2 text-sm text-danger" role="alert">
            {{ deleteError }}
        </p>

        <div class="overflow-x-auto rounded-lg border border-border bg-card shadow-(--shadow)">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-border text-text-2">
                    <tr>
                        <th class="px-3 py-2 font-medium">{{ trans('roles.col.priority') }}</th>
                        <th class="px-3 py-2 font-medium">{{ trans('roles.col.name') }}</th>
                        <th class="px-3 py-2 font-medium">{{ trans('roles.col.users') }}</th>
                        <th class="px-3 py-2 font-medium">{{ trans('roles.col.permissions') }}</th>
                        <th v-if="can.manage" class="px-3 py-2" />
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="role in roles" :key="role.id" class="border-b border-border last:border-0">
                        <td class="px-3 py-2 tabular-nums">{{ role.priority }}</td>
                        <td class="px-3 py-2">
                            <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="chip[role.color] ?? chip.secondary">
                                {{ role.name }}
                            </span>
                            <span v-if="role.is_default" class="ml-2 text-xs text-text-2">{{ trans('roles.default') }}</span>
                        </td>
                        <td class="px-3 py-2 tabular-nums">{{ role.users_count }}</td>
                        <td class="px-3 py-2">
                            <div class="flex flex-wrap gap-1">
                                <span
                                    v-for="p in role.permissions"
                                    :key="p"
                                    class="rounded bg-(--fill-3) px-1.5 py-0.5 text-xs text-text-2"
                                >
                                    {{ permLabel(p) }}
                                </span>
                                <span v-if="!role.permissions.length" class="text-xs text-text-2">{{ trans('roles.none') }}</span>
                            </div>
                        </td>
                        <td v-if="can.manage" class="whitespace-nowrap px-3 py-2 text-right">
                            <Button variant="ghost" size="sm" @click="openForm(role)">{{ trans('roles.edit') }}</Button>
                            <Button variant="ghost" size="sm" @click="deleting = role">{{ trans('roles.delete') }}</Button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Dialog v-model:open="open">
            <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>{{ editing ? trans('roles.edit') : trans('roles.create') }}</DialogTitle>
                    <DialogDescription>{{ trans('roles.form_hint') }}</DialogDescription>
                </DialogHeader>
                <form class="space-y-4" @submit.prevent="save">
                    <div class="space-y-1">
                        <Label for="role-name">{{ trans('roles.field.name') }}</Label>
                        <Input id="role-name" v-model="form.name" required maxlength="255" />
                        <p v-if="form.errors.name" class="text-sm text-danger">{{ form.errors.name }}</p>
                    </div>
                    <div class="space-y-1">
                        <Label for="role-priority">{{ trans('roles.field.priority') }}</Label>
                        <Input id="role-priority" v-model.number="form.priority" type="number" min="1" max="999" required />
                        <p v-if="form.errors.priority" class="text-sm text-danger">{{ form.errors.priority }}</p>
                    </div>
                    <fieldset class="space-y-1">
                        <legend class="text-sm font-medium">{{ trans('roles.field.color') }}</legend>
                        <div class="flex flex-wrap gap-2">
                            <label v-for="c in colors" :key="c" class="cursor-pointer">
                                <input v-model="form.color" type="radio" name="color" :value="c" class="peer sr-only" />
                                <span
                                    class="inline-block rounded-full px-3 py-1 text-xs font-medium ring-offset-2 ring-offset-bg peer-checked:ring-2 peer-checked:ring-accent peer-focus-visible:ring-2"
                                    :class="chip[c]"
                                >
                                    {{ trans('roles.color.' + c) }}
                                </span>
                            </label>
                        </div>
                        <p v-if="form.errors.color" class="text-sm text-danger">{{ form.errors.color }}</p>
                    </fieldset>
                    <fieldset class="space-y-3">
                        <legend class="text-sm font-medium">{{ trans('roles.field.permissions') }}</legend>
                        <div v-for="(names, group) in permissionGroups" :key="group">
                            <h3 class="mb-1 text-xs font-medium uppercase tracking-wide text-text-2">
                                {{ trans('permissions.group.' + group) }}
                            </h3>
                            <div class="grid gap-1 sm:grid-cols-2">
                                <div v-for="name in names" :key="name" class="flex items-center gap-2">
                                    <Checkbox
                                        :id="'perm-' + name"
                                        :model-value="form.permissions.includes(name)"
                                        @update:model-value="(v) => toggle(name, v)"
                                    />
                                    <Label :for="'perm-' + name" class="font-normal">{{ permLabel(name) }}</Label>
                                </div>
                            </div>
                        </div>
                        <p v-if="permissionsError" class="text-sm text-danger">{{ permissionsError }}</p>
                    </fieldset>
                    <DialogFooter>
                        <Button type="button" variant="ghost" @click="open = false">{{ trans('roles.cancel') }}</Button>
                        <Button type="submit" :disabled="form.processing">{{ trans('roles.save') }}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog :open="deleting !== null" @update:open="(v) => !v && (deleting = null)">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{{ trans('roles.delete') }}</DialogTitle>
                </DialogHeader>
                <DialogDescription class="text-foreground">{{ trans('roles.delete_confirm', { name: deleting?.name ?? '' }) }}</DialogDescription>
                <DialogFooter>
                    <Button variant="ghost" @click="deleting = null">{{ trans('roles.cancel') }}</Button>
                    <Button variant="destructive" @click="destroy">{{ trans('roles.delete') }}</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
