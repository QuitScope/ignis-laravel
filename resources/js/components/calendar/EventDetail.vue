<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { trans } from 'laravel-vue-i18n';
import { Lock, MapPin, Repeat } from '@lucide/vue';
import ResponseButtons from '@/components/calendar/ResponseButtons.vue';
import { categoryIcon } from '@/components/calendar/icons';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { colorClass, formatRange } from '@/lib/calendar';
import { calendarRequest } from '@/lib/calendarApi';

export type Attendee = { personnel_id: number; name: string; response: string | null; is_organizer: boolean };
export type Detail = {
    id: number;
    title: string;
    description: string | null;
    location: string | null;
    starts_at: string;
    ends_at: string;
    all_day: boolean;
    color: string;
    category: string;
    visibility: string;
    track_attendance: boolean;
    recurrence_rule: string | null;
    recurrence_until: string | null;
    recurring: boolean;
    source_locked: boolean;
    creator: string | null;
    roles: { id: number; name: string }[];
    attendees: Attendee[] | null;
    my_response: string | null;
    can_respond: boolean;
    can_edit: boolean;
    can_delete: boolean;
    conflicts: { personnel_id: number; name: string; events: { id: number; title: string }[] }[];
};

// Read-only view of one event (GET /calendar/{id}); `occurrence` is the clicked occurrence of a series (time shown for it).
const props = defineProps<{ eventId: number | null; occurrence: { start: string; end: string; allDay: boolean } | null; timezone: string; locale: string }>();
const emit = defineEmits<{ close: []; edit: [detail: Detail]; changed: [] }>();

const detail = ref<Detail | null>(null);
const loading = ref(false);
const error = ref('');
const busy = ref(false);
const confirming = ref(false);
let load = 0;

async function fetchDetail(id: number | null) {
    if (id === null) return;
    const mine = ++load;
    loading.value = true;
    const res = await calendarRequest<Detail>('GET', `/calendar/${id}`);
    if (mine !== load) return;
    loading.value = false;
    if (res.ok) detail.value = res.data;
    else error.value = res.status === 404 ? trans('calendar.error.detail') : res.message;
}

watch(
    () => props.eventId,
    (id) => {
        detail.value = null;
        error.value = '';
        confirming.value = false;
        void fetchDetail(id);
    },
    { immediate: true },
);

const when = computed(() => {
    const d = detail.value;
    if (d === null) return '';
    const o = props.occurrence;

    return o ? formatRange(o.start, o.end, o.allDay, props.timezone, props.locale) : formatRange(d.starts_at, d.ends_at, d.all_day, props.timezone, props.locale);
});

async function respond(value: 'accepted' | 'declined' | 'tentative') {
    const d = detail.value;
    if (d === null || busy.value) return;
    busy.value = true;
    error.value = '';
    const res = await calendarRequest<{ response: string }>('POST', `/calendar/${d.id}/response`, { response: value });
    busy.value = false;
    if (!res.ok) {
        error.value = res.message;

        return;
    }
    d.my_response = res.data.response;
    await fetchDetail(d.id);
    emit('changed');
}

async function destroy() {
    const d = detail.value;
    if (d === null || busy.value) return;
    busy.value = true;
    const res = await calendarRequest('DELETE', `/calendar/${d.id}`);
    busy.value = false;
    if (!res.ok) {
        error.value = res.message;
        confirming.value = false;

        return;
    }
    emit('changed');
    emit('close');
}
</script>

