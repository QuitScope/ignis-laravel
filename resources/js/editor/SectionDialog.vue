<script setup lang="ts">
import { ref, watch } from 'vue';
import { trans } from 'laravel-vue-i18n';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

export type SectionResult = { title: string; hint: string; repeatable: boolean };

const props = defineProps<{ initial: SectionResult }>();
const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{ submit: [result: SectionResult] }>();

const title = ref('');
const hint = ref('');
const repeatable = ref(false);

watch(open, (o) => {
    if (!o) return;
    ({ title: title.value, hint: hint.value, repeatable: repeatable.value } = props.initial);
});

function submit() {
    emit('submit', { title: title.value.trim(), hint: hint.value.trim(), repeatable: repeatable.value });
    open.value = false;
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>{{ trans('documents.editor.section.dialog_title') }}</DialogTitle>
                <DialogDescription>{{ trans('documents.editor.section.dialog_hint') }}</DialogDescription>
            </DialogHeader>
            <form class="space-y-4" @submit.prevent="submit">
                <div class="space-y-1">
                    <Label for="efe-section-title">{{ trans('documents.editor.section.title') }}</Label>
                    <Input id="efe-section-title" v-model="title" maxlength="100" />
                </div>
                <div class="space-y-1">
                    <Label for="efe-section-hint">{{ trans('documents.editor.section.hint') }}</Label>
                    <Input id="efe-section-hint" v-model="hint" maxlength="200" />
                </div>
                <div class="flex items-center gap-2">
                    <Checkbox id="efe-section-repeatable" v-model="repeatable" />
                    <Label for="efe-section-repeatable">{{ trans('documents.editor.section.repeatable_hint') }}</Label>
                </div>
                <DialogFooter>
                    <Button type="button" variant="ghost" @click="open = false">{{ trans('documents.editor.cancel') }}</Button>
                    <Button type="submit">{{ trans('documents.editor.apply') }}</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
