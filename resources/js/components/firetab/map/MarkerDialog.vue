<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { computed, reactive, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { inMap, TYPE_COLOR } from '@/lib/leafletMap';
import { SYMBOL_SELECTS, SYMBOL_TEXTS, symbolOptions } from '@/lib/tacticalSymbolOptions';

export type MarkerForm = Record<string, string>;

/** New marker. The position is a pair of percent fields: set by a map click (MapView) or typed (keyboard alternative). */
const props = defineProps<{ initial: MarkerForm; error?: string | null }>();
const emit = defineEmits<{ close: []; pick: [form: MarkerForm]; save: [payload: Record<string, string | number>] }>();

const types = Object.keys(TYPE_COLOR).filter((t) => t !== 'Einsatzort');
const open = ref(true);
const form = reactive<MarkerForm>({ marker_type: 'custom', pos_x: '50', pos_y: '50', name: '', description: '', ...props.initial });
const input = 'block min-h-11 w-full rounded-md border border-border bg-bg-2 px-3 py-2 text-text focus-visible:outline-2 focus-visible:outline-accent';
const canSave = computed(() => inMap({ x: Number(form.pos_x), y: Number(form.pos_y) }) && form.pos_x !== '' && form.pos_y !== '');

const save = () => {
    if (!canSave.value) return;
    const payload: Record<string, string | number> = { marker_type: form.marker_type, pos_x: Number(form.pos_x), pos_y: Number(form.pos_y) };
    for (const k of ['name', 'description', ...SYMBOL_SELECTS, ...SYMBOL_TEXTS]) if (form[k]?.trim()) payload[k] = form[k].trim();
    emit('save', payload);
};
const update = (v: boolean) => {
    open.value = v;
    if (!v) emit('close');
};
</script>

<template>
    <Dialog :open="open" @update:open="update">
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>{{ trans('firetab.map.add_marker') }}</DialogTitle>
                <DialogDescription>{{ trans('firetab.map.marker_hint') }}</DialogDescription>
            </DialogHeader>

            <form class="space-y-3" @submit.prevent="save">
                <div>
                    <label for="mk-type" class="mb-1 block text-sm text-text-2">{{ trans('firetab.map.marker_type') }}</label>
                    <select id="mk-type" v-model="form.marker_type" autofocus :class="input">
                        <option v-for="t in types" :key="t" :value="t">{{ t === 'custom' ? trans('firetab.map.type_custom') : t }}</option>
                    </select>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label for="mk-x" class="mb-1 block text-sm text-text-2">{{ trans('firetab.map.pos_x') }}</label>
                        <input id="mk-x" v-model="form.pos_x" type="number" inputmode="decimal" min="0" max="100" step="0.01" :class="input" />
                    </div>
                    <div>
                        <label for="mk-y" class="mb-1 block text-sm text-text-2">{{ trans('firetab.map.pos_y') }}</label>
                        <input id="mk-y" v-model="form.pos_y" type="number" inputmode="decimal" min="0" max="100" step="0.01" :class="input" />
                    </div>
                </div>
                <Button type="button" variant="secondary" class="min-h-11" @click="emit('pick', { ...form })">{{ trans('firetab.map.pick_on_map') }}</Button>
                <div>
                    <label for="mk-name" class="mb-1 block text-sm text-text-2">{{ trans('firetab.map.name') }}</label>
                    <input id="mk-name" v-model="form.name" maxlength="100" :class="input" />
                </div>
                <div>
                    <label for="mk-desc" class="mb-1 block text-sm text-text-2">{{ trans('firetab.map.description') }}</label>
                    <textarea id="mk-desc" v-model="form.description" maxlength="500" rows="2" :class="input" />
                </div>
                <fieldset class="grid gap-3 sm:grid-cols-2">
                    <legend class="mb-1 text-sm font-semibold">{{ trans('firetab.map.symbol') }}</legend>
                    <div v-for="k in SYMBOL_SELECTS" :key="k">
                        <label :for="`mk-${k}`" class="mb-1 block text-sm text-text-2">{{ trans(`firetab.map.sym.${k}`) }}</label>
                        <select :id="`mk-${k}`" v-model="form[k]" :class="input">
                            <option value="">-</option>
                            <option v-for="o in symbolOptions[k]" :key="o.value" :value="o.value">{{ o.label }}</option>
                        </select>
                    </div>
                    <div v-for="k in SYMBOL_TEXTS" :key="k">
                        <label :for="`mk-${k}`" class="mb-1 block text-sm text-text-2">{{ trans(`firetab.map.sym.${k}`) }}</label>
                        <input :id="`mk-${k}`" v-model="form[k]" maxlength="100" :class="input" />
                    </div>
                </fieldset>

                <p v-if="error" role="alert" class="text-sm text-danger">{{ error }}</p>
                <DialogFooter class="gap-2">
                    <Button type="button" variant="secondary" class="min-h-11" @click="update(false)">{{ trans('firetab.map.cancel') }}</Button>
                    <Button type="submit" class="min-h-11" :disabled="!canSave">{{ trans('firetab.map.save') }}</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
