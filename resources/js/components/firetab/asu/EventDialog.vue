<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { computed, reactive, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import type { AsuTruppRow } from '@/lib/asuClient';

export type TruppPayload = { name: string; role: string; task: string | null; expected_minutes: number; members: { position: string; name: string }[] };

/**
 * One dialog for the entries that carry numbers or text:
 * start (pressure when connected), druck (pressure per person), ziel (pressures at the target, optional), trupp (create/edit).
 */
const props = defineProps<{
    mode: 'start' | 'druck' | 'ziel' | 'trupp';
    trupp: AsuTruppRow | null;
    startBar: number;
    last: Record<number, number | null>;
    error?: string | null;
}>();
const emit = defineEmits<{ close: []; bars: [bars: Record<number, number | null>]; start: [bar: number]; trupp: [payload: TruppPayload]; remove: [] }>();

const POSITIONS = ['tf', 'tm1', 'tm2'] as const;
const open = ref(true);
const bar = ref(props.startBar);
const bars = reactive<Record<number, string>>(Object.fromEntries((props.trupp?.members ?? []).map((m) => [m.id, props.mode === 'druck' ? String(props.last[m.id] ?? '') : ''])));
const form = reactive({
    name: props.trupp?.name ?? '',
    role: props.trupp?.role ?? 'angriff',
    task: props.trupp?.task ?? '',
    minutes: props.trupp?.expected_minutes ?? 30,
    names: Object.fromEntries(POSITIONS.map((p) => [p, props.trupp?.members.find((m) => m.position === p)?.name ?? ''])) as Record<string, string>,
});
const title = computed(() => trans(`firetab.asu.dialog.${props.mode}`));
const input = 'block min-h-11 w-full rounded-md border border-border bg-bg-2 px-3 py-2 text-text focus-visible:outline-2 focus-visible:outline-accent';

const valid = (v: string) => v !== '' && Number.isInteger(Number(v)) && Number(v) >= 0 && Number(v) <= 400;
const canSave = computed(() => {
    if (props.mode === 'start') return Number.isInteger(bar.value) && bar.value >= 0 && bar.value <= 400;
    if (props.mode === 'druck') return Object.values(bars).some(valid);
    if (props.mode === 'ziel') return Object.values(bars).every((v) => v === '' || valid(v));
    return form.name.trim() !== '' && form.minutes >= 5 && form.minutes <= 120 && POSITIONS.some((p) => form.names[p].trim() !== '');
});

const save = () => {
    if (!canSave.value) return;
    if (props.mode === 'start') emit('start', bar.value);
    else if (props.mode === 'trupp') {
        emit('trupp', {
            name: form.name.trim(),
            role: form.role,
            task: form.task.trim() || null,
            expected_minutes: Number(form.minutes),
            members: POSITIONS.map((p) => ({ position: p, name: form.names[p].trim() })),
        });
    } else emit('bars', Object.fromEntries(Object.entries(bars).map(([id, v]) => [Number(id), valid(v) ? Number(v) : null])));
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
                <DialogTitle>{{ title }}<template v-if="trupp && mode !== 'trupp'"> · {{ trupp.name }}</template></DialogTitle>
                <DialogDescription>{{ trans(`firetab.asu.dialog.${mode}_hint`) }}</DialogDescription>
            </DialogHeader>

            <form class="space-y-3" @submit.prevent="save">
                <div v-if="mode === 'start'">
                    <label for="asu-bar" class="mb-1 block text-sm text-text-2">{{ trans('firetab.asu.start_bar') }}</label>
                    <input id="asu-bar" v-model.number="bar" type="number" inputmode="numeric" min="0" max="400" autofocus :class="input" />
                </div>

                <template v-else-if="mode === 'druck' || mode === 'ziel'">
                    <div v-for="(m, i) in trupp?.members ?? []" :key="m.id">
                        <label :for="`asu-p-${m.id}`" class="mb-1 block text-sm text-text-2">{{ trans(`firetab.asu.pos.${m.position}`) }}: {{ m.name }} (bar)</label>
                        <input :id="`asu-p-${m.id}`" v-model="bars[m.id]" type="number" inputmode="numeric" min="0" max="400" :autofocus="i === 0" :class="input" />
                    </div>
                </template>

                <template v-else>
                    <div>
                        <label for="asu-name" class="mb-1 block text-sm text-text-2">{{ trans('firetab.asu.field.name') }}</label>
                        <input id="asu-name" v-model="form.name" maxlength="50" autofocus :class="input" />
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <label for="asu-role" class="mb-1 block text-sm text-text-2">{{ trans('firetab.asu.field.role') }}</label>
                            <select id="asu-role" v-model="form.role" :class="input">
                                <option v-for="r in ['angriff', 'sicherheit']" :key="r" :value="r">{{ trans(`firetab.asu.role.${r}`) }}</option>
                            </select>
                        </div>
                        <div>
                            <label for="asu-min" class="mb-1 block text-sm text-text-2">{{ trans('firetab.asu.field.minutes') }}</label>
                            <input id="asu-min" v-model.number="form.minutes" type="number" inputmode="numeric" min="5" max="120" :class="input" />
                        </div>
                    </div>
                    <div>
                        <label for="asu-task" class="mb-1 block text-sm text-text-2">{{ trans('firetab.asu.field.task') }}</label>
                        <input id="asu-task" v-model="form.task" maxlength="255" :class="input" />
                    </div>
                    <div v-for="p in POSITIONS" :key="p">
                        <label :for="`asu-n-${p}`" class="mb-1 block text-sm text-text-2">{{ trans(`firetab.asu.pos.${p}`) }}</label>
                        <input :id="`asu-n-${p}`" v-model="form.names[p]" maxlength="100" :class="input" />
                    </div>
                </template>

                <p v-if="error" role="alert" class="text-sm text-danger">{{ error }}</p>
                <DialogFooter class="gap-2">
                    <Button v-if="mode === 'trupp' && trupp" type="button" variant="ghost" class="min-h-11 text-danger sm:mr-auto" @click="emit('remove')">{{ trans('firetab.asu.delete_trupp') }}</Button>
                    <Button type="button" variant="secondary" class="min-h-11" @click="update(false)">{{ trans('firetab.asu.cancel') }}</Button>
                    <Button type="submit" class="min-h-11" :disabled="!canSave">{{ trans('firetab.asu.save') }}</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
