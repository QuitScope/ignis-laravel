<script setup lang="ts">
import { CircleCheck, Clock, Gauge, Hourglass, MessageCircleQuestion, Pencil, Play, Siren, TriangleAlert, Undo2, UserRound } from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';
import { computed, type Component } from 'vue';
import { Button } from '@/components/ui/button';
import type { AsuTruppRow } from '@/lib/asuClient';
import type { AsuTone, TruppState } from '@/lib/asuState';

const props = defineProps<{ trupp: AsuTruppRow; state: TruppState; writable: boolean }>();
const emit = defineEmits<{
    next: [];
    druck: [];
    ask: [type: 'hinweis_13' | 'hinweis_23'];
    mayday: [];
    edit: [];
}>();

// The tone is told by icon and word as well as by colour.
const icons: Record<AsuTone, Component> = { mayday: Siren, alarm: TriangleAlert, due: MessageCircleQuestion, back: Undo2, go: Play, done: CircleCheck, idle: Clock, ready: Clock };

const mmss = (ms: number) => {
    const s = Math.max(0, Math.floor(ms / 1000));
    return `${String(Math.floor(s / 60)).padStart(2, '0')}:${String(s % 60).padStart(2, '0')}`;
};
const advancing = computed(() => props.state.phase === 'vorgehen' || props.state.phase === 'ziel');
const unconfirmed = computed(() => props.state.nextAsk);
const which = (t: string) => (t === 'hinweis_13' ? '1/3' : '2/3');
const pct = (bar: number | null, full: number | null) => (bar === null || !full ? 0 : Math.max(0, Math.min(100, Math.round((bar / full) * 100))));
</script>

<template>
    <article class="asu-card" :data-tone="state.tone" :aria-label="trupp.name">
        <header class="asu-card__head">
            <div class="min-w-0">
                <h3 class="truncate text-xl font-bold">{{ trupp.name }}</h3>
                <p class="text-sm">
                    {{ trans(`firetab.asu.role.${trupp.role}`) }}<template v-if="trupp.task"> · {{ trupp.task }}</template>
                </p>
            </div>
            <p class="asu-tone">
                <component :is="icons[state.tone]" class="size-5" aria-hidden="true" />
                <span>{{ trans(`firetab.asu.tone.${state.tone}`) }}</span>
            </p>
        </header>

        <p v-if="state.mayday" class="asu-mayday-note">
            <Siren class="size-5" aria-hidden="true" />
            <span>
                {{ trans('firetab.asu.mayday_active') }}
                <template v-if="state.mayday.member"> · {{ state.mayday.member }}</template>
                <template v-if="state.mayday.standort"> · {{ state.mayday.standort }}</template>
                <template v-if="state.mayday.lage"> · {{ state.mayday.lage }}</template>
            </span>
        </p>

        <dl class="asu-metrics">
            <div>
                <dt><Clock class="size-4" aria-hidden="true" />{{ trans('firetab.asu.elapsed') }}</dt>
                <dd :class="{ 'is-over': state.overtime }">{{ mmss(state.elapsedMs) }}<span v-if="state.overtime"> ({{ trans('firetab.asu.overtime') }})</span></dd>
            </div>
            <div v-if="advancing || state.phase === 'rueckzug'">
                <dt><Hourglass class="size-4" aria-hidden="true" />{{ trans('firetab.asu.rest') }}</dt>
                <dd>{{ state.restMin }} {{ trans('firetab.asu.min') }} <small>({{ trans(`firetab.asu.rest_source.${state.restSource}`) }})</small></dd>
            </div>
            <div v-if="advancing && unconfirmed">
                <dt><MessageCircleQuestion class="size-4" aria-hidden="true" />{{ trans('firetab.asu.ask') }} {{ which(unconfirmed.type) }}</dt>
                <dd :class="{ 'is-over': state.askDue }">{{ state.askDue ? trans('firetab.asu.ask_due') : `${state.askMin} ${trans('firetab.asu.min')}` }}</dd>
            </div>
            <div v-if="state.retreatBar !== null">
                <dt><Gauge class="size-4" aria-hidden="true" />{{ trans('firetab.asu.retreat_bar') }}</dt>
                <dd :class="{ 'is-over': state.belowRetreat }">{{ state.retreatBar }} bar<span v-if="state.belowRetreat"> ({{ trans('firetab.asu.below_retreat') }})</span></dd>
            </div>
        </dl>

        <ul class="asu-people">
            <li v-for="m in state.members" :key="m.id">
                <UserRound class="size-4" aria-hidden="true" />
                <span class="min-w-0 flex-1 truncate">{{ trans(`firetab.asu.pos.${m.position}`) }}: {{ m.name }}</span>
                <span class="asu-gauge" role="img" :aria-label="`${m.bar ?? '–'} bar`">
                    <b :style="{ width: `${pct(m.bar, state.startBar)}%` }" />
                </span>
                <span class="w-16 text-right font-mono font-bold">{{ m.bar ?? '–' }}<small> bar</small></span>
            </li>
        </ul>

        <div v-if="writable" class="asu-actions">
            <Button v-if="state.nextAction" type="button" class="min-h-11" @click="emit('next')">{{ trans(`firetab.asu.next.${state.nextAction}`) }}</Button>
            <Button v-if="state.underAir" type="button" variant="secondary" class="min-h-11" @click="emit('druck')">{{ trans('firetab.asu.pressure') }}</Button>
            <Button v-if="advancing && unconfirmed" type="button" variant="secondary" class="min-h-11" @click="emit('ask', unconfirmed.type)">
                {{ trans('firetab.asu.ask_done', { which: which(unconfirmed.type) }) }}
            </Button>
            <Button type="button" variant="destructive" class="min-h-11" @click="emit('mayday')">
                <Siren class="size-4" aria-hidden="true" />{{ state.mayday ? trans('firetab.asu.mayday_end') : trans('firetab.asu.mayday') }}
            </Button>
            <Button type="button" variant="ghost" class="min-h-11" @click="emit('edit')"><Pencil class="size-4" aria-hidden="true" />{{ trans('firetab.asu.edit_trupp') }}</Button>
        </div>
    </article>
