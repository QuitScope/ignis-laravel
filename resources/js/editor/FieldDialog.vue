<script setup lang="ts">
import { ref, watch } from 'vue';
import { trans } from 'laravel-vue-i18n';
import { X } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { FieldOption } from './extensions/DocField';

export type FieldResult = { label: string; required: boolean; options: FieldOption[] };

const props = defineProps<{ edit: boolean; initial: FieldResult }>();
const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{ submit: [result: FieldResult]; remove: [] }>();

const label = ref('');
const required = ref(false);
const options = ref<{ label: string; male: string; female: string }[]>([]);

watch(open, (o) => {
    if (!o) return;
    label.value = props.initial.label;
    required.value = props.initial.required;
    options.value = props.initial.options.map((x) => ({ label: x.label, male: x.male ?? '', female: x.female ?? '' }));
});

function submit() {
    emit('submit', {
        label: label.value.trim(),
        required: required.value,
        options: options.value.filter((o) => o.label.trim()).map((o) => ({
            label: o.label.trim(),
            ...(o.male.trim() ? { male: o.male.trim() } : {}),
            ...(o.female.trim() ? { female: o.female.trim() } : {}),
        })),
    });
    open.value = false;
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-2xl">
            <DialogHeader>
                <DialogTitle>{{ edit ? trans('documents.editor.field.edit_title') : trans('documents.editor.field.insert_title') }}</DialogTitle>
                <DialogDescription>{{ trans('documents.editor.field.description') }}</DialogDescription>
            </DialogHeader>
            <form class="space-y-4" @submit.prevent="submit">
                <div class="space-y-1">
                    <Label for="efe-field-label">{{ trans('documents.editor.field.label') }}</Label>
                    <Input id="efe-field-label" v-model="label" maxlength="100" />
                </div>
                <div class="flex items-center gap-2">
                    <Checkbox id="efe-field-required" v-model="required" />
                    <Label for="efe-field-required">{{ trans('documents.editor.field.required_hint') }}</Label>
                </div>
                <fieldset class="space-y-2">
                    <legend class="text-sm font-medium">{{ trans('documents.editor.field.options') }}</legend>
                    <p class="text-xs text-text-2">{{ trans('documents.editor.field.options_hint') }}</p>
                    <div v-for="(o, i) in options" :key="i" class="flex items-center gap-2">
                        <Input v-model="o.label" maxlength="100" :placeholder="trans('documents.editor.field.form_general')" :aria-label="trans('documents.editor.field.form_general')" />
                        <Input v-model="o.male" maxlength="100" :placeholder="trans('documents.editor.field.form_male')" :aria-label="trans('documents.editor.field.form_male')" />
                        <Input v-model="o.female" maxlength="100" :placeholder="trans('documents.editor.field.form_female')" :aria-label="trans('documents.editor.field.form_female')" />
                        <Button type="button" variant="ghost" size="icon" :aria-label="trans('documents.editor.field.option_remove')" @click="options.splice(i, 1)">
                            <X class="size-4" aria-hidden="true" />
                        </Button>
                    </div>
                    <Button type="button" variant="secondary" size="sm" @click="options.push({ label: '', male: '', female: '' })">{{ trans('documents.editor.field.option_add') }}</Button>
                </fieldset>
                <DialogFooter>
                    <Button type="button" variant="ghost" @click="open = false">{{ trans('documents.editor.cancel') }}</Button>
                    <Button v-if="edit" type="button" variant="destructive" @click="emit('remove'); open = false">{{ trans('documents.editor.field.remove') }}</Button>
                    <Button type="submit">{{ edit ? trans('documents.editor.apply') : trans('documents.editor.field.insert') }}</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
