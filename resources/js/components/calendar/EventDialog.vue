<script setup lang="ts">
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { trans } from 'laravel-vue-i18n';
import { X } from '@lucide/vue';
import type { Detail } from '@/components/calendar/EventDetail.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    addMinutes,
    blankRecurrence,
    buildRule,
    colorClass,
    isoWeekday,
    MAX_ATTENDEES,
    parseRule,
    timeError,
    toInputValue,
    toWall,
    withStartWeekday,
    type Recurrence,
} from '@/lib/calendar';
import { calendarRequest } from '@/lib/calendarApi';

export type Conflict = { personnel_id: number; name: string; events: { id: number; title: string }[] };
type Person = { id: number; name: string };

// Create (detail null) or edit one event. Times: all-day => dates, else `YYYY-MM-DDTHH:mm` without offset, which the
// server reads as wall time in the organisation zone (`timezone`), so no conversion happens here.
const props = defineProps<{
    open: boolean;
    detail: Detail | null;
    initial: { start: string; end: string; allDay: boolean } | null;
    roles: { id: number; name: string }[];
    options: { colors: string[]; categories: string[]; visibilities: string[] };
    timezone: string;
}>();
const emit = defineEmits<{ close: []; saved: [conflicts: Conflict[]] }>();

const blank = () => ({
    title: '',
    description: '',
    location: '',
    allDay: false,
    start: '',
    end: '',
    color: 'accent',
    category: 'general',
    visibility: 'all',
    track: false,
    roleIds: [] as number[],
    attendees: [] as Person[],
    rec: blankRecurrence() as Recurrence,
});
const form = reactive(blank());
const errors = ref<Record<string, string>>({});
const message = ref('');
const busy = ref(false);
const query = ref('');
const results = ref<Person[]>([]);
const searched = ref(false);
let timer: ReturnType<typeof setTimeout> | undefined;
let controller: AbortController | undefined;

watch(
    () => [props.open, props.detail] as const,
    ([open, d]) => {
        if (!open) return;
        Object.assign(form, blank());
        errors.value = {};
        message.value = '';
        query.value = '';
        results.value = [];
        if (d === null) {
            const now = toWall(new Date(), props.timezone).slice(0, 13);
            const start = props.initial?.start ?? `${now}:00`;
            Object.assign(form, { allDay: props.initial?.allDay ?? false, start, end: props.initial?.end ?? addMinutes(start, 60) });
        } else {
            Object.assign(form, {
                title: d.title,
                description: d.description ?? '',
                location: d.location ?? '',
                allDay: d.all_day,
                start: toInputValue(d.starts_at, d.all_day, props.timezone),
                end: toInputValue(d.ends_at, d.all_day, props.timezone),
                color: d.color,
                category: d.category,
                visibility: d.visibility,
                track: d.track_attendance,
                roleIds: d.roles.map((r) => r.id),
                attendees: (d.attendees ?? []).filter((a) => !a.is_organizer && d.visibility !== 'role').map((a) => ({ id: a.personnel_id, name: a.name })),
                rec: parseRule(d.recurrence_rule, d.recurrence_until === null ? null : toInputValue(d.recurrence_until, d.all_day, props.timezone).slice(0, 10)),
            });
        }
    },
    { immediate: true },
);

function setAllDay(on: boolean) {
    form.allDay = on;
    if (on) {
        form.start = form.start.slice(0, 10);
        form.end = form.end.slice(0, 10);
    } else {
        form.start = `${form.start.slice(0, 10)}T09:00`;
        form.end = `${form.end.slice(0, 10)}T10:00`;
    }
}

watch(
    () => [form.rec.freq, form.start.slice(0, 10)] as const,
    () => {
        form.rec = withStartWeekday(form.rec, form.start.slice(0, 10));
    },
);
function toggleDay(day: number) {
    const on = form.rec.weekdays.includes(day);
    if (on && day === startDay.value) return;
    form.rec.weekdays = on ? form.rec.weekdays.filter((d) => d !== day) : [...form.rec.weekdays, day].sort((a, b) => a - b);
}
const startDay = computed(() => (form.start === '' ? 0 : isoWeekday(form.start)));

