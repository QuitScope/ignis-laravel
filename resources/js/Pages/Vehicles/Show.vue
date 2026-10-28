<script setup lang="ts">
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import LoadoutTab from '@/components/vehicles/LoadoutTab.vue';
import DefectsTab from '@/components/vehicles/DefectsTab.vue';
import TacticalSymbolPreview from '@/components/vehicles/TacticalSymbolPreview.vue';
import VehicleStatusBadge from '@/components/vehicles/VehicleStatusBadge.vue';
import type { Assignable, DefectRow } from '@/components/vehicles/defects';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';

type Entry = { id: number; action: string; details: string | null; user: string | null; time: string | null };

const props = defineProps<{
    vehicle: {
        id: number;
        name: string;
        identifier: string;
        type: string;
        sort_order: number;
        service: number;
        license_plate: string | null;
        is_active: boolean;
        state: 'operable' | 'blocked' | 'inactive';
        blocking_defects_count: number;
        open_defects_count: number;
        fms_status: string | null;
        fms_label: string | null;
        fms_status_at: string | null;
        fms_status_source: string | null;
        station: string | null;
        allowed_jobs: string[] | null;
        tactical_symbol: Record<string, unknown> | null;
        tactical_symbol_name: string | null;
    };
    activity: Entry[];
    defects: DefectRow[];
    loadout: { id: number; title: string; compartment: number; items: { id: number; title: string; quantity: number }[] }[];
    users: Assignable[];
    can: { manage: boolean; manageLoadout: boolean; createDefect: boolean; deleteDefect: boolean };
}>();

const tabs = ['overview', 'defects', 'loadout', 'symbol', 'activity'] as const;
const tab = ref<(typeof tabs)[number]>('overview');
const tabLabel = (t: (typeof tabs)[number]) =>
    t === 'defects' && props.vehicle.open_defects_count > 0
        ? trans('vehicles.tab.defects_count', { count: String(props.vehicle.open_defects_count) })
        : trans(`vehicles.tab.${t}`);

const time = (iso: string | null) => (iso ? new Date(iso).toLocaleString() : '–');
const rows = () => [
    ['vehicles.field.identifier', props.vehicle.identifier],
    ['vehicles.field.type', props.vehicle.type],
    ['vehicles.field.service', trans(`vehicles.service.${props.vehicle.service}`)],
    ['vehicles.field.license_plate', props.vehicle.license_plate],
    ['vehicles.field.station', props.vehicle.station],
    ['vehicles.field.allowed_jobs', props.vehicle.allowed_jobs?.join(', ') ?? trans('vehicles.all_jobs')],
    ['vehicles.field.sort_order', String(props.vehicle.sort_order)],
    [
        'vehicles.field.fms_status',
        props.vehicle.fms_status === null
            ? null
            : `${props.vehicle.fms_status} – ${props.vehicle.fms_label}` +
              (props.vehicle.fms_status_at ? ` (${time(props.vehicle.fms_status_at)})` : '') +
              (props.vehicle.fms_status_source ? ` · ${trans(`vehicles.fms_source.${props.vehicle.fms_status_source}`)}` : ''),
    ],
];

const deleting = ref(false);
const destroy = () => router.delete(`/vehicles/${props.vehicle.id}`, { onFinish: () => (deleting.value = false) });

const card = 'rounded-lg border border-border bg-card p-4 shadow-(--shadow)';
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div class="flex flex-wrap items-center gap-3">
                <h1 class="text-2xl font-semibold">{{ vehicle.name }}</h1>
                <VehicleStatusBadge :state="vehicle.state" :blocking="vehicle.blocking_defects_count" :active="vehicle.is_active" />
            </div>
            <div class="flex gap-2">
                <Button as-child variant="ghost">
                    <Link href="/vehicles">{{ trans('vehicles.back') }}</Link>
                </Button>
                <Button v-if="can.manage" as-child>
                    <Link :href="`/vehicles/${vehicle.id}/edit`">{{ trans('vehicles.edit') }}</Link>
                </Button>
                <Button v-if="can.manage" variant="destructive" @click="deleting = true">{{ trans('vehicles.delete') }}</Button>
            </div>
        </div>

        <section :class="card">
            <div class="mb-3 flex flex-wrap gap-2" role="tablist">
                <Button
                    v-for="t in tabs"
                    :key="t"
                    :variant="tab === t ? 'default' : 'ghost'"
                    size="sm"
                    role="tab"
                    :aria-selected="tab === t"
                    @click="tab = t"
                >
                    {{ tabLabel(t) }}
                </Button>
            </div>

            <div v-if="tab === 'overview'" role="tabpanel">
                <dl class="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                    <div v-for="[label, value] in rows()" :key="String(label)">
                        <dt class="text-text-2">{{ trans(label as string) }}</dt>
                        <dd>{{ value || '–' }}</dd>
                    </div>
                    <div>
                        <dt class="text-text-2">{{ trans('vehicles.field.is_active') }}</dt>
                        <dd>{{ trans(vehicle.is_active ? 'vehicles.switch.on' : 'vehicles.switch.off') }}</dd>
                    </div>
                    <div>
                        <dt class="text-text-2">{{ trans('vehicles.field.operability') }}</dt>
                        <dd><VehicleStatusBadge :state="vehicle.state" :blocking="vehicle.blocking_defects_count" /></dd>
                    </div>
                </dl>
            </div>

            <div v-else-if="tab === 'defects'" role="tabpanel" data-slot="defects">
                <DefectsTab :vehicle-id="vehicle.id" :defects="defects" :users="users" :can="can" />
            </div>

            <div v-else-if="tab === 'loadout'" role="tabpanel" data-slot="loadout">
                <LoadoutTab :categories="loadout" :can-manage="can.manageLoadout" />
            </div>

            <div v-else-if="tab === 'symbol'" role="tabpanel" data-slot="symbol" class="space-y-2">
                <TacticalSymbolPreview :symbol="vehicle.tactical_symbol" :name="vehicle.tactical_symbol_name" :size="96" />
                <p v-if="vehicle.tactical_symbol_name" class="text-sm font-medium">{{ vehicle.tactical_symbol_name }}</p>
                <p v-if="!vehicle.tactical_symbol" class="text-sm text-text-2">{{ trans('symbols.show.empty') }}</p>
                <Link v-if="can.manage" :href="`/vehicles/${vehicle.id}/edit`" class="text-sm underline">{{ trans('symbols.show.edit') }}</Link>
            </div>

            <div v-else role="tabpanel">
                <ul v-if="activity.length" class="space-y-2 text-sm">
                    <li v-for="a in activity" :key="a.id">
                        <span class="font-medium">{{ a.action }}</span>
                        <span v-if="a.details" class="block text-text-2">{{ a.details }}</span>
                        <span class="block text-xs text-text-2">{{ time(a.time) }}<template v-if="a.user"> · {{ a.user }}</template></span>
                    </li>
                </ul>
                <p v-else class="text-sm text-text-2">{{ trans('vehicles.activity.empty') }}</p>
            </div>
        </section>

        <Dialog :open="deleting" @update:open="(v) => (deleting = v)">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{{ trans('vehicles.delete') }}</DialogTitle>
                </DialogHeader>
                <p class="text-sm">{{ trans('vehicles.delete_confirm', { name: vehicle.name }) }}</p>
                <DialogFooter>
                    <Button variant="ghost" @click="deleting = false">{{ trans('vehicles.cancel') }}</Button>
                    <Button variant="destructive" @click="destroy">{{ trans('vehicles.delete') }}</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
