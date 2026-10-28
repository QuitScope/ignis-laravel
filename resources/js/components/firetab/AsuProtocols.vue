<script lang="ts">
export type AsuProtocolRow = { id: number; supervisor: string; location: string | null; date: string | null; trupps: string[] };
/** IncidentController::asuRegistration: times HH:MM (organisation zone), voided events already left out. */
export type AsuRegistrationRow = {
    id: number;
    name: string;
    safety: boolean;
    members: string[];
    angeschlossen: string | null;
    start_bar: number | null;
    hinweis_13: string | null;
    hinweis_23: string | null;
    ziel: string | null;
    ziel_bar: number | null;
    rueckzug: string | null;
    draussen: string | null;
    minutes: number | null;
    mayday: string | null;
};
</script>

<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';

defineProps<{ protocols: AsuProtocolRow[]; registration: AsuRegistrationRow[] }>();

const withBar = (t: string | null, bar: number | null) => (t && bar !== null ? `${t} · ${bar} bar` : (t ?? ''));
const cols = ['angeschlossen', 'hinweis_13', 'hinweis_23', 'ziel', 'rueckzug', 'draussen'] as const;
</script>

<template>
    <section v-if="registration.length" class="space-y-2">
        <h2 class="text-lg font-semibold">{{ trans('firetab.asu_registration.title') }}</h2>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="text-text-2">
                    <tr>
                        <th class="p-2">{{ trans('firetab.asu_registration.trupp') }}</th>
                        <th class="p-2">{{ trans('firetab.asu_registration.members') }}</th>
                        <th v-for="c in cols" :key="c" class="p-2">{{ trans(`firetab.asu.event.${c}`) }}</th>
                        <th class="p-2">{{ trans('firetab.asu_registration.minutes') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="t in registration" :key="t.id" class="border-t border-border">
                        <td class="p-2">
                            <strong>{{ t.name }}</strong>
                            <small v-if="t.safety" class="text-text-2"> {{ trans('firetab.asu.role.sicherheit') }}</small>
                            <span v-if="t.mayday" class="ml-1 font-semibold text-danger">{{ trans('firetab.asu.event.mayday') }} {{ t.mayday }}</span>
                        </td>
                        <td class="p-2">{{ t.members.join(', ') }}</td>
                        <td class="p-2">{{ withBar(t.angeschlossen, t.start_bar) }}</td>
                        <td class="p-2">{{ t.hinweis_13 }}</td>
                        <td class="p-2">{{ t.hinweis_23 }}</td>
                        <td class="p-2">{{ withBar(t.ziel, t.ziel_bar) }}</td>
                        <td class="p-2">{{ t.rueckzug }}</td>
                        <td class="p-2">{{ t.draussen }}</td>
                        <td class="p-2">{{ t.minutes }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
    <section v-if="protocols.length" class="space-y-2">
        <h2 class="text-lg font-semibold">{{ trans('firetab.asu_protocols.title') }}</h2>
        <ul class="space-y-2">
            <li v-for="p in protocols" :key="p.id" class="rounded-md border border-border bg-bg-2 p-3 text-sm">
                <p>
                    <span class="text-text-2">{{ trans('firetab.asu_protocols.supervisor') }}:</span> {{ p.supervisor }}
                    · <span class="text-text-2">{{ trans('firetab.asu_protocols.location') }}:</span> {{ p.location ?? '–' }}
                    · <span class="text-text-2">{{ trans('firetab.asu_protocols.date') }}:</span> {{ p.date ?? '–' }}
                </p>
                <p v-if="p.trupps.length">
                    <span class="text-text-2">{{ trans('firetab.asu_protocols.trupps') }}:</span> {{ p.trupps.join(', ') }}
                </p>
            </li>
        </ul>
    </section>
</template>