const clientTimeError = computed(() => {
    const key = timeError({ allDay: form.allDay, start: form.start, end: form.end, recurring: form.rec.freq !== 'NONE' });

    return key === null ? '' : trans(key);
});

const rule = computed(() => buildRule(form.rec));
const summary = computed(() => {
    const r = form.rec;
    if (r.freq === 'NONE') return '';
    const parts = [trans('calendar.recurrence.every', { n: String(r.interval), unit: trans(`calendar.recurrence.unit.${r.freq.toLowerCase()}`) })];
    if (r.freq === 'WEEKLY') parts.push(trans('calendar.recurrence.on', { days: r.weekdays.map((d) => trans(`calendar.weekday.${d}`)).join(', ') }));
    parts.push(r.end === 'count' ? trans('calendar.recurrence.ends_count', { count: String(r.count) }) : r.end === 'until' && r.until !== '' ? trans('calendar.recurrence.ends_until', { date: r.until }) : trans('calendar.recurrence.ends_never'));

    return parts.join(', ');
});

// ---- attendee picker (server-side search, never the whole personnel list) ----

watch(query, (q) => {
    clearTimeout(timer);
    controller?.abort();
    if (q.trim() === '') {
        results.value = [];
        searched.value = false;

        return;
    }
    timer = setTimeout(async () => {
        controller = new AbortController();
        try {
            const res = await calendarRequest<Person[]>('GET', `/calendar/personnel?${new URLSearchParams({ q: q.trim() })}`, undefined, controller.signal);
            if (res.ok) {
                results.value = res.data.filter((p) => !form.attendees.some((a) => a.id === p.id));
                searched.value = true;
            } else message.value = res.message;
        } catch {
        }
    }, 250);
});
onBeforeUnmount(() => {
    clearTimeout(timer);
    controller?.abort();
});

function addAttendee(p: Person) {
    if (form.attendees.length >= MAX_ATTENDEES) return;
    form.attendees.push(p);
    results.value = results.value.filter((r) => r.id !== p.id);
}
const removeAttendee = (id: number) => (form.attendees = form.attendees.filter((a) => a.id !== id));

function toggleRole(id: number) {
    form.roleIds = form.roleIds.includes(id) ? form.roleIds.filter((r) => r !== id) : [...form.roleIds, id];
}

async function save() {
    if (busy.value) return;
    errors.value = {};
    message.value = '';
    if (clientTimeError.value !== '') {
        errors.value = { ends_at: clientTimeError.value };

        return;
    }
    if (form.rec.freq !== 'NONE' && form.rec.end === 'until' && form.rec.until === '') {
        errors.value = { recurrence_until: trans('calendar.error.until_required') };

        return;
    }
    if (form.visibility === 'role' && form.roleIds.length === 0) {
        errors.value = { role_ids: trans('calendar.roles_required') };

        return;
    }
    const payload = {
        title: form.title,
        description: form.description,
        location: form.location,
        all_day: form.allDay,
        starts_at: form.start,
        ends_at: form.end,
        color: form.color,
        category: form.category,
        visibility: form.visibility,
        track_attendance: form.visibility === 'role' && form.track,
        role_ids: form.visibility === 'role' ? form.roleIds : [],
        attendee_ids: ['attendees', 'private'].includes(form.visibility) ? form.attendees.map((a) => a.id) : [],
        ...rule.value,
    };
    busy.value = true;
    const res = props.detail === null ? await calendarRequest<{ conflicts: Conflict[] }>('POST', '/calendar', payload) : await calendarRequest<{ conflicts: Conflict[] }>('PUT', `/calendar/${props.detail.id}`, payload);
    busy.value = false;
    if (res.ok) {
        emit('saved', res.data.conflicts ?? []);

        return;
    }
    errors.value = res.errors;
    message.value = res.message;
}

