<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { getActiveLanguage, trans } from 'laravel-vue-i18n';
import FullCalendar from '@fullcalendar/vue3';
import dayGridPlugin from '@fullcalendar/daygrid';
import timeGridPlugin from '@fullcalendar/timegrid';
import listPlugin from '@fullcalendar/list';
import interactionPlugin from '@fullcalendar/interaction';
import deLocale from '@fullcalendar/core/locales/de';
import type { CalendarOptions, EventContentArg } from '@fullcalendar/core';
import { Lock, Plus, Rss, X } from '@lucide/vue';
import AbsenceToday from '@/components/calendar/AbsenceToday.vue';
import EventDetail, { type Detail } from '@/components/calendar/EventDetail.vue';
import EventDialog, { type Conflict } from '@/components/calendar/EventDialog.vue';
import SubscriptionDialog from '@/components/calendar/SubscriptionDialog.vue';
import { categoryIcon } from '@/components/calendar/icons';
import { Button } from '@/components/ui/button';
import {
    absentNames,
    addDays,
    addMinutes,
    filterItems,
    floatingDate,
    floatingWall,
    mapFeedItem,
    rangeParams,
    wallNow,
    type FeedItem,
} from '@/lib/calendar';
import { calendarRequest } from '@/lib/calendarApi';

const props = defineProps<{
    can: { create: boolean; manage: boolean };
    roles: { id: number; name: string }[];
    timezone: string;
    options: { colors: string[]; categories: string[]; visibilities: string[] };
    openEvent: number | null;
    openNew?: boolean;
}>();

const locale = getActiveLanguage();
const calendar = ref<InstanceType<typeof FullCalendar> | null>(null);

// ---- filters (client side on the feed items) ----
const categories = ref<string[]>([...props.options.categories]);
const mine = ref(false);
watch([categories, mine], () => calendar.value?.getApi().refetchEvents());

// ---- feed ----
const loadError = ref('');
const loading = ref(false);
let controller: AbortController | undefined;

async function events(info: { start: Date; end: Date }) {
    controller?.abort();
    controller = new AbortController();
    loadError.value = '';
    try {
        const res = await calendarRequest<FeedItem[]>('GET', `/calendar/events?${new URLSearchParams(rangeParams(info.start, info.end))}`, undefined, controller.signal);
        if (!res.ok) {
            loadError.value = res.status === 401 || res.status === 419 ? res.message : trans('calendar.error.load');

            return [];
        }

        return filterItems(res.data, { categories: categories.value, mine: mine.value }).map((i) => mapFeedItem(i, props.timezone));
    } catch {
        return [];
    }
}

// ---- "absent today": same feed, window of today ----
const absent = ref<string[]>([]);
async function loadAbsent() {
    const today = floatingDate(wallNow(props.timezone));
    const res = await calendarRequest<FeedItem[]>('GET', `/calendar/events?${new URLSearchParams({ from: today, to: addDays(today, 1) })}`);
    if (res.ok) absent.value = absentNames(res.data, today);
}

function refresh() {
    calendar.value?.getApi().refetchEvents();
    void loadAbsent();
}

// ---- dialogs ----
const detailId = ref<number | null>(null);
const occurrence = ref<{ start: string; end: string; allDay: boolean } | null>(null);
const editOpen = ref(false);
const editDetail = ref<Detail | null>(null);
const initial = ref<{ start: string; end: string; allDay: boolean } | null>(null);
const conflicts = ref<Conflict[]>([]);
const savedNotice = ref(false);
const subscriptionOpen = ref(false);

function openCreate(slot: { start: string; end: string; allDay: boolean } | null) {
    if (!props.can.create) return;
    editDetail.value = null;
    initial.value = slot;
    editOpen.value = true;
}
function openEdit(d: Detail) {
    detailId.value = null;
    editDetail.value = d;
    initial.value = null;
    editOpen.value = true;
}
function saved(list: Conflict[]) {
    editOpen.value = false;
    conflicts.value = list;
    savedNotice.value = true;
    refresh();
}

