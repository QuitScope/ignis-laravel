<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { ref } from 'vue';
import Board from '@/components/firetab/asu/Board.vue';
import Log, { type LogRow } from '@/components/firetab/Log.vue';
import { Button } from '@/components/ui/button';

type Sitrep = { id: number; at: string | null; text: string; reporter: string | null; source: string };

const props = defineProps<{
    incident: Record<string, string | number | boolean | null>;
    vehicles: { id: number; name: string | null; identifier: string | null; radio_name: string | null; is_external: boolean }[];
    sitreps: Sitrep[];
    log: LogRow[];
    asu: { url: string; config: { consumption: number; start_bar: number } };
    statuses: string[];
    can: { review: boolean; archive: boolean; reopen: boolean };
}>();

const status = ref(String(props.incident.status));
const base = `/firetab/qm/${props.incident.id}`;
const fields: [string, string][] = [
    ['number', 'number'],
    ['location', 'location'],
    ['keyword', 'keyword'],
    ['started_at', 'started_date'],
    ['leader_name', 'leader'],
    ['caller_name', 'caller_name'],
    ['caller_contact', 'caller_contact'],
    ['owner_name', 'owner_name'],
    ['owner_contact', 'owner_contact'],
];
</script>

<template>
    <div class="mx-auto max-w-4xl space-y-6">
        <header class="flex flex-wrap items-center justify-between gap-2">
            <h1 class="text-2xl font-semibold">{{ incident.number ?? '#' + incident.id }} · {{ incident.keyword }}</h1>
            <Link href="/firetab/qm" class="text-accent">{{ trans('firetab.back') }}</Link>
        </header>
        <p class="text-sm text-text-2">{{ trans('firetab.qm.readonly') }}</p>

        <div class="flex flex-wrap items-center gap-2">
            <template v-if="can.review">
                <select v-model="status" class="h-9 rounded-md border border-border bg-card px-3 text-sm" :aria-label="trans('firetab.qm.set_status')">
                    <option v-for="s in statuses" :key="s" :value="s">{{ trans(`firetab.status.${s}`) }}</option>
                </select>
                <Button size="sm" @click="router.patch(`${base}/status`, { status }, { preserveScroll: true })">{{ trans('firetab.qm.set_status') }}</Button>
            </template>
            <Button v-if="can.reopen && incident.finalized" size="sm" variant="ghost" @click="router.post(`${base}/reopen`, {}, { preserveScroll: true })">{{ trans('firetab.qm.reopen') }}</Button>
            <Button v-if="can.archive && !incident.archived" size="sm" variant="ghost" @click="router.post(`${base}/archive`, {}, { preserveScroll: true })">{{ trans('firetab.qm.archive') }}</Button>
            <Button v-if="can.archive && incident.archived" size="sm" variant="ghost" @click="router.delete(`${base}/archive`, { preserveScroll: true })">{{ trans('firetab.qm.unarchive') }}</Button>
        </div>

        <section class="space-y-2">
            <h2 class="text-lg font-semibold">{{ trans('firetab.qm.section.master') }}</h2>
            <dl class="grid gap-x-4 gap-y-1 sm:grid-cols-2">
                <div v-for="[f, l] in fields" :key="f">
                    <dt class="text-sm text-text-2">{{ trans(`firetab.field.${l}`) }}</dt>
                    <dd>{{ incident[f] ?? '–' }}</dd>
                </div>
            </dl>
        </section>

        <section class="space-y-2">
            <h2 class="text-lg font-semibold">{{ trans('firetab.qm.section.report') }}</h2>
            <p class="whitespace-pre-wrap">{{ incident.notes ?? '–' }}</p>
        </section>

        <section class="space-y-2">
            <h2 class="text-lg font-semibold">{{ trans('firetab.qm.section.vehicles') }}</h2>
            <ul class="list-disc pl-5">
                <li v-for="v in vehicles" :key="v.id">
                    {{ v.radio_name ?? v.name }} <span v-if="v.identifier" class="text-text-2">({{ v.identifier }})</span>
                </li>
            </ul>
        </section>

        <section class="space-y-2">
            <h2 class="text-lg font-semibold">{{ trans('firetab.qm.section.sitreps') }}</h2>
            <ul v-if="sitreps.length" class="divide-y divide-border rounded-md border border-border">
                <li v-for="s in sitreps" :key="s.id" class="px-4 py-2">
                    <p class="text-sm text-text-2">{{ s.at }} · {{ s.reporter }}</p>
                    <p class="whitespace-pre-wrap">{{ s.text }}</p>
                </li>
            </ul>
            <p v-else class="text-text-2">{{ trans('firetab.sitreps.empty') }}</p>
        </section>

        <p><Link :href="`/firetab/qm/incidents/${incident.id}/map`" class="text-accent">{{ trans('firetab.tab.lagekarte') }}</Link></p>

        <section class="space-y-2">
            <Board :incident-id="Number(incident.id)" :url="asu.url" :config="asu.config" :can-write="false" />
        </section>

        <Log :log="log" />
    </div>
</template>
