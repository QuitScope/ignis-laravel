<script setup lang="ts">
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { settingsSend } from '@/lib/settingsFetch';

type FormType = { id: number; name: string; description: string | null; icon: string | null; is_active: boolean; is_absence: boolean; fields_count: number; submissions_count: number };

const props = defineProps<{ types: FormType[] }>();

const messages = { session: 'forms.error.session', save: 'forms.error.save' };
const error = ref<string | null>(null);

async function toggle(t: FormType) {
    const r = await settingsSend('PATCH', `/settings/forms/${t.id}/active`, { is_active: !t.is_active }, messages, ['types']);
    error.value = r.ok ? null : r.message;
}

async function move(index: number, by: -1 | 1) {
    const ids = props.types.map((t) => t.id);
    const other = index + by;
    if (other < 0 || other >= ids.length) return;
    [ids[index], ids[other]] = [ids[other], ids[index]];
    const r = await settingsSend('PUT', '/settings/forms/order', { ids }, messages, ['types']);
    error.value = r.ok ? null : r.message;
}

const deleting = ref<FormType | null>(null);
const deleteError = ref<string | null>(null);
async function destroy() {
    if (deleting.value === null) return;
    const r = await settingsSend('DELETE', `/settings/forms/${deleting.value.id}`, undefined, messages, ['types']);
    deleteError.value = r.ok ? null : r.message;
    if (r.ok) deleting.value = null;
}
</script>

<template>
    <div class="space-y-4">
        <div class="flex items-center justify-between gap-3">
            <h1 class="text-2xl font-semibold">{{ trans('forms.settings.title') }}</h1>
            <Button @click="router.visit('/settings/forms/create')">{{ trans('forms.settings.new') }}</Button>
        </div>
        <p v-if="error" class="text-sm text-destructive" role="alert">{{ error }}</p>
        <ul class="divide-y divide-border rounded-lg border border-border bg-card shadow-(--shadow)" data-test="types">
            <li v-for="(t, i) in types" :key="t.id" class="flex flex-wrap items-center gap-2 px-3 py-2">
                <span class="font-medium" :class="{ 'text-text-2': !t.is_active }">{{ t.name }}</span>
                <span v-if="t.is_absence" class="rounded-full bg-(--fill-3) px-2 py-0.5 text-xs text-text-2">{{ trans('forms.settings.absence') }}</span>
                <span v-if="!t.is_active" class="rounded-full bg-(--fill-3) px-2 py-0.5 text-xs text-text-2">{{ trans('forms.settings.inactive') }}</span>
                <span class="text-xs text-text-2">
                    {{ trans('forms.settings.fields_count', { count: String(t.fields_count) }) }} ·
                    {{ trans('forms.settings.submissions_count', { count: String(t.submissions_count) }) }}
                </span>
                <span class="ml-auto flex flex-wrap items-center gap-1 whitespace-nowrap">
                    <label class="mr-2 flex items-center gap-1 text-sm">
                        <input type="checkbox" :checked="t.is_active" @change="toggle(t)" />
                        {{ trans('forms.settings.active') }}
                    </label>
                    <Button variant="ghost" size="sm" :disabled="i === 0" :aria-label="trans('forms.settings.up')" @click="move(i, -1)">↑</Button>
                    <Button variant="ghost" size="sm" :disabled="i === types.length - 1" :aria-label="trans('forms.settings.down')" @click="move(i, 1)">↓</Button>
                    <Button variant="ghost" size="sm" as-child>
                        <Link :href="`/settings/forms/${t.id}/edit`">{{ trans('forms.settings.edit') }}</Link>
                    </Button>
                    <Button variant="ghost" size="sm" @click="((deleteError = null), (deleting = t))">{{ trans('forms.settings.delete') }}</Button>
                </span>
            </li>
            <li v-if="types.length === 0" class="px-3 py-6 text-center text-sm text-text-2">{{ trans('forms.settings.empty') }}</li>
        </ul>

        <Dialog :open="deleting !== null" @update:open="(v) => !v && (deleting = null)">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{{ trans('forms.settings.delete_title') }}</DialogTitle>
                </DialogHeader>
                <DialogDescription v-if="deleting">{{ trans('forms.settings.delete_confirm', { name: deleting.name, fields: String(deleting.fields_count) }) }}</DialogDescription>
                <p v-if="deleteError" class="text-sm text-destructive" role="alert">{{ deleteError }}</p>
                <DialogFooter>
                    <Button variant="ghost" @click="deleting = null">{{ trans('forms.edit.cancel') }}</Button>
                    <Button variant="destructive" @click="destroy">{{ trans('forms.settings.delete') }}</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
