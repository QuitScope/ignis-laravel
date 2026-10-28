<script setup lang="ts">
import { computed, ref } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

export type LookupField = { key: string; type: 'text' | 'number' | 'checkbox'; required?: boolean; max?: number };
type Value = string | number | boolean | null;
export type LookupRow = { id: number; used: number } & Record<string, Value>;

const props = defineProps<{
    title: string;
    lead: string;
    path: string;
    rows: LookupRow[];
    fields: LookupField[];
    can: { manage: boolean };
}>();

const page = usePage<{ errors: Record<string, string> }>();

const blank = (): Record<string, string | number | boolean> =>
    Object.fromEntries(props.fields.map((f) => [f.key, f.type === 'checkbox' ? false : f.type === 'number' ? 0 : '']));
const form = useForm<Record<string, string | number | boolean>>(blank());
const editing = ref<LookupRow | null>(null);
const open = ref(false);
const deleting = ref<LookupRow | null>(null);

function openForm(row: LookupRow | null) {
    editing.value = row;
    form.clearErrors();
    const values = blank();
    if (row) for (const f of props.fields) values[f.key] = row[f.key] ?? values[f.key];
    form.defaults(values).reset();
    open.value = true;
}

function save() {
    const options = { preserveScroll: true, onSuccess: () => (open.value = false) };
    if (editing.value) form.put(`${props.path}/${editing.value.id}`, options);
    else form.post(props.path, options);
}

const deleteError = computed(() => page.props.errors?.delete);
const nameOf = (row: LookupRow | null) => String(row?.name ?? row?.number ?? '');
function destroy() {
    if (!deleting.value) return;
    router.delete(`${props.path}/${deleting.value.id}`, { preserveScroll: true, onFinish: () => (deleting.value = null) });
}
const cell = (row: LookupRow, f: LookupField) =>
    f.type === 'checkbox' ? (row[f.key] ? trans('lookup.yes') : trans('lookup.no')) : (row[f.key] ?? '');
</script>

<template>
    <div class="space-y-4">
        <div class="flex items-start justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold">{{ trans(title) }}</h1>
                <p class="mt-1 text-sm text-text-2">{{ trans(lead) }}</p>
            </div>
            <Button v-if="can.manage" @click="openForm(null)">{{ trans('lookup.create') }}</Button>
        </div>

        <p v-if="deleteError" class="rounded-md bg-(--danger-soft) px-3 py-2 text-sm text-danger" role="alert">
            {{ deleteError }}
        </p>

        <div class="overflow-x-auto rounded-lg border border-border bg-card shadow-(--shadow)">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-border text-text-2">
                    <tr>
                        <th v-for="f in fields" :key="f.key" class="px-3 py-2 font-medium">{{ trans('lookup.field.' + f.key) }}</th>
                        <th class="px-3 py-2 font-medium">{{ trans('lookup.col.used') }}</th>
                        <th v-if="can.manage" class="px-3 py-2" />
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in rows" :key="row.id" class="border-b border-border last:border-0">
                        <td v-for="f in fields" :key="f.key" class="px-3 py-2" :class="{ 'tabular-nums': f.type === 'number' }">
                            {{ cell(row, f) }}
                        </td>
                        <td class="px-3 py-2 tabular-nums">{{ row.used }}</td>
                        <td v-if="can.manage" class="whitespace-nowrap px-3 py-2 text-right">
                            <Button variant="ghost" size="sm" :aria-label="trans('lookup.edit_row', { name: nameOf(row) })" @click="openForm(row)">{{ trans('lookup.edit') }}</Button>
                            <Button variant="ghost" size="sm" :aria-label="trans('lookup.delete_row', { name: nameOf(row) })" @click="deleting = row">{{ trans('lookup.delete') }}</Button>
                        </td>
                    </tr>
                    <tr v-if="!rows.length">
                        <td :colspan="fields.length + 2" class="px-3 py-6 text-center text-text-2">{{ trans('lookup.empty') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Dialog v-model:open="open">
            <DialogContent class="max-h-[90vh] overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>{{ editing ? trans('lookup.edit') : trans('lookup.create') }}</DialogTitle>
                    <DialogDescription>{{ trans('lookup.form_hint') }}</DialogDescription>
                </DialogHeader>
                <form class="space-y-4" @submit.prevent="save">
                    <div v-for="f in fields" :key="f.key" class="space-y-1">
                        <div v-if="f.type === 'checkbox'" class="flex items-center gap-2">
                            <Checkbox
                                :id="'lookup-' + f.key"
                                :model-value="form[f.key] === true"
                                @update:model-value="(v) => (form[f.key] = v === true)"
                            />
                            <Label :for="'lookup-' + f.key" class="font-normal">{{ trans('lookup.field.' + f.key) }}</Label>
                        </div>
                        <template v-else>
                            <Label :for="'lookup-' + f.key">
                                {{ trans('lookup.field.' + f.key) }}<span v-if="f.required" aria-hidden="true"> *</span>
                            </Label>
                            <Input
                                :id="'lookup-' + f.key"
                                v-model="form[f.key] as string | number"
                                :type="f.type"
                                :required="f.required"
                                :maxlength="f.max"
                                :min="f.type === 'number' ? 0 : undefined"
                                :max="f.type === 'number' ? 9999 : undefined"
                                :aria-invalid="!!form.errors[f.key]"
                            />
                        </template>
                        <p v-if="form.errors[f.key]" class="text-sm text-danger">{{ form.errors[f.key] }}</p>
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="ghost" @click="open = false">{{ trans('lookup.cancel') }}</Button>
                        <Button type="submit" :disabled="form.processing">{{ trans('lookup.save') }}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog :open="deleting !== null" @update:open="(v) => !v && (deleting = null)">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{{ trans('lookup.delete') }}</DialogTitle>
                    <DialogDescription class="text-foreground">{{ trans('lookup.delete_confirm', { name: nameOf(deleting) }) }}</DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button variant="ghost" @click="deleting = null">{{ trans('lookup.cancel') }}</Button>
                    <Button variant="destructive" @click="destroy">{{ trans('lookup.delete') }}</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
