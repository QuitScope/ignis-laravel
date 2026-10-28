<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { Button } from '@/components/ui/button';
import { type BoardPatient, patientState, triageClass } from '@/lib/manv';

// One lane of the board: the patients of one triage category (or untriaged), no personal data.
const props = defineProps<{ lane: string; patients: BoardPatient[]; incidentId: number; canWrite: boolean }>();
defineEmits<{ depart: [patient: BoardPatient] }>();

const title = props.lane === 'none' ? trans('manvboard.triage.none') : `${trans(`manvboard.triage.${props.lane}`)} ${trans(`manvboard.triage_name.${props.lane}`)}`;
</script>

<template>
    <section class="flex min-w-56 flex-1 flex-col rounded-lg border border-border bg-card shadow-(--shadow)" :aria-label="title">
        <header class="flex items-center justify-between gap-2 rounded-t-lg px-3 py-2" :class="triageClass(lane === 'none' ? null : lane)">
            <h2 class="text-sm font-semibold">{{ title }}</h2>
            <span class="text-sm font-semibold tabular-nums">{{ patients.length }}</span>
        </header>
        <ul class="space-y-2 p-2">
            <li v-for="p in patients" :key="p.id" class="rounded-md border border-border p-2 text-sm">
                <div class="flex items-center justify-between gap-2">
                    <Link :href="`/mci/${incidentId}/patients/${p.id}`" class="font-mono font-semibold hover:underline">{{ p.number }}</Link>
                    <span class="text-text-2">{{ p.age ?? '?' }} {{ trans(`manvboard.sex_short.${p.sex}`) }}</span>
                </div>
                <p v-if="p.vehicle" class="mt-1 truncate text-text-2">{{ p.vehicle }}<template v-if="p.destination"> → {{ p.destination }}</template></p>
                <div class="mt-2 flex items-center justify-between gap-2">
                    <span class="text-xs" :class="patientState(p) === 'ready' ? 'text-ok' : 'text-warn'">{{ trans(`manvboard.board.${patientState(p)}`) }}</span>
                    <Button v-if="canWrite && p.can_depart" size="sm" variant="secondary" @click="$emit('depart', p)">{{ trans('manvboard.board.depart') }}</Button>
                </div>
            </li>
        </ul>
    </section>
</template>