const fcOptions = computed<CalendarOptions>(() => ({
    plugins: [dayGridPlugin, timeGridPlugin, listPlugin, interactionPlugin],
    initialView: 'dayGridMonth',
    timeZone: 'UTC',
    now: () => wallNow(props.timezone),
    locale: locale === 'de' ? deLocale : 'en',
    firstDay: 1,
    weekNumberCalculation: 'ISO',
    headerToolbar: { left: 'prev,next today', center: 'title', right: 'dayGridMonth,timeGridWeek,listWeek' },
    buttonText: { today: trans('calendar.view.today'), month: trans('calendar.view.month'), week: trans('calendar.view.week'), list: trans('calendar.view.list') },
    eventTimeFormat: { hour: '2-digit', minute: '2-digit', hour12: false },
    slotLabelFormat: { hour: '2-digit', minute: '2-digit', hour12: false },
    height: 'auto',
    nowIndicator: true,
    dayMaxEvents: 4,
    eventInteractive: true, // events get tabindex and open on Enter/Space
    selectable: false,
    editable: false,
    events: (info, success, failure) => events(info).then(success, failure),
    loading: (v: boolean) => (loading.value = v),
    eventClick: (arg) => {
        arg.jsEvent.preventDefault();
        const item = arg.event.extendedProps.item as FeedItem;
        occurrence.value = { start: item.occurrence_start, end: item.occurrence_end, allDay: item.all_day };
        detailId.value = item.id;
    },
    dateClick: (arg) => {
        const start = arg.allDay ? floatingDate(arg.date) : floatingWall(arg.date);
        openCreate({ start, end: arg.allDay ? start : addMinutes(start, 60), allDay: arg.allDay });
    },
}));

onMounted(() => {
    void loadAbsent();
    if (props.openEvent !== null) {
        occurrence.value = null;
        detailId.value = props.openEvent;
    }
    if (props.openNew) openCreate(null);
});
onBeforeUnmount(() => controller?.abort());

function toggleCategory(c: string) {
    categories.value = categories.value.includes(c) ? categories.value.filter((x) => x !== c) : [...categories.value, c];
}
</script>

<template>
    <div class="cal space-y-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <h1 class="text-2xl font-semibold">{{ trans('calendar.title') }}</h1>
            <div class="flex flex-wrap gap-2">
                <Button variant="outline" data-test="subscribe" @click="subscriptionOpen = true"><Rss aria-hidden="true" />{{ trans('calendar.subscription.open') }}</Button>
                <Button v-if="can.create" data-test="new" @click="openCreate(null)"><Plus aria-hidden="true" />{{ trans('calendar.new') }}</Button>
            </div>
        </div>

        <AbsenceToday :names="absent" />

        <div v-if="savedNotice" class="flex items-start justify-between gap-3 rounded-lg border border-border bg-card px-4 py-3 text-sm" role="status" data-test="saved-notice">
            <div>
                <p class="font-medium">{{ trans(conflicts.length > 0 ? 'calendar.conflicts.title' : 'calendar.saved') }}</p>
                <ul v-if="conflicts.length > 0" class="mt-1 list-disc pl-5 text-text-2">
                    <li v-for="c in conflicts" :key="c.personnel_id">{{ trans('calendar.conflicts.line', { name: c.name, events: c.events.map((e) => e.title).join(', ') }) }}</li>
                </ul>
            </div>
            <Button variant="ghost" size="icon-sm" :aria-label="trans('calendar.dismiss')" @click="savedNotice = false"><X aria-hidden="true" /></Button>
        </div>

        <fieldset class="flex flex-wrap items-center gap-x-4 gap-y-2 text-sm" data-test="filters">
            <legend class="sr-only">{{ trans('calendar.filter.label') }}</legend>
            <label v-for="c in options.categories" :key="c" class="flex items-center gap-1.5">
                <input type="checkbox" class="size-4 accent-(--accent)" :checked="categories.includes(c)" @change="toggleCategory(c)" />
                <component :is="categoryIcon(c)" class="size-4" aria-hidden="true" />{{ trans(`calendar.category.${c}`) }}
            </label>
            <label class="flex items-center gap-1.5 border-l border-border pl-4"><input v-model="mine" type="checkbox" class="size-4 accent-(--accent)" data-test="mine" />{{ trans('calendar.filter.mine') }}</label>
        </fieldset>

        <div v-if="loadError" class="flex flex-wrap items-center gap-3 rounded-md bg-(--danger-soft) px-3 py-2 text-sm text-danger" role="alert" data-test="load-error">
            {{ loadError }}
            <Button size="sm" variant="outline" @click="refresh">{{ trans('calendar.retry') }}</Button>
        </div>
        <p v-if="loading" class="text-sm text-text-2" role="status">{{ trans('calendar.loading') }}</p>

        <div class="rounded-lg border border-border bg-card p-3 shadow-(--shadow)">
            <FullCalendar ref="calendar" :options="fcOptions">
                <template #eventContent="arg: EventContentArg">
                    <span class="cal-ev">
                        <component :is="categoryIcon(arg.event.extendedProps.item.category)" class="size-3.5 shrink-0" aria-hidden="true" />
                        <span class="sr-only">{{ trans(`calendar.category.${arg.event.extendedProps.item.category}`) }}:</span>
                        <b v-if="arg.timeText" class="font-medium">{{ arg.timeText }}</b>
                        <span class="cal-ev-title">{{ arg.event.title }}</span>
                        <Lock v-if="arg.event.extendedProps.item.source_locked" class="size-3 shrink-0" aria-hidden="true" />
                        <span v-if="arg.event.extendedProps.item.source_locked" class="sr-only">{{ trans('calendar.locked') }}</span>
                    </span>
                </template>
            </FullCalendar>
        </div>

        <EventDetail :event-id="detailId" :occurrence="occurrence" :timezone="timezone" :locale="locale" @close="detailId = null" @edit="openEdit" @changed="refresh" />
        <EventDialog v-if="can.create || editDetail" :open="editOpen" :detail="editDetail" :initial="initial" :roles="roles" :options="options" :timezone="timezone" @close="editOpen = false" @saved="saved" />
        <SubscriptionDialog :open="subscriptionOpen" @close="subscriptionOpen = false" />
    </div>
