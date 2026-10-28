<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';
import SearchPick, { type Hit } from '@/components/firetab/SearchPick.vue';
import { Button } from '@/components/ui/button';
import TabletLayout from '@/Layouts/TabletLayout.vue';

defineOptions({ layout: TabletLayout });

type VehicleHit = { id: number; identifier: string; name: string; taken: number[] | null };

const props = defineProps<{
    q: string;
    vehicles: VehicleHit[];
    me: string | null;
    current: { identifier: string; name: string } | null;
    pinRequired: boolean;
}>();

const q = ref(props.q);
const search = () => router.get('/enotf/login', { q: q.value }, { only: ['q', 'vehicles'], preserveState: true, replace: true });

const vehicle = ref<VehicleHit | null>(null);
const codriver = ref<Hit | null>(null);
const trainee = ref<Hit | null>(null);
const form = useForm({
    vehicle_id: 0,
    codriver_id: null as number | null,
    trainee_id: null as number | null,
    pin: '',
});
const signIn = () => {
    if (!vehicle.value) return;
    if (vehicle.value.taken && !window.confirm(trans('enotf.crew.replace_confirm'))) return;
    form.vehicle_id = vehicle.value.id;
    form.codriver_id = codriver.value?.id ?? null;
    form.trainee_id = trainee.value?.id ?? null;
    form.post('/enotf/login');
};
const membersError = computed(() => (form.errors as Record<string, string | undefined>).members);
const signOut = () => router.post('/enotf/logout');
const freeSeats = computed(() => (vehicle.value?.taken ? [1, 2, 3].filter((p) => !vehicle.value?.taken?.includes(p)) : []));
const seatKeys: Record<number, string> = { 1: 'enotf.login.driver', 2: 'enotf.login.codriver', 3: 'enotf.login.trainee' };
const joinError = ref('');
const join = (position: number) =>
    router.post(
        '/enotf/crew/join',
        { vehicle_id: vehicle.value?.id ?? 0, position, pin: form.pin },
        { onError: (e) => (joinError.value = e.pin ?? e.position ?? e.vehicle_id ?? e.members ?? '') },
    );

const input =
    'block min-h-11 w-full rounded-md border border-border bg-bg-2 px-3 py-2 text-text placeholder:text-text-3 focus-visible:outline-2 focus-visible:outline-accent';
</script>

