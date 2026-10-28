<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import 'leaflet/dist/leaflet.css';
import { onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue';
import Legend from '@/components/firetab/map/Legend.vue';
import MarkerDialog, { type MarkerForm } from '@/components/firetab/map/MarkerDialog.vue';
import Toolbar from '@/components/firetab/map/Toolbar.vue';
import ZoneDrawer from '@/components/firetab/map/ZoneDrawer.vue';
import { Button } from '@/components/ui/button';
import { xsrf } from '@/lib/asuClient';
import { usePulse } from '@/lib/realtime';
import { createLagekarte, MAP_TYPES, type MapMarker, type MapType, type MapZone, type Pct } from '@/lib/leafletMap';

type Item = { kind: 'marker' | 'zone'; id: number };
const POLL_MS = 15000;

const props = defineProps<{
    incidentId: number;
    base: string;
    canWrite: boolean;
    hasLocation: boolean;
    colors: string[];
    markers: (MapMarker & { personnel?: string | null; creator?: string | null })[];
    zones: (MapZone & { personnel?: string | null; creator?: string | null })[];
}>();

const el = ref<HTMLElement | null>(null);
const lk = shallowRef<ReturnType<typeof createLagekarte> | null>(null);
const typeKey = `lagekarte.type.${props.incidentId}`;
const stored = (() => {
    try {
        return localStorage.getItem(typeKey);
    } catch {
        return null;
    }
})();
const type = ref<MapType>((MAP_TYPES as readonly string[]).includes(stored ?? '') ? (stored as MapType) : 'satellite');
const mode = ref<'none' | 'pick' | 'zone'>('none');
const dialog = ref<MarkerForm | null>(null);
const points = ref<Pct[]>([]);
const zoneColor = ref(props.colors[0]);
const selected = ref<Item | null>(null);
const confirming = ref<string | null>(null);
const error = ref<string | null>(null);
const notice = ref<string | null>(null);
let reloading = false;
let again = false;

const reload = () => {
    if (document.hidden) return;
    if (reloading) {
        again = true;
        return;
    }
    reloading = true;
    again = false;
    router.reload({
        only: ['markers', 'zones'],
        onFinish: () => {
            reloading = false;
            if (again) reload();
        },
    });
};
usePulse(`firetab.incident.${props.incidentId}`, ['map'], reload, { pollMs: POLL_MS });

async function send(method: string, path: string, body?: unknown): Promise<boolean> {
    error.value = null;
    try {
        const res = await fetch(`${props.base}${path}`, {
            method,
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf(), 'X-Requested-With': 'XMLHttpRequest' },
            body: body === undefined ? undefined : JSON.stringify(body),
        });
        if (res.ok) {
            reload();
            return true;
        }
        const d = (await res.json().catch(() => ({}))) as { errors?: Record<string, string[]>; message?: string };
        error.value = Object.values(d.errors ?? {})[0]?.[0] ?? (res.status === 403 ? trans('firetab.map.forbidden') : trans('firetab.map.failed'));
    } catch {
        error.value = trans('firetab.map.failed');
    }
    return false;
}

const onClick = (p: Pct) => {
    if (mode.value === 'pick') {
        dialog.value = { ...(draft ?? {}), pos_x: String(p.x), pos_y: String(p.y) };
        draft = null;
        mode.value = 'none';
    } else if (mode.value === 'zone') {
        points.value = [...points.value, p];
    }
};
const onMove = async (id: number, p: Pct) => {
    if (!(await send('PATCH', `/markers/${id}`, { pos_x: p.x, pos_y: p.y }))) {
        lk.value?.resetMarkers();
        lk.value?.setMarkers(props.markers, props.canWrite);
    }
};
const onSelect = (kind: Item['kind'], id: number) => {
    if (mode.value === 'none') selected.value = { kind, id };
};

const openDialog = () => {
    dialog.value = { pos_x: String(lk.value?.center().x ?? 50), pos_y: String(lk.value?.center().y ?? 50) };
    error.value = null;
};
const saveMarker = async (payload: Record<string, string | number>) => {
    if (await send('POST', '/markers', payload)) dialog.value = null;
};
let draft: MarkerForm | null = null;
const pickOnMap = (form: MarkerForm) => {
    draft = form;
    dialog.value = null;
    mode.value = 'pick';
};
const cancelPick = () => {
    dialog.value = draft;
    draft = null;
    mode.value = 'none';
};

const startZone = () => {
    mode.value = 'zone';
    points.value = [];
    error.value = null;
};
const endZone = () => {
    mode.value = 'none';
    points.value = [];
};
const saveZone = async (z: { name: string; description: string | null; color: string }) => {
    if (await send('POST', '/zones', { ...z, points: points.value })) endZone();
};
const remove = async (kind: Item['kind'], id: number) => {
    const k = `${kind}-${id}`;
    if (confirming.value !== k) {
        confirming.value = k;
        return;
    }
    confirming.value = null;
    if (await send('DELETE', `/${kind}s/${id}`)) selected.value = null;
};
const setLocation = async () => {
    if (await send('PUT', '/location')) notice.value = trans('firetab.map.location_set');
};
const setType = (t: MapType) => {
    type.value = t;
    lk.value?.setType(t);
    try {
        localStorage.setItem(typeKey, t);
    } catch {
    }
};
const who = (i: { personnel?: string | null; creator?: string | null }) => i.personnel ?? i.creator ?? '';