</template>

<style>
.cal-c-accent { --cal-color: var(--accent); }
.cal-c-blue { --cal-color: var(--info); }
.cal-c-green { --cal-color: var(--ok); }
.cal-c-red { --cal-color: var(--danger); }
.cal-c-purple { --cal-color: var(--cal-purple); }
.cal-c-gray { --cal-color: var(--text-3); }
.cal-dot { background: var(--cal-color); }
.cal-swatch { background: color-mix(in srgb, var(--cal-color) 18%, var(--surface)); }

.cal .fc {
    --fc-border-color: var(--border);
    --fc-page-bg-color: transparent;
    --fc-neutral-bg-color: var(--fill-2);
    --fc-neutral-text-color: var(--text-2);
    --fc-today-bg-color: var(--accent-soft);
    --fc-now-indicator-color: var(--danger);
    --fc-event-text-color: var(--text);
    --fc-list-event-hover-bg-color: var(--fill-2);
    --fc-button-text-color: var(--text);
    --fc-button-bg-color: var(--btn-secondary-bg);
    --fc-button-border-color: var(--border-strong);
    --fc-button-hover-bg-color: var(--fill-4);
    --fc-button-hover-border-color: var(--border-strong);
    --fc-button-active-bg-color: var(--accent);
    --fc-button-active-border-color: var(--accent);
    color: var(--text);
}
.cal .fc .fc-button-primary:not(:disabled).fc-button-active { color: var(--on-accent); }
.cal .fc .fc-toolbar-title { font-size: 1.15rem; font-weight: 600; }
.cal .fc a { color: inherit; }
.cal .fc-daygrid-block-event,
.cal .fc-timegrid-event {
    background: color-mix(in srgb, var(--cal-color, var(--accent)) 24%, var(--surface));
    border: 1px solid color-mix(in srgb, var(--cal-color, var(--accent)) 60%, transparent);
    border-left: 4px solid var(--cal-color, var(--accent));
    color: var(--text);
}
.cal .fc-event:focus-visible { outline: 2px solid var(--text); outline-offset: 1px; }
.cal .fc-daygrid-dot-event .fc-daygrid-event-dot,
.cal .fc-list-event-dot { border-color: var(--cal-color, var(--accent)); }
.cal .cal-locked.fc-daygrid-block-event,
.cal .cal-locked.fc-timegrid-event {
    background-image: repeating-linear-gradient(135deg, transparent 0 6px, var(--fill-2) 6px 12px);
    border-style: dashed;
    border-left-style: solid;
}
.cal-ev { display: flex; min-width: 0; align-items: center; gap: 0.25rem; padding: 0 0.25rem; font-size: 0.75rem; }
.cal-ev-title { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.cal .fc-timegrid-event .cal-ev { flex-wrap: wrap; }
.cal .fc-timegrid-event .cal-ev-title { white-space: normal; }
</style>
