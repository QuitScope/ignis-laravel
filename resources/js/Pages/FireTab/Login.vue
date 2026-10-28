<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import TabletLayout from '@/Layouts/TabletLayout.vue';

defineOptions({ layout: TabletLayout });

type VehicleHit = { id: number; identifier: string; name: string };

const props = defineProps<{
    q: string;
    vehicles: VehicleHit[];
    hasPersonnel: boolean;
    current: { identifier: string; name: string } | null;
}>();

const q = ref(props.q);
const search = () => router.get('/firetab/login', { q: q.value }, { only: ['q', 'vehicles'], preserveState: true, replace: true });

const form = useForm({ vehicle_id: 0 });
const signIn = (v: VehicleHit) => {
    form.vehicle_id = v.id;
    form.post('/firetab/login');
};
const signOut = () => router.post('/firetab/logout');

const input =
    'block min-h-11 w-full rounded-md border border-border bg-bg-2 px-3 py-2 text-text placeholder:text-text-3 focus-visible:outline-2 focus-visible:outline-accent';
</script>

<template>
    <div class="mx-auto max-w-xl space-y-4">
        <header>
            <h1 class="text-2xl font-semibold">{{ trans('firetab.login.title') }}</h1>
            <p class="mt-1 text-text-2">{{ trans('firetab.login.lead') }}</p>
        </header>

        <div v-if="!hasPersonnel" role="alert" class="rounded-md border border-danger bg-(--danger-soft) p-3 text-sm">
            {{ trans('crew.no_personnel') }}
        </div>

        <template v-else>
            <div v-if="current" class="flex flex-wrap items-center justify-between gap-2 rounded-md border border-info bg-(--info-soft) p-3">
                <span>{{ trans('firetab.login.current', { name: `${current.identifier} ${current.name}` }) }}</span>
                <Button variant="secondary" class="min-h-11" @click="signOut">{{ trans('firetab.logout') }}</Button>
            </div>

            <form class="flex gap-2" role="search" @submit.prevent="search">
                <label class="sr-only" for="vehicle-q">{{ trans('firetab.login.search') }}</label>
                <input
                    id="vehicle-q"
                    v-model="q"
                    type="search"
                    maxlength="50"
                    autocomplete="off"
                    :placeholder="trans('firetab.login.search_placeholder')"
                    :class="input"
                />
                <Button type="submit" class="min-h-11">{{ trans('firetab.login.search') }}</Button>
            </form>

            <p v-if="form.errors.vehicle_id" role="alert" class="rounded-md border border-danger bg-(--danger-soft) p-3 text-sm">
                {{ form.errors.vehicle_id }}
            </p>

            <ul v-if="vehicles.length" class="divide-y divide-border rounded-md border border-border">
                <li v-for="v in vehicles" :key="v.id">
                    <button
                        type="button"
                        class="flex min-h-11 w-full items-center gap-3 px-4 py-2 text-left hover:bg-bg-2 disabled:opacity-50"
                        :disabled="form.processing"
                        @click="signIn(v)"
                    >
                        <span class="font-mono text-sm text-text-2">{{ v.identifier }}</span>
                        <span class="flex-1">{{ v.name }}</span>
                        <span class="text-sm text-accent">{{ trans('firetab.login.submit') }}</span>
                    </button>
                </li>
            </ul>
            <p v-else-if="q && props.q" class="text-sm text-text-2">{{ trans('firetab.login.no_results') }}</p>
            <p v-if="vehicles.length === 20" class="text-sm text-text-3">{{ trans('firetab.login.limit_hint') }}</p>
        </template>
    </div>
</template>
