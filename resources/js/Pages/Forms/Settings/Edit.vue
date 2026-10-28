<script setup lang="ts">
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { settingsSend } from '@/lib/settingsFetch';

type TypeData = { id: number; name: string; description: string | null; icon: string | null; is_active: boolean; is_absence: boolean; sort_order: number };
type Field = {
    id: number;
    key: string;
    label: string;
    type: string;
    options: string[] | null;
    required: boolean;
    placeholder: string | null;
    hint: string | null;
    width: string;
    default_value: string | null;
    readonly: boolean;
    auto_fill: string | null;
};

const props = defineProps<{ type: TypeData | null; fields: Field[]; options: { types: string[]; widths: string[]; auto_fill: string[] } }>();

const messages = { session: 'forms.error.session', save: 'forms.error.save' };
const field = 'w-full rounded-md border border-border bg-card px-3 py-2 text-sm';

const form = ref({
    name: props.type?.name ?? '',
    description: props.type?.description ?? '',
    icon: props.type?.icon ?? '',
    is_active: props.type?.is_active ?? true,
    is_absence: props.type?.is_absence ?? false,
});
const formError = ref<string | null>(null);
async function saveType() {
    const body = { ...form.value, description: form.value.description === '' ? null : form.value.description, icon: form.value.icon === '' ? null : form.value.icon };
    const r = await settingsSend(props.type === null ? 'POST' : 'PUT', props.type === null ? '/settings/forms' : `/settings/forms/${props.type.id}`, body, messages, ['type']);
    formError.value = r.ok ? null : r.message;
    if (r.ok && props.type === null) router.visit(`/settings/forms/${r.data.id}/edit`);
}

const blank = () => ({ key: '', label: '', type: 'text', options: '', required: false, readonly: false, placeholder: '', hint: '', width: 'full', default_value: '', auto_fill: '' });
const adding = ref(blank());
const addError = ref<string | null>(null);
async function addField() {
    if (props.type === null) return;
    const a = adding.value;
    const orNull = (v: string) => (v.trim() === '' ? null : v);
    const body = {
        key: a.key,
        label: a.label,
        type: a.type,
        options: a.type === 'select' ? a.options.split('\n').map((o) => o.trim()).filter((o) => o !== '') : null,
        required: a.required,
        readonly: a.readonly,
        placeholder: orNull(a.placeholder),
        hint: orNull(a.hint),
        width: a.width,
        default_value: orNull(a.default_value),
        auto_fill: orNull(a.auto_fill),
    };
    const r = await settingsSend('POST', `/settings/forms/${props.type.id}/fields`, body, messages, ['fields']);
    addError.value = r.ok ? null : r.message;
    if (r.ok) adding.value = blank();
}

const listError = ref<string | null>(null);
async function move(index: number, by: -1 | 1) {
    if (props.type === null) return;
    const ids = props.fields.map((f) => f.id);
    const other = index + by;
    if (other < 0 || other >= ids.length) return;
    [ids[index], ids[other]] = [ids[other], ids[index]];
    const r = await settingsSend('PUT', `/settings/forms/${props.type.id}/fields/order`, { ids }, messages, ['fields']);
    listError.value = r.ok ? null : r.message;
}

const deleting = ref<Field | null>(null);
const deleteError = ref<string | null>(null);
async function destroyField() {
    if (deleting.value === null || props.type === null) return;
    const r = await settingsSend('DELETE', `/settings/forms/${props.type.id}/fields/${deleting.value.id}`, undefined, messages, ['fields']);
    deleteError.value = r.ok ? null : r.message;
    if (r.ok) deleting.value = null;
}
</script>