<template>
    <Dialog :open="eventId !== null" @update:open="(v) => !v && emit('close')">
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-xl" data-test="event-detail">
            <DialogHeader>
                <DialogTitle>{{ detail?.title ?? trans('calendar.title') }}</DialogTitle>
                <DialogDescription>{{ when || (loading ? trans('calendar.loading') : '') }}</DialogDescription>
            </DialogHeader>

            <p v-if="error" class="rounded-md bg-(--danger-soft) px-3 py-2 text-sm text-danger" role="alert">{{ error }}</p>

            <div v-if="detail" class="space-y-3 text-sm">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="inline-flex items-center gap-1.5 rounded-full border border-border px-2.5 py-0.5" :class="colorClass(detail.color)">
                        <span class="cal-dot size-2.5 rounded-full" aria-hidden="true" />
                        <component :is="categoryIcon(detail.category)" class="size-3.5" aria-hidden="true" />
                        {{ trans(`calendar.category.${detail.category}`) }}
                    </span>
                    <span class="rounded-full bg-(--fill-3) px-2.5 py-0.5 text-text-2">{{ trans(`calendar.visibility.${detail.visibility}`) }}</span>
                    <span v-if="detail.recurring" class="inline-flex items-center gap-1 rounded-full bg-(--fill-3) px-2.5 py-0.5 text-text-2"><Repeat class="size-3.5" aria-hidden="true" />{{ trans('calendar.detail.recurring') }}</span>
                </div>

                <p v-if="detail.source_locked" class="flex items-start gap-2 rounded-md bg-(--fill-2) px-3 py-2" data-test="locked-note">
                    <Lock class="mt-0.5 size-4 shrink-0" aria-hidden="true" /><span>{{ trans('calendar.locked_hint') }}</span>
                </p>

                <p v-if="detail.location" class="flex items-center gap-2"><MapPin class="size-4 shrink-0 text-text-2" aria-hidden="true" /><span class="sr-only">{{ trans('calendar.detail.where') }}:</span>{{ detail.location }}</p>

                <p v-if="detail.description" class="whitespace-pre-wrap break-words" data-test="description">{{ detail.description }}</p>

                <dl class="grid grid-cols-[max-content_1fr] gap-x-4 gap-y-1 text-text-2">
                    <template v-if="detail.creator"><dt>{{ trans('calendar.detail.creator') }}</dt><dd class="text-text">{{ detail.creator }}</dd></template>
                    <template v-if="detail.roles.length > 0"><dt>{{ trans('calendar.detail.roles') }}</dt><dd class="text-text">{{ detail.roles.map((r) => r.name).join(', ') }}</dd></template>
                </dl>

                <div v-if="detail.can_respond" class="space-y-1">
                    <p class="font-medium">{{ trans('calendar.response.label') }}</p>
                    <ResponseButtons :current="detail.my_response" :busy="busy" @respond="respond" />
                </div>

                <section v-if="detail.attendees && detail.attendees.length > 0" class="space-y-1">
                    <h3 class="font-medium">{{ trans('calendar.detail.attendees') }} ({{ detail.attendees.length }})</h3>
                    <ul class="max-h-40 space-y-0.5 overflow-y-auto">
                        <li v-for="a in detail.attendees" :key="a.personnel_id" class="flex justify-between gap-3">
                            <span>{{ a.name }}</span>
                            <span class="text-text-2">{{ trans(`calendar.response.${a.response ?? 'none'}`) }}</span>
                        </li>
                    </ul>
                </section>

                <section v-if="detail.conflicts.length > 0" class="space-y-1">
                    <h3 class="font-medium">{{ trans('calendar.detail.conflicts') }}</h3>
                    <ul class="space-y-0.5 text-text-2">
                        <li v-for="c in detail.conflicts" :key="c.personnel_id">{{ trans('calendar.conflicts.line', { name: c.name, events: c.events.map((e) => e.title).join(', ') }) }}</li>
                    </ul>
                </section>

                <p v-if="detail.can_edit && detail.recurring" class="text-xs text-text-2">{{ trans('calendar.detail.series_note') }}</p>
            </div>

            <p v-if="confirming && detail" class="rounded-md bg-(--danger-soft) px-3 py-2 text-sm" role="alert" data-test="delete-confirm">{{ trans('calendar.delete_confirm', { title: detail.title }) }}</p>

            <DialogFooter>
                <template v-if="confirming">
                    <Button variant="ghost" :disabled="busy" @click="confirming = false">{{ trans('calendar.cancel') }}</Button>
                    <Button variant="destructive" :disabled="busy" data-test="delete-yes" @click="destroy">{{ trans('calendar.delete') }}</Button>
                </template>
                <template v-else>
                    <Button variant="ghost" @click="emit('close')">{{ trans('calendar.close') }}</Button>
                    <Button v-if="detail?.can_delete" variant="outline" data-test="delete" @click="confirming = true">{{ trans('calendar.delete') }}</Button>
                    <Button v-if="detail?.can_edit" data-test="edit" @click="emit('edit', detail)">{{ trans('calendar.edit') }}</Button>
                </template>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