onMounted(() => {
    if (!el.value) return;
    lk.value = createLagekarte({ el: el.value, incidentId: props.incidentId, type: type.value, colors: props.colors, onClick, onMove, onSelect });
    lk.value.setMarkers(props.markers, props.canWrite);
    lk.value.setZones(props.zones);
});
onBeforeUnmount(() => {
    lk.value?.destroy();
});
watch(() => props.markers, (m) => lk.value?.setMarkers(m, props.canWrite));
watch(() => props.zones, (z) => lk.value?.setZones(z));
watch([points, zoneColor], () => lk.value?.setDraft(points.value, zoneColor.value), { deep: true });
</script>

<template>
    <div class="space-y-3">
        <Toolbar :can-write="canWrite" :mode="mode" :type="type" :has-location="hasLocation" @type="setType" @marker="openDialog" @zone="startZone" @location="setLocation" />

        <p v-if="mode === 'pick'" role="status" class="rounded-md border border-info bg-(--info-soft) p-3 text-sm">
            {{ trans('firetab.map.pick_hint') }}
            <Button type="button" variant="secondary" class="ml-2 min-h-11" @click="cancelPick">{{ trans('firetab.map.cancel') }}</Button>
        </p>
        <p v-if="notice" role="status" class="text-sm text-success">{{ notice }}</p>
        <p v-if="error && !dialog && mode !== 'zone'" role="alert" class="text-sm text-danger">{{ error }}</p>

        <div ref="el" class="lk-map" role="application" :aria-label="trans('firetab.map.title')" />

        <ZoneDrawer
            v-if="mode === 'zone'"
            :points="points"
            :colors="colors"
            :error="error"
            @add="points = [...points, $event]"
            @undo="points = points.slice(0, -1)"
            @cancel="endZone"
            @color="zoneColor = $event"
            @save="saveZone"
        />

        <section class="rounded-md border border-border p-3 text-sm" :aria-label="trans('firetab.map.objects')">
            <h2 class="mb-2 font-semibold">{{ trans('firetab.map.objects') }}</h2>
            <p v-if="!markers.length && !zones.length" class="text-text-2">{{ trans('firetab.map.empty') }}</p>
            <ul class="space-y-1">
                <li
                    v-for="m in markers"
                    :key="`marker-${m.id}`"
                    class="flex flex-wrap items-center gap-2 rounded-md px-2"
                    :class="selected?.kind === 'marker' && selected.id === m.id ? 'bg-bg-2' : ''"
                >
                    <button type="button" class="min-h-11 flex-1 text-left" @click="selected = { kind: 'marker', id: m.id }">
                        {{ m.name || m.text || m.description || m.marker_type }}
                        <span class="text-text-2">· {{ m.marker_type }} · {{ m.pos_x }} / {{ m.pos_y }} · {{ who(m) }}</span>
                    </button>
                    <Button v-if="m.can_delete" type="button" variant="ghost" class="min-h-11 text-danger" @click="remove('marker', m.id)">
                        {{ confirming === `marker-${m.id}` ? trans('firetab.map.confirm_delete') : trans('firetab.map.delete') }}
                    </Button>
                </li>
                <li
                    v-for="z in zones"
                    :key="`zone-${z.id}`"
                    class="flex flex-wrap items-center gap-2 rounded-md px-2"
                    :class="selected?.kind === 'zone' && selected.id === z.id ? 'bg-bg-2' : ''"
                >
                    <button type="button" class="min-h-11 flex-1 text-left" @click="selected = { kind: 'zone', id: z.id }">
                        <span class="mr-1 inline-block size-3 rounded-sm" :style="{ background: colors.includes(z.color) ? z.color : 'var(--text-3)' }" aria-hidden="true" />
                        {{ z.name }}
                        <span class="text-text-2">· {{ trans('firetab.map.zone') }} · {{ who(z) }}</span>
                        <span v-if="z.description" class="block text-text-2">{{ z.description }}</span>
                    </button>
                    <Button v-if="z.can_delete" type="button" variant="ghost" class="min-h-11 text-danger" @click="remove('zone', z.id)">
                        {{ confirming === `zone-${z.id}` ? trans('firetab.map.confirm_delete') : trans('firetab.map.delete') }}
                    </Button>
                </li>
            </ul>
        </section>

        <Legend :colors="colors" />

        <MarkerDialog v-if="dialog" :initial="dialog" :error="error" @close="dialog = null" @pick="pickOnMap" @save="saveMarker" />
    </div>
</template>

<style>
.lk-map {
    height: 70vh;
    min-height: 24rem;
    border-radius: 0.375rem;
    z-index: 0;
}
.lk-divicon {
    background: none;
    border: 0;
}
.lk-icon {
    display: flex;
    width: 48px;
    height: 48px;
    align-items: center;
    justify-content: center;
    border-radius: 9999px;
    color: var(--white);
    font-weight: 700;
}
.lk-icon img {
    max-width: 48px;
    max-height: 48px;
}
.leaflet-touch .leaflet-bar a {
    width: 44px;
    height: 44px;
    line-height: 44px;
}
</style>