<template>
    <div class="space-y-8">
        <div class="space-y-1">
            <Link href="/settings/forms" class="text-sm text-text-2 hover:underline">{{ trans('forms.edit.back') }}</Link>
            <h1 class="text-2xl font-semibold">{{ type ? type.name : trans('forms.edit.title_new') }}</h1>
        </div>

        <form class="space-y-3 rounded-lg border border-border bg-card p-4 shadow-(--shadow)" @submit.prevent="saveType">
            <div class="space-y-1">
                <Label for="ft-name">{{ trans('forms.edit.name') }}</Label>
                <Input id="ft-name" v-model="form.name" maxlength="255" required />
            </div>
            <div class="space-y-1">
                <Label for="ft-description">{{ trans('forms.edit.description') }}</Label>
                <textarea id="ft-description" v-model="form.description" maxlength="5000" rows="2" :class="field" />
            </div>
            <div class="space-y-1">
                <Label for="ft-icon">{{ trans('forms.edit.icon') }}</Label>
                <Input id="ft-icon" v-model="form.icon" maxlength="50" />
            </div>
            <label class="flex items-center gap-2 text-sm"><input v-model="form.is_active" type="checkbox" /> {{ trans('forms.edit.is_active') }}</label>
            <label class="flex items-center gap-2 text-sm"><input v-model="form.is_absence" type="checkbox" /> {{ trans('forms.edit.is_absence') }}</label>
            <p v-if="formError" class="text-sm text-destructive" role="alert">{{ formError }}</p>
            <Button type="submit">{{ trans('forms.edit.save') }}</Button>
        </form>

        <section v-if="type" class="space-y-3">
            <h2 class="text-lg font-medium">{{ trans('forms.edit.fields') }}</h2>
            <p class="text-sm text-text-2">{{ trans('forms.edit.fields_hint') }}</p>
            <p v-if="listError" class="text-sm text-destructive" role="alert">{{ listError }}</p>
            <ul class="divide-y divide-border rounded-lg border border-border bg-card shadow-(--shadow)" data-test="fields">
                <li v-for="(f, i) in fields" :key="f.id" class="flex flex-wrap items-center gap-2 px-3 py-2">
                    <span class="font-medium">{{ f.label }}</span>
                    <code class="text-xs text-text-2">{{ f.key }}</code>
                    <span class="rounded-full bg-(--fill-3) px-2 py-0.5 text-xs text-text-2">{{ trans(`forms.field.type.${f.type}`) }}</span>
                    <span v-if="f.required" class="text-xs text-text-2">{{ trans('forms.field.required') }}</span>
                    <span v-if="f.readonly" class="text-xs text-text-2">{{ trans('forms.field.readonly') }}</span>
                    <span class="ml-auto whitespace-nowrap">
                        <Button variant="ghost" size="sm" :disabled="i === 0" :aria-label="trans('forms.settings.up')" @click="move(i, -1)">↑</Button>
                        <Button variant="ghost" size="sm" :disabled="i === fields.length - 1" :aria-label="trans('forms.settings.down')" @click="move(i, 1)">↓</Button>
                        <Button variant="ghost" size="sm" @click="((deleteError = null), (deleting = f))">{{ trans('forms.edit.delete_field') }}</Button>
                    </span>
                </li>
                <li v-if="fields.length === 0" class="px-3 py-6 text-center text-sm text-text-2">{{ trans('forms.edit.fields_empty') }}</li>
            </ul>

            <form class="space-y-3 rounded-lg border border-border bg-card p-4 shadow-(--shadow)" @submit.prevent="addField">
                <h3 class="font-medium">{{ trans('forms.edit.add_field') }}</h3>
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div class="space-y-1">
                        <Label for="ff-key">{{ trans('forms.field.key') }}</Label>
                        <Input id="ff-key" v-model="adding.key" maxlength="100" pattern="[a-z][a-z0-9_]*" required />
                    </div>
                    <div class="space-y-1">
                        <Label for="ff-label">{{ trans('forms.field.label') }}</Label>
                        <Input id="ff-label" v-model="adding.label" maxlength="255" required />
                    </div>
                    <div class="space-y-1">
                        <Label for="ff-type">{{ trans('forms.field.type') }}</Label>
                        <select id="ff-type" v-model="adding.type" :class="field">
                            <option v-for="t in options.types" :key="t" :value="t">{{ trans(`forms.field.type.${t}`) }}</option>
                        </select>
                    </div>
                    <div class="space-y-1">
                        <Label for="ff-width">{{ trans('forms.field.width') }}</Label>
                        <select id="ff-width" v-model="adding.width" :class="field">
                            <option v-for="w in options.widths" :key="w" :value="w">{{ trans(`forms.field.width.${w}`) }}</option>
                        </select>
                    </div>
                    <div v-if="adding.type === 'select'" class="space-y-1 sm:col-span-2">
                        <Label for="ff-options">{{ trans('forms.field.options') }}</Label>
                        <textarea id="ff-options" v-model="adding.options" rows="4" :class="field" required />
                    </div>
                    <div class="space-y-1">
                        <Label for="ff-placeholder">{{ trans('forms.field.placeholder') }}</Label>
                        <Input id="ff-placeholder" v-model="adding.placeholder" maxlength="255" />
                    </div>
                    <div class="space-y-1">
                        <Label for="ff-default">{{ trans('forms.field.default_value') }}</Label>
                        <Input id="ff-default" v-model="adding.default_value" maxlength="2000" />
                    </div>
                    <div class="space-y-1 sm:col-span-2">
                        <Label for="ff-hint">{{ trans('forms.field.hint') }}</Label>
                        <Input id="ff-hint" v-model="adding.hint" maxlength="2000" />
                    </div>
                    <div class="space-y-1">
                        <Label for="ff-auto">{{ trans('forms.field.auto_fill') }}</Label>
                        <select id="ff-auto" v-model="adding.auto_fill" :class="field">
                            <option value="">{{ trans('forms.field.auto_fill_none') }}</option>
                            <option v-for="a in options.auto_fill" :key="a" :value="a">{{ trans(`forms.field.auto_fill.${a}`) }}</option>
                        </select>
                    </div>
                    <div class="flex items-end gap-4 pb-2">
                        <label class="flex items-center gap-2 text-sm"><input v-model="adding.required" type="checkbox" /> {{ trans('forms.field.required') }}</label>
                        <label class="flex items-center gap-2 text-sm"><input v-model="adding.readonly" type="checkbox" /> {{ trans('forms.field.readonly') }}</label>
                    </div>
                </div>
                <p v-if="addError" class="text-sm text-destructive" role="alert">{{ addError }}</p>
                <Button type="submit">{{ trans('forms.edit.add_field') }}</Button>
            </form>
        </section>

        <Dialog :open="deleting !== null" @update:open="(v) => !v && (deleting = null)">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{{ trans('forms.edit.delete_field') }}</DialogTitle>
                </DialogHeader>
                <DialogDescription v-if="deleting">{{ trans('forms.edit.delete_field_confirm', { label: deleting.label, key: deleting.key }) }}</DialogDescription>
                <p v-if="deleteError" class="text-sm text-destructive" role="alert">{{ deleteError }}</p>
                <DialogFooter>
                    <Button variant="ghost" @click="deleting = null">{{ trans('forms.edit.cancel') }}</Button>
                    <Button variant="destructive" @click="destroyField">{{ trans('forms.edit.delete_field') }}</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