</template>

<style scoped>
.asu-card { --c: var(--ok); display: grid; gap: 10px; padding: 12px; border-radius: 10px; border: 1px solid var(--c); border-left-width: 10px; background: var(--surface); color: var(--text); }
.asu-card[data-tone='idle'], .asu-card[data-tone='ready'] { --c: var(--text-3); }
.asu-card[data-tone='due'] { --c: var(--warn); }
.asu-card[data-tone='alarm'] { --c: var(--danger); }
.asu-card[data-tone='mayday'] { --c: var(--danger); box-shadow: inset 0 0 0 3px var(--white); }
.asu-card[data-tone='back'] { --c: var(--info); }
.asu-card[data-tone='done'] { --c: var(--border-strong); }
.asu-card__head { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 8px; }
.asu-tone { display: inline-flex; align-items: center; gap: 6px; align-self: start; margin: 0; padding: 4px 10px; border-radius: 6px; background: var(--c); color: var(--white); font-weight: 700; text-transform: uppercase; letter-spacing: 0.03em; }
.asu-mayday-note { display: flex; align-items: center; gap: 8px; margin: 0; padding: 8px 10px; border-radius: 6px; background: rgba(var(--danger-rgb), 0.45); font-weight: 700; }
.asu-metrics { display: flex; flex-wrap: wrap; gap: 8px 24px; margin: 0; }
.asu-metrics dt { display: flex; align-items: center; gap: 4px; font-size: 0.8rem; color: var(--text-2); }
.asu-metrics dd { margin: 0; font: 700 1.6rem/1.1 ui-monospace, 'SFMono-Regular', Menlo, monospace; }
.asu-metrics dd small { font: 400 0.8rem sans-serif; color: var(--text-2); }
.asu-metrics dd.is-over { color: var(--danger); }
.asu-people { display: grid; gap: 6px; margin: 0; padding: 0; list-style: none; }
.asu-people li { display: flex; align-items: center; gap: 8px; }
.asu-gauge { position: relative; width: 120px; height: 14px; border: 2px solid var(--white); flex: none; }
.asu-gauge b { position: absolute; top: 0; bottom: 0; left: 0; background: var(--white); }
.asu-actions { display: flex; flex-wrap: wrap; gap: 8px; }
</style>