<template>
    <div class="mx-auto max-w-xl space-y-4">
        <header>
            <h1 class="text-2xl font-semibold">
                {{ trans('enotf.login.title') }}
            </h1>
            <p class="mt-1 text-text-2">{{ trans('enotf.login.lead') }}</p>
        </header>

        <div v-if="!me" role="alert" class="rounded-md border border-danger bg-(--danger-soft) p-3 text-sm">
            {{ trans('crew.no_personnel') }}
        </div>

        <template v-else>
            <div v-if="current" class="flex flex-wrap items-center justify-between gap-2 rounded-md border border-info bg-(--info-soft) p-3">
                <span>{{
                    trans('enotf.login.current', {
                        name: `${current.identifier} ${current.name}`,
                    })
                }}</span>
                <Button variant="secondary" class="min-h-11" @click="signOut">{{ trans('enotf.logout') }}</Button>
            </div>

            <p v-if="form.errors.vehicle_id" role="alert" class="rounded-md border border-danger bg-(--danger-soft) p-3 text-sm">
                {{ form.errors.vehicle_id }}
            </p>

            <template v-if="!vehicle">
                <form class="flex gap-2" role="search" @submit.prevent="search">
                    <label class="sr-only" for="vehicle-q">{{ trans('enotf.login.search') }}</label>
                    <input
                        id="vehicle-q"
                        v-model="q"
                        type="search"
                        maxlength="50"
                        autocomplete="off"
                        :placeholder="trans('enotf.login.search_placeholder')"
                        :class="input"
                    />
                    <Button type="submit" class="min-h-11">{{ trans('enotf.login.search') }}</Button>
                </form>

                <ul v-if="vehicles.length" class="divide-y divide-border rounded-md border border-border">
                    <li v-for="v in vehicles" :key="v.id">
                        <button type="button" class="flex min-h-11 w-full items-center gap-3 px-4 py-2 text-left hover:bg-bg-2" @click="vehicle = v">
                            <span class="font-mono text-sm text-text-2">{{ v.identifier }}</span>
                            <span class="flex-1">{{ v.name }}</span>
                        </button>
                    </li>
                </ul>
                <p v-else-if="q && props.q" class="text-sm text-text-2">
                    {{ trans('enotf.login.no_results') }}
                </p>
                <p v-if="vehicles.length === 20" class="text-sm text-text-3">
                    {{ trans('enotf.login.limit_hint') }}
                </p>
            </template>

            <form v-else class="space-y-4" @submit.prevent="signIn">
                <div class="flex flex-wrap items-center justify-between gap-2 rounded-md border border-border p-3">
                    <span class="font-semibold">{{
                        trans('enotf.login.vehicle', {
                            name: `${vehicle.identifier} ${vehicle.name}`,
                        })
                    }}</span>
                    <Button type="button" variant="secondary" class="min-h-11" @click="vehicle = null">{{ trans('enotf.login.change_vehicle') }}</Button>
                </div>

                <div v-if="vehicle.taken" class="space-y-2 rounded-md border border-info bg-(--info-soft) p-3">
                    <p>{{ trans('enotf.crew.active') }}</p>
                    <div class="flex flex-wrap gap-2">
                        <Button v-for="p in freeSeats" :key="p" type="button" class="min-h-11" @click="join(p)">
                            {{ trans('enotf.crew.join_as', { position: trans(seatKeys[p] ?? '') }) }}
                        </Button>
                    </div>
                    <p v-if="joinError" role="alert" class="text-sm text-danger">{{ joinError }}</p>
                </div>

                <div class="rounded-md border border-border p-3">
                    <div class="text-sm text-text-2">
                        {{ trans('enotf.login.driver') }}
                    </div>
                    <div>{{ me }}</div>
                </div>

                <div
                    v-for="slot in [
                        { key: 'codriver', model: codriver },
                        { key: 'trainee', model: trainee },
                    ]"
                    :key="slot.key"
                    class="space-y-2 rounded-md border border-border p-3"
                >
                    <div class="text-sm text-text-2">
                        {{ trans(`enotf.login.${slot.key}`) }}
                    </div>
                    <div v-if="slot.model" class="flex items-center justify-between gap-2">
                        <span>{{ slot.model.name }}</span>
                        <Button type="button" variant="secondary" class="min-h-11" @click="slot.key === 'codriver' ? (codriver = null) : (trainee = null)">
                            {{ trans('enotf.login.remove') }}
                        </Button>
                    </div>
                    <SearchPick
                        v-else
                        url="/enotf/personnel/search"
                        :placeholder="trans('enotf.login.member_search')"
                        @pick="(h) => (slot.key === 'codriver' ? (codriver = h) : (trainee = h))"
                    />
                </div>

                <div v-if="pinRequired" class="space-y-1 rounded-md border border-border p-3">
                    <label for="enotf-login-pin" class="text-sm text-text-2">{{ trans('enotf.login.pin') }}</label>
                    <input id="enotf-login-pin" v-model="form.pin" type="password" inputmode="numeric" autocomplete="off" maxlength="8" :class="input" />
                    <p class="text-xs text-text-3">{{ trans('enotf.login.pin_hint') }}</p>
                    <p v-if="form.errors.pin" role="alert" class="text-sm text-danger">{{ form.errors.pin }}</p>
                </div>

                <p v-if="membersError" role="alert" class="rounded-md border border-danger bg-(--danger-soft) p-3 text-sm">
                    {{ membersError }}
                </p>

                <Button type="submit" class="min-h-11 w-full" :disabled="form.processing">{{
                    trans(vehicle.taken ? 'enotf.crew.replace' : 'enotf.login.submit')
                }}</Button>
            </form>
        </template>
    </div>
</template>
