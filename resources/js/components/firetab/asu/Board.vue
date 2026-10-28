<script setup lang="ts">
import { Plus, Siren, TriangleAlert, Volume2, VolumeX, Wifi, WifiOff } from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import EventDialog, { type TruppPayload } from '@/components/firetab/asu/EventDialog.vue';
import MaydayDialog from '@/components/firetab/asu/MaydayDialog.vue';
import Protocol from '@/components/firetab/asu/Protocol.vue';
import TruppCard from '@/components/firetab/asu/TruppCard.vue';
import { Button } from '@/components/ui/button';
import { createAsuClient, type AsuTruppRow } from '@/lib/asuClient';
import { reportContact } from '@/lib/firetabOffline';
import { usePulse } from '@/lib/realtime';
import { derive } from '@/lib/asuState';

/** `canWrite` false = read-only (QM, finalized incident, unassigned crew): no buttons, same polling. */
const props = defineProps<{ incidentId: number; url: string; config: { consumption: number; start_bar: number }; canWrite: boolean }>();

const version = ref(0);
const clock = ref(Date.now());
const toast = ref<string | null>(null);
let toastTimer: ReturnType<typeof setTimeout> | undefined;

const { live } = usePulse(`firetab.incident.${props.incidentId}`, ['asu'], () => void client.poll(), { pollMs: 0 });
watch(live, (on) => {
    if (!on) client.restart();
});
const client = createAsuClient({
    url: props.url,
    incidentId: props.incidentId,
    readOnly: !props.canWrite,
    pollMs: () => (live.value ? 60_000 : 3000),
    onChange: () => {
        version.value++;
        reportContact(client.state.online || client.state.authExpired);
    },
    onToast: (status) => {
        toast.value = trans('firetab.asu.dropped', { status: String(status) });
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => (toast.value = null), 8000);
    },
});

