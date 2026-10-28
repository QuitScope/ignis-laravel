<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { computed, reactive, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { inMap, type Pct } from '@/lib/leafletMap';

/** Zone drawing panel: points come from map clicks (MapView) or from the coordinate fields (keyboard alternative). */
const props = defineProps<{ points: Pct[]; colors: string[]; error?: string | null }>();
const emit = defineEmits<{ add: [p: Pct]; undo: []; cancel: []; color: [c: string]; save: [z: { name: string; description: string | null; color: string }] }>();

const form = reactive({ name: '', description: '', color: props.colors[0] });
const xy = reactive({ x: '', y: '' });
const manual = ref<string | null>(null);
const input = 'block min-h-11 w-full rounded-md border border-border bg-bg-2 px-3 py-2 text-text focus-visible:outline-2 focus-visible:outline-accent';
const canSave = computed(() => props.points.length >= 3 && form.name.trim() !== '');

const pick = (c: string) => {
    form.color = c;
    emit('color', c);
};
const addManual = () => {
    const p = { x: Number(xy.x), y: Number(xy.y) };
    if (xy.x === '' || xy.y === '' || !inMap(p)) {
        manual.value = trans('firetab.map.coords_invalid');
        return;
    }
    manual.value = null;
    emit('add', p);
    xy.x = xy.y = '';
};
const save = () => canSave.value && emit('save', { name: form.name.trim(), description: form.description.trim() || null, color: form.color });
</script>

<template>
    <form class="space-y-3 rounded-md border border-border p-3" @submit.prevent="save">
        <h2 class="font-semibold">{{ trans('firetab.map.draw_zone') }}</h2>
        <p class="text-sm text-text-2" aria-live="polite">{{ trans('firetab.map.zone_hint', { count: String(points.length) }) }}</p>

        <div class="grid grid-cols-2 gap-2 sm:grid-cols-[1fr_1fr_auto]">
            <div>
                <label for="zone-x" class="mb-1 block text-sm text-text-2">{{ trans('firetab.map.pos_x') }}</label>
                <input id="zone-x" v-model="xy.x" type="number" inputmode="decimal" min="0" max="100" step="0.01" :class="input" @keydown.enter.prevent="addManual" />
            </div>
            <div>
                <label for="zone-y" class="mb-1 block text-sm text-text-2">{{ trans('firetab.map.pos_y') }}</label>
                <input id="zone-y" v-model="xy.y" type="number" inputmode="decimal" min="0" max="100" step="0.01" :class="input" @keydown.enter.prevent="addManual" />
            </div>
            <Button type="button" variant="secondary" class="col-span-2 min-h-11 self-end sm:col-span-1" @click="addManual">{{ trans('firetab.map.add_point') }}</Button>
        </div>
        <p v-if="manual" role="alert" class="text-sm text-danger">{{ manual }}</p>

        <div>
            <label for="zone-name" class="mb-1 block text-sm text-text-2">{{ trans('firetab.map.name') }}</label>
            <input id="zone-name" v-model="form.name" maxlength="100" :class="input" />
        </div>
        <div>
            <label for="zone-desc" class="mb-1 block text-sm text-text-2">{{ trans('firetab.map.description') }}</label>
            <textarea id="zone-desc" v-model="form.description" maxlength="500" rows="2" :class="input" />
        </div>
        <fieldset>
            <legend class="mb-1 text-sm text-text-2">{{ trans('firetab.map.color') }}</legend>
            <div class="flex flex-wrap gap-2">
                <button
                    v-for="c in colors"
                    :key="c"
                    type="button"
                    class="size-11 rounded-md border-2"
                    :style="{ background: c, borderColor: c === form.color ? 'var(--text)' : 'transparent' }"
                    :aria-pressed="c === form.color"
                    :aria-label="trans(`firetab.map.colors.${c.slice(1)}`)"
                    @click="pick(c)"
                />
            </div>
        </fieldset>

        <p v-if="error" role="alert" class="text-sm text-danger">{{ error }}</p>
        <div class="flex flex-wrap gap-2">
            <Button type="button" variant="secondary" class="min-h-11" :disabled="points.length === 0" @click="emit('undo')">{{ trans('firetab.map.undo_point') }}</Button>
            <Button type="button" variant="secondary" class="min-h-11" @click="emit('cancel')">{{ trans('firetab.map.cancel') }}</Button>
            <Button type="submit" class="min-h-11" :disabled="!canSave">{{ trans('firetab.map.save_zone') }}</Button>
        </div>
    </form>
</template>