const field = 'w-full rounded-md border border-border bg-card px-3 py-2 text-sm';
const err = 'text-xs text-danger';
const dateKind = computed(() => (form.allDay ? 'date' : 'datetime-local'));
</script>

<template>
    <Dialog :open="open" @update:open="(v) => !v && emit('close')">
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-2xl" data-test="event-dialog">
            <DialogHeader>
                <DialogTitle>{{ trans(detail === null ? 'calendar.new' : 'calendar.edit') }}</DialogTitle>
                <DialogDescription>{{ trans('calendar.timezone_hint', { zone: timezone }) }}</DialogDescription>
            </DialogHeader>

            <form class="grid gap-3 sm:grid-cols-2" novalidate @submit.prevent="save">
                <p v-if="message" class="rounded-md bg-(--danger-soft) px-3 py-2 text-sm text-danger sm:col-span-2" role="alert" data-test="form-error">{{ message }}</p>

                <div class="space-y-1 sm:col-span-2">
                    <Label for="ev-title">{{ trans('calendar.field.title') }}</Label>
                    <Input id="ev-title" v-model="form.title" maxlength="160" required :aria-invalid="!!errors.title" />
                    <p v-if="errors.title" :class="err">{{ errors.title }}</p>
                </div>

                <div class="space-y-1 sm:col-span-2">
                    <Label for="ev-desc">{{ trans('calendar.field.description') }}</Label>
                    <textarea id="ev-desc" v-model="form.description" rows="3" maxlength="2000" :class="field" />
                    <p v-if="errors.description" :class="err">{{ errors.description }}</p>
                </div>

                <div class="space-y-1 sm:col-span-2">
                    <Label for="ev-loc">{{ trans('calendar.field.location') }}</Label>
                    <Input id="ev-loc" v-model="form.location" maxlength="255" />
                    <p v-if="errors.location" :class="err">{{ errors.location }}</p>
                </div>

                <label class="flex items-center gap-2 text-sm sm:col-span-2">
                    <input type="checkbox" class="size-4 accent-(--accent)" :checked="form.allDay" data-test="all-day" @change="setAllDay(($event.target as HTMLInputElement).checked)" />
                    {{ trans('calendar.field.all_day') }}
                </label>

                <div class="space-y-1">
                    <Label for="ev-start">{{ trans(form.allDay ? 'calendar.field.start_date' : 'calendar.field.start') }}</Label>
                    <Input id="ev-start" v-model="form.start" :type="dateKind" required :aria-invalid="!!errors.starts_at" />
                    <p v-if="errors.starts_at" :class="err">{{ errors.starts_at }}</p>
                </div>
                <div class="space-y-1">
                    <Label for="ev-end">{{ trans(form.allDay ? 'calendar.field.end_date' : 'calendar.field.end') }}</Label>
                    <Input id="ev-end" v-model="form.end" :type="dateKind" :min="form.start" required :aria-invalid="!!errors.ends_at || clientTimeError !== ''" aria-describedby="ev-end-err" />
                    <p id="ev-end-err" :class="err" role="status">{{ errors.ends_at ?? clientTimeError }}</p>
                </div>

                <div class="space-y-1">
                    <Label for="ev-cat">{{ trans('calendar.field.category') }}</Label>
                    <select id="ev-cat" v-model="form.category" :class="field">
                        <option v-for="c in options.categories" :key="c" :value="c">{{ trans(`calendar.category.${c}`) }}</option>
                    </select>
                    <p v-if="errors.category" :class="err">{{ errors.category }}</p>
                </div>
                <fieldset class="space-y-1">
                    <legend class="text-sm font-medium">{{ trans('calendar.field.color') }}</legend>
                    <div class="flex flex-wrap gap-2">
                        <label v-for="c in options.colors" :key="c" class="cursor-pointer">
                            <input v-model="form.color" type="radio" name="ev-color" :value="c" class="peer sr-only" />
                            <span class="cal-swatch inline-flex items-center gap-1.5 rounded-full border border-border px-2.5 py-1 text-xs peer-checked:ring-2 peer-checked:ring-(--text) peer-focus-visible:ring-2 peer-focus-visible:ring-(--text)" :class="colorClass(c)">
                                <span class="cal-dot size-2.5 rounded-full" aria-hidden="true" />{{ trans(`calendar.color.${c}`) }}
                            </span>
                        </label>
                    </div>
                    <p v-if="errors.color" :class="err">{{ errors.color }}</p>
                </fieldset>

                <div class="space-y-1 sm:col-span-2">
                    <Label for="ev-vis">{{ trans('calendar.field.visibility') }}</Label>
                    <select id="ev-vis" v-model="form.visibility" :class="field" aria-describedby="ev-vis-hint">
                        <option v-for="v in options.visibilities" :key="v" :value="v">{{ trans(`calendar.visibility.${v}`) }}</option>
                    </select>
                    <p id="ev-vis-hint" class="text-xs text-text-2">{{ trans(`calendar.visibility.${form.visibility}_hint`) }}</p>
                    <p v-if="errors.visibility" :class="err">{{ errors.visibility }}</p>
                </div>

                <fieldset v-if="form.visibility === 'role'" class="space-y-2 sm:col-span-2">
                    <legend class="text-sm font-medium">{{ trans('calendar.field.roles') }}</legend>
                    <div class="flex max-h-32 flex-wrap gap-x-4 gap-y-1 overflow-y-auto">
                        <label v-for="r in roles" :key="r.id" class="flex items-center gap-2 text-sm">
                            <input type="checkbox" class="size-4 accent-(--accent)" :checked="form.roleIds.includes(r.id)" @change="toggleRole(r.id)" />{{ r.name }}
                        </label>
                    </div>
                    <p v-if="errors.role_ids" :class="err">{{ errors.role_ids }}</p>
                    <label class="flex items-center gap-2 text-sm"><input v-model="form.track" type="checkbox" class="size-4 accent-(--accent)" />{{ trans('calendar.field.track') }}</label>
                </fieldset>

                <section v-if="form.visibility === 'attendees' || form.visibility === 'private'" class="space-y-2 sm:col-span-2">
                    <Label for="ev-att">{{ trans('calendar.field.attendees') }}</Label>
                    <ul v-if="form.attendees.length > 0" class="flex flex-wrap gap-2" data-test="chips">
                        <li v-for="a in form.attendees" :key="a.id" class="inline-flex items-center gap-1 rounded-full bg-(--fill-3) py-0.5 pl-2.5 pr-1 text-sm">
                            {{ a.name }}
                            <button type="button" class="rounded-full p-0.5 hover:bg-(--fill-4)" :aria-label="trans('calendar.attendees.remove', { name: a.name })" @click="removeAttendee(a.id)"><X class="size-3.5" aria-hidden="true" /></button>
                        </li>
                    </ul>
                    <Input id="ev-att" v-model="query" type="search" autocomplete="off" :placeholder="trans('calendar.attendees.search')" :disabled="form.attendees.length >= MAX_ATTENDEES" />
                    <p class="text-xs text-text-2">{{ trans('calendar.attendees.count', { count: String(form.attendees.length), max: String(MAX_ATTENDEES) }) }}</p>
                    <ul v-if="results.length > 0" class="max-h-40 overflow-y-auto rounded-md border border-border" data-test="results">
                        <li v-for="p in results" :key="p.id">
                            <button type="button" class="block w-full px-3 py-1.5 text-left text-sm hover:bg-(--fill-2) focus-visible:bg-(--fill-2)" :aria-label="trans('calendar.attendees.add', { name: p.name })" @click="addAttendee(p)">{{ p.name }}</button>
                        </li>
                    </ul>
                    <p v-else-if="searched && query.trim() !== ''" class="text-xs text-text-2" role="status">{{ trans('calendar.attendees.none') }}</p>
                    <p v-if="errors.attendee_ids" :class="err">{{ errors.attendee_ids }}</p>
                </section>

                <fieldset class="space-y-2 rounded-md border border-border p-3 sm:col-span-2">
                    <legend class="px-1 text-sm font-medium">{{ trans('calendar.recurrence.title') }}</legend>
                    <div class="flex flex-wrap items-end gap-3">
                        <div class="space-y-1">
                            <Label for="ev-freq" class="sr-only">{{ trans('calendar.recurrence.title') }}</Label>
                            <select id="ev-freq" v-model="form.rec.freq" :class="field">
                                <option value="NONE">{{ trans('calendar.recurrence.none') }}</option>
                                <option value="DAILY">{{ trans('calendar.recurrence.daily') }}</option>
                                <option value="WEEKLY">{{ trans('calendar.recurrence.weekly') }}</option>
                                <option value="MONTHLY">{{ trans('calendar.recurrence.monthly') }}</option>
                            </select>
                        </div>
                        <template v-if="form.rec.freq !== 'NONE'">
                            <div class="space-y-1">
                                <Label for="ev-int">{{ trans('calendar.recurrence.interval') }}</Label>
                                <div class="flex items-center gap-2">
                                    <Input id="ev-int" v-model.number="form.rec.interval" type="number" min="1" max="999" class="w-20" />
                                    <span class="text-sm">{{ trans(`calendar.recurrence.unit.${form.rec.freq.toLowerCase()}`) }}</span>
                                </div>
                            </div>
                            <div class="space-y-1">
                                <Label for="ev-rend">{{ trans('calendar.recurrence.end') }}</Label>
                                <select id="ev-rend" v-model="form.rec.end" :class="field">
                                    <option value="never">{{ trans('calendar.recurrence.end_never') }}</option>
                                    <option value="count">{{ trans('calendar.recurrence.end_count') }}</option>
                                    <option value="until">{{ trans('calendar.recurrence.end_until') }}</option>
                                </select>
                            </div>
                            <div v-if="form.rec.end === 'count'" class="space-y-1">
                                <Label for="ev-rcount">{{ trans('calendar.recurrence.count') }}</Label>
                                <Input id="ev-rcount" v-model.number="form.rec.count" type="number" min="1" max="9999" class="w-24" />
                            </div>
                            <div v-if="form.rec.end === 'until'" class="space-y-1">
                                <Label for="ev-runtil">{{ trans('calendar.recurrence.until') }}</Label>
                                <Input id="ev-runtil" v-model="form.rec.until" type="date" :min="form.start.slice(0, 10)" />
                            </div>
                        </template>
                    </div>
                    <div v-if="form.rec.freq === 'WEEKLY'" class="space-y-1" role="group" :aria-label="trans('calendar.recurrence.weekdays')">
                        <p class="text-sm">{{ trans('calendar.recurrence.weekdays') }}</p>
                        <div class="flex flex-wrap gap-2">
                            <label v-for="d in 7" :key="d" class="flex items-center gap-1 text-sm">
                                <input type="checkbox" class="size-4 accent-(--accent)" :checked="form.rec.weekdays.includes(d)" :disabled="d === startDay" @change="toggleDay(d)" />{{ trans(`calendar.weekday.${d}`) }}
                            </label>
                        </div>
                        <p class="text-xs text-text-2">{{ trans('calendar.recurrence.start_day_hint') }}</p>
                    </div>
                    <p v-if="form.rec.freq !== 'NONE'" class="text-xs text-text-2" data-test="rule-summary">
                        {{ summary }} <span class="ml-1">{{ trans('calendar.recurrence.rule') }}</span> <code class="font-mono">{{ rule.recurrence_rule }}</code>
                    </p>
                    <p v-if="errors.recurrence_rule" :class="err">{{ errors.recurrence_rule }}</p>
                    <p v-if="errors.recurrence_until" :class="err">{{ errors.recurrence_until }}</p>
                </fieldset>

                <DialogFooter class="sm:col-span-2">
                    <Button type="button" variant="ghost" @click="emit('close')">{{ trans('calendar.cancel') }}</Button>
                    <Button type="submit" :disabled="busy || clientTimeError !== ''" data-test="save">{{ trans('calendar.save') }}</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