const serverNow = computed(() => clock.value + client.state.offset);
const rows = computed(() => {
    void version.value;
    const events = client.view();
    return client.state.trupps.map((trupp) => {
        const own = events.filter((e) => e.trupp_id === trupp.id);
        return { trupp, events: own, state: derive(trupp, own, serverNow.value, { consumption: props.config.consumption }) };
    });
});
const allEvents = computed(() => {
    void version.value;
    return client
        .view()
        .slice()
        .sort((a, b) => b.at - a.at || b.id - a.id);
});
const writable = computed(() => props.canWrite && client.state.writable);
const alarms = computed(() => rows.value.filter((r) => r.state.tone === 'alarm' || r.state.tone === 'mayday'));
const dues = computed(() => rows.value.filter((r) => r.state.tone === 'due'));
const air = computed(() => rows.value.filter((r) => r.state.underAir));
const synced = computed(() => (void version.value, client.state.synced));
const online = computed(() => (void version.value, client.state.online));
const pending = computed(() => (void version.value, client.pending()));
const authExpired = computed(() => (void version.value, client.state.authExpired));
const timeText = computed(() => new Date(serverNow.value).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' }));

// Alarm: text in a live region (the only one) and, when sound is on, a beep for every newly alarming Trupp.
const sound = ref(false);
const alarmKey = computed(() => alarms.value.map((r) => `${r.trupp.id}:${r.state.tone}`).join(','));
const alarmText = computed(() => alarms.value.map((r) => `${r.trupp.name}: ${trans(`firetab.asu.tone.${r.state.tone}`)}`).join('; '));
const dueKey = computed(() => dues.value.map((r) => r.trupp.id).join(','));
let ctx: AudioContext | null = null;
const toggleSound = () => {
    sound.value = !sound.value;
    if (!sound.value) return;
    try {
        ctx ??= new AudioContext();
        void ctx.resume();
    } catch {
    }
};
const beep = (hz: number, times: number) => {
    try {
        ctx ??= new AudioContext();
        for (let i = 0; i < times; i++) {
            const o = ctx.createOscillator();
            const g = ctx.createGain();
            o.frequency.value = hz;
            g.gain.value = 0.2;
            o.connect(g).connect(ctx.destination);
            o.start(ctx.currentTime + i * 0.35);
            o.stop(ctx.currentTime + i * 0.35 + 0.2);
        }
    } catch {
    }
};
watch(alarmKey, (now, old) => {
    const before = new Set((old ?? '').split(',').filter(Boolean));
    if (sound.value && now.split(',').some((k) => k && !before.has(k))) beep(880, 3);
});
watch(dueKey, (now, old) => {
    const before = new Set((old ?? '').split(','));
    if (sound.value && now.split(',').some((k) => k && !before.has(k))) beep(520, 1);
});

let tick: ReturnType<typeof setInterval> | undefined;
onMounted(() => {
    client.start();
    tick = setInterval(() => (clock.value = Date.now()), 1000);
});
onBeforeUnmount(() => {
    client.stop();
    clearInterval(tick);
    clearTimeout(toastTimer);
    void ctx?.close().catch(() => undefined);
});

// ---- dialogs and actions
type Dlg = { mode: 'start' | 'druck' | 'ziel' | 'trupp'; trupp: AsuTruppRow | null } | null;
const dlg = ref<Dlg>(null);
const dlgError = ref<string | null>(null);
const mayday = ref<{ truppId: number | null } | null>(null);
const dlgState = computed(() => rows.value.find((r) => r.trupp.id === dlg.value?.trupp?.id)?.state ?? null);
const last = computed(() => Object.fromEntries((dlgState.value?.members ?? []).map((m) => [m.id, m.bar])));

const next = (id: number) => {
    const r = rows.value.find((x) => x.trupp.id === id);
    if (!r) return;
    const a = r.state.nextAction;
    if (a === 'anschliessen') dlg.value = { mode: 'start', trupp: r.trupp };
    else if (a === 'ziel') dlg.value = { mode: 'ziel', trupp: r.trupp };
    else if (a) client.record({ trupp_id: id, type: a });
};
const closeDlg = () => {
    dlg.value = null;
    dlgError.value = null;
};
const saveBars = (bars: Record<number, number | null>) => {
    const t = dlg.value?.trupp;
    if (!t) return;
    for (const [id, bar] of Object.entries(bars)) if (bar !== null) client.record({ trupp_id: t.id, type: 'druck', member_id: Number(id), bar });
    if (dlg.value?.mode === 'ziel') client.record({ trupp_id: t.id, type: 'ziel' });
    closeDlg();
};
const saveStart = (bar: number) => {
    if (dlg.value?.trupp) client.record({ trupp_id: dlg.value.trupp.id, type: 'angeschlossen', bar });
    closeDlg();
};
const firstError = async (res: Response): Promise<string> => {
    try {
        const j = (await res.json()) as { errors?: Record<string, string[]>; message?: string };
        return Object.values(j.errors ?? {})[0]?.[0] ?? j.message ?? String(res.status);
    } catch {
        return String(res.status);
    }
};
const saveTrupp = async (p: TruppPayload) => {
    const t = dlg.value?.trupp;
    try {
        const res = t ? await client.request('PUT', `/trupps/${t.id}`, p) : await client.request('POST', '/trupps', p);
        if (!res.ok) return void (dlgError.value = await firstError(res));
        closeDlg();
        await client.poll();
    } catch {
        dlgError.value = trans('firetab.asu.offline_trupp');
    }
};
const removeTrupp = async () => {
    const t = dlg.value?.trupp;
    if (!t) return;
    try {
        const res = await client.request('DELETE', `/trupps/${t.id}`);
        if (!res.ok) return void (dlgError.value = await firstError(res));
        closeDlg();
        await client.poll();
    } catch {
        dlgError.value = trans('firetab.asu.offline_trupp');
    }
};
const sendMayday = (p: { truppId: number; type: 'mayday' | 'mayday_ende'; memberId: number | null; note: string | null }) => {
    client.record({ trupp_id: p.truppId, type: p.type, member_id: p.memberId, note: p.note });
    mayday.value = null;
};
</script>

<template>
    <section class="asu-board" :aria-label="trans('firetab.asu.title')">
        <div class="flex flex-wrap items-center gap-3">
            <h2 class="text-lg font-semibold">{{ trans('firetab.asu.title') }}</h2>
            <span class="font-mono text-xl font-bold">{{ synced ? timeText : '--:--:--' }}</span>
            <span class="inline-flex items-center gap-1 text-sm" :class="online ? 'text-text-2' : 'font-semibold text-danger'">
                <component :is="online ? Wifi : WifiOff" class="size-4" aria-hidden="true" />
                {{ online ? trans('firetab.asu.online') : trans('firetab.asu.offline') }}<template v-if="pending"> · {{ trans('firetab.asu.pending', { n: String(pending) }) }}</template>
            </span>
            <Button v-if="canWrite" type="button" variant="ghost" class="min-h-11" :aria-pressed="sound" @click="toggleSound">
                <component :is="sound ? Volume2 : VolumeX" class="size-4" aria-hidden="true" />{{ sound ? trans('firetab.asu.sound_on') : trans('firetab.asu.sound_off') }}
            </Button>
            <div v-if="writable" class="ml-auto flex flex-wrap gap-2">
                <Button type="button" class="min-h-11" @click="dlg = { mode: 'trupp', trupp: null }"><Plus class="size-4" aria-hidden="true" />{{ trans('firetab.asu.add_trupp') }}</Button>
                <Button type="button" variant="destructive" class="min-h-11" :disabled="!rows.length" @click="mayday = { truppId: null }"><Siren class="size-4" aria-hidden="true" />{{ trans('firetab.asu.mayday') }}</Button>
            </div>
        </div>

        <p v-if="!writable && pending" class="rounded-md border border-danger p-3 text-sm font-semibold">{{ trans('firetab.asu.pending', { n: String(pending) }) }}</p>
        <p v-if="!synced" class="text-text-2">{{ trans('firetab.asu.loading') }}</p>

        <ul class="flex flex-wrap gap-2 text-sm font-semibold">
            <li class="rounded-md border border-border px-3 py-1"><TriangleAlert class="mr-1 inline size-4" aria-hidden="true" />{{ trans('firetab.asu.count_alarm') }}: {{ alarms.length }}</li>
            <li class="rounded-md border border-border px-3 py-1">{{ trans('firetab.asu.count_due') }}: {{ dues.length }}</li>
            <li class="rounded-md border border-border px-3 py-1">{{ trans('firetab.asu.count_air') }}: {{ air.length }}</li>
        </ul>

        <!-- The one live region: only alarms (below retreat pressure, overdue, MAYDAY) are announced. -->
        <p class="sr-only" aria-live="assertive" role="alert">{{ alarmText }}</p>
        <p v-if="authExpired" role="status" class="rounded-md border border-danger p-3 text-sm font-semibold text-danger">{{ trans('firetab.asu.session_expired') }}</p>
        <p v-if="toast" role="status" class="rounded-md border border-danger p-3 text-sm">{{ toast }}</p>

        <div v-if="rows.length" class="grid gap-3 lg:grid-cols-2">
            <TruppCard
                v-for="r in rows"
                :key="r.trupp.id"
                :trupp="r.trupp"
                :state="r.state"
                :writable="writable"
                @next="next(r.trupp.id)"
                @druck="dlg = { mode: 'druck', trupp: r.trupp }"
                @ask="(type) => client.record({ trupp_id: r.trupp.id, type })"
                @mayday="mayday = { truppId: r.trupp.id }"
                @edit="dlg = { mode: 'trupp', trupp: r.trupp }"
            />
        </div>
        <p v-else-if="synced" class="rounded-md border border-dashed border-border p-6 text-center text-text-2">{{ trans('firetab.asu.empty') }}</p>

        <Protocol :events="allEvents" :trupps="client.state.trupps" :writable="writable" @void="(id) => client.voidEvent(id)" />

        <EventDialog
            v-if="dlg"
            :mode="dlg.mode"
            :trupp="dlg.trupp"
            :start-bar="config.start_bar"
            :last="last"
            :error="dlgError"
            @close="closeDlg"
            @start="saveStart"
            @bars="saveBars"
            @trupp="saveTrupp"
            @remove="removeTrupp"
        />
        <MaydayDialog v-if="mayday" :trupps="rows" :trupp-id="mayday.truppId" @close="mayday = null" @send="sendMayday" />
    </section>
</template>

<style scoped>
.asu-board { display: grid; gap: 12px; }
</style>
