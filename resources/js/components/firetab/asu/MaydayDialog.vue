<script setup lang="ts">
import { Siren } from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import type { AsuTruppRow } from '@/lib/asuClient';
import type { TruppState } from '@/lib/asuState';

const props = defineProps<{ trupps: { trupp: AsuTruppRow; state: TruppState }[]; truppId: number | null }>();
const emit = defineEmits<{
    close: [];
    send: [payload: { truppId: number; type: 'mayday' | 'mayday_ende'; memberId: number | null; note: string | null }];
}>();

const open = ref(true);
const truppId = ref<number>(props.truppId ?? props.trupps[0]?.trupp.id ?? 0);
const memberId = ref<number | null>(null);
const standort = ref('');
const lage = ref('');
const current = computed(() => props.trupps.find((t) => t.trupp.id === truppId.value));
const active = computed(() => current.value?.state.mayday ?? null);
const input = 'block min-h-11 w-full rounded-md border border-border bg-bg-2 px-3 py-2 text-text focus-visible:outline-2 focus-visible:outline-accent';

const send = () => {
    if (!current.value) return;
    if (active.value) return emit('send', { truppId: truppId.value, type: 'mayday_ende', memberId: null, note: null });
    const member = current.value.trupp.members.find((m) => m.id === memberId.value);
    const note = JSON.stringify({ member: member?.name ?? '', standort: standort.value.trim(), lage: lage.value.trim() });
    emit('send', { truppId: truppId.value, type: 'mayday', memberId: member?.id ?? null, note });
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
                <DialogTitle class="flex items-center gap-2"><Siren class="size-5 text-danger" aria-hidden="true" />{{ active ? trans('firetab.asu.mayday_end') : trans('firetab.asu.mayday') }}</DialogTitle>
                <DialogDescription>{{ active ? trans('firetab.asu.dialog.mayday_end_hint') : trans('firetab.asu.dialog.mayday_hint') }}</DialogDescription>
            </DialogHeader>
            <form class="space-y-3" @submit.prevent="send">
                <div v-if="truppId === null || trupps.length > 1">
                    <label for="md-trupp" class="mb-1 block text-sm text-text-2">{{ trans('firetab.asu.field.trupp') }}</label>
                    <select id="md-trupp" v-model.number="truppId" :class="input">
                        <option v-for="t in trupps" :key="t.trupp.id" :value="t.trupp.id">{{ t.trupp.name }}</option>
                    </select>
                </div>
                <template v-if="active">
                    <p class="rounded-md border border-danger p-3">
                        {{ active.member }} · {{ active.standort }} · {{ active.lage }}
                    </p>
                </template>
                <template v-else>
                    <div>
                        <label for="md-member" class="mb-1 block text-sm text-text-2">{{ trans('firetab.asu.field.member') }}</label>
                        <select id="md-member" v-model.number="memberId" :class="input">
                            <option :value="null">–</option>
                            <option v-for="m in current?.trupp.members ?? []" :key="m.id" :value="m.id">{{ trans(`firetab.asu.pos.${m.position}`) }}: {{ m.name }}</option>
                        </select>
                    </div>
                    <div>
                        <label for="md-standort" class="mb-1 block text-sm text-text-2">{{ trans('firetab.asu.field.standort') }}</label>
                        <input id="md-standort" v-model="standort" maxlength="60" autofocus :class="input" />
                    </div>
                    <div>
                        <label for="md-lage" class="mb-1 block text-sm text-text-2">{{ trans('firetab.asu.field.lage') }}</label>
                        <input id="md-lage" v-model="lage" maxlength="60" :class="input" />
                    </div>
                </template>
                <DialogFooter class="gap-2">
                    <Button type="button" variant="secondary" class="min-h-11" @click="update(false)">{{ trans('firetab.asu.cancel') }}</Button>
                    <Button type="submit" :variant="active ? 'default' : 'destructive'" class="min-h-11" :disabled="!current">{{ active ? trans('firetab.asu.mayday_end') : trans('firetab.asu.mayday_send') }}</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
