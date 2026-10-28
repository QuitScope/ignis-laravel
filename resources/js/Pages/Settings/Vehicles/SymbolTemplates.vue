<script setup lang="ts">
import { ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import TacticalSymbolForm from '@/components/vehicles/TacticalSymbolForm.vue';
import TacticalSymbolPreview from '@/components/vehicles/TacticalSymbolPreview.vue';
import { emptySymbol, type SymbolTemplate } from '@/lib/tacticalSymbol';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

defineProps<{ templates: SymbolTemplate[]; types: string[]; can: { manage: boolean } }>();

const BASE = '/settings/vehicles/symbol-templates';
const field = 'h-9 w-full rounded-md border border-border bg-card px-3 text-sm';

// ----- create and edit -----
const form = useForm({ name: '', symbol: emptySymbol() });
const editing = ref<SymbolTemplate | null>(null);
const formOpen = ref(false);
function open(t: SymbolTemplate | null) {
    editing.value = t;
    form.clearErrors();
    form.defaults({ name: t?.name ?? '', symbol: emptySymbol(t?.symbol) }).reset();
    formOpen.value = true;
}
function save() {
    const options = { preserveScroll: true, onSuccess: () => (formOpen.value = false) };
    if (editing.value) form.put(`${BASE}/${editing.value.id}`, options);
    else form.post(BASE, options);
}

// ----- delete -----
const deleting = ref<SymbolTemplate | null>(null);
const destroy = () => deleting.value && router.delete(`${BASE}/${deleting.value.id}`, { preserveScroll: true, onFinish: () => (deleting.value = null) });

// ----- apply to a vehicle type -----
const applyForm = useForm({ type: '' });
const applying = ref<SymbolTemplate | null>(null);
function openApply(t: SymbolTemplate, types: string[]) {
    applying.value = t;
    applyForm.clearErrors();
    applyForm.defaults({ type: types[0] ?? '' }).reset();
}
function apply() {
    if (applying.value) applyForm.post(`${BASE}/${applying.value.id}/apply`, { preserveScroll: true, onSuccess: () => (applying.value = null) });
}
</script>

<template>
    <div class="max-w-3xl space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div>
                <h1 class="text-2xl font-semibold">{{ trans('symbol_templates.title') }}</h1>
                <p class="text-sm text-text-2">{{ trans('symbol_templates.lead') }}</p>
            </div>
            <Button v-if="can.manage" @click="open(null)">{{ trans('symbol_templates.new') }}</Button>
        </div>

        <ul v-if="templates.length" class="space-y-2">
            <li
                v-for="t in templates"
                :key="t.id"
                class="flex flex-wrap items-center gap-3 rounded-lg border border-border bg-card p-3 shadow-(--shadow)"
            >
                <TacticalSymbolPreview :symbol="t.symbol" :size="56" />
                <span class="min-w-0 flex-1 truncate font-medium">{{ t.name }}</span>
                <div v-if="can.manage" class="flex flex-wrap gap-2">
                    <Button size="sm" variant="ghost" @click="openApply(t, types)">{{ trans('symbol_templates.apply') }}</Button>
                    <Button size="sm" variant="ghost" @click="open(t)">{{ trans('symbol_templates.edit_short') }}</Button>
                    <Button size="sm" variant="ghost" @click="deleting = t">{{ trans('symbol_templates.delete_short') }}</Button>
                </div>
            </li>
        </ul>
        <p v-else class="text-sm text-text-2">{{ trans('symbol_templates.empty') }}</p>

        <Dialog v-model:open="formOpen">
            <DialogContent class="max-h-[90vh] overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>{{ trans(editing ? 'symbol_templates.edit' : 'symbol_templates.new') }}</DialogTitle>
                    <DialogDescription class="sr-only">{{ trans('symbol_templates.lead') }}</DialogDescription>
                </DialogHeader>
                <form class="space-y-4" @submit.prevent="save">
                    <div class="space-y-1">
                        <Label for="tpl-name">{{ trans('symbol_templates.name') }} *</Label>
                        <Input id="tpl-name" v-model="form.name" required maxlength="100" :aria-invalid="!!form.errors.name" />
                        <p v-if="form.errors.name" class="text-sm text-danger" role="alert">{{ form.errors.name }}</p>
                    </div>
                    <TacticalSymbolForm v-model="form.symbol" prefix="symbol" :errors="form.errors" />
                    <p v-if="form.errors.symbol" class="text-sm text-danger" role="alert">{{ form.errors.symbol }}</p>
                    <DialogFooter>
                        <Button type="button" variant="ghost" @click="formOpen = false">{{ trans('symbol_templates.cancel') }}</Button>
                        <Button type="submit" :disabled="form.processing">{{ trans('symbol_templates.save') }}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog :open="deleting !== null" @update:open="(v) => !v && (deleting = null)">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{{ trans('symbol_templates.delete') }}</DialogTitle>
                    <DialogDescription class="text-foreground">{{ trans('symbol_templates.delete_confirm', { name: deleting?.name ?? '' }) }}</DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button variant="ghost" @click="deleting = null">{{ trans('symbol_templates.cancel') }}</Button>
                    <Button variant="destructive" @click="destroy">{{ trans('symbol_templates.delete_short') }}</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <Dialog :open="applying !== null" @update:open="(v) => !v && (applying = null)">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{{ trans('symbol_templates.apply_title', { name: applying?.name ?? '' }) }}</DialogTitle>
                    <DialogDescription>{{ trans('symbol_templates.apply_hint') }}</DialogDescription>
                </DialogHeader>
                <form class="space-y-3" @submit.prevent="apply">
                    <div v-if="types.length" class="space-y-1">
                        <Label for="apply-type">{{ trans('symbol_templates.apply_type') }}</Label>
                        <select id="apply-type" v-model="applyForm.type" :class="field" required>
                            <option v-for="t in types" :key="t" :value="t">{{ t }}</option>
                        </select>
                        <p v-if="applyForm.errors.type" class="text-sm text-danger" role="alert">{{ applyForm.errors.type }}</p>
                    </div>
                    <p v-else class="text-sm text-text-2">{{ trans('symbol_templates.apply_none') }}</p>
                    <DialogFooter>
                        <Button type="button" variant="ghost" @click="applying = null">{{ trans('symbol_templates.cancel') }}</Button>
                        <Button type="submit" :disabled="applyForm.processing || !types.length">{{ trans('symbol_templates.apply') }}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
