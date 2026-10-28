<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { ref } from 'vue';
import TabletLayout from '@/Layouts/TabletLayout.vue';
import { usePulse } from '@/lib/realtime';

defineOptions({ layout: TabletLayout });

const props = defineProps<{
    vehicle: { id: number; identifier: string; name: string; status: string | null; label: string | null; at: string | null };
    incident: { id: number; number: string | null; keyword: string | null; location: string | null } | null;
    buttons: { code: string; label: string }[];
}>();

const busy = ref(false);
let again = false;
const reload = () => router.reload({ only: ['vehicle', 'incident'] });
function send(code: string) {
    busy.value = true;
    router.post('/firetab/status', { status: code }, {
        preserveScroll: true,
        onFinish: () => {
            busy.value = false;
            if (again) {
                again = false;
                reload();
            }
        },
    });
}

usePulse(`firetab.vehicle.${props.vehicle.id}`, ['status', 'incidents'], () => {
    if (busy.value) again = true;
    else reload();
}, { pollMs: 5000 });
</script>

<template>
    <div class="mx-auto max-w-3xl space-y-4">
        <header class="flex flex-wrap items-center justify-between gap-2">
            <div>
                <h1 class="text-2xl font-semibold">{{ trans('firetab.fms.title') }}</h1>
                <p class="text-text-2">{{ vehicle.identifier }} {{ vehicle.name }}</p>
            </div>
            <Link href="/firetab/list" class="inline-flex min-h-11 items-center rounded-md border border-border px-4">{{ trans('firetab.fms.back') }}</Link>
        </header>

        <section class="rounded-md border border-border p-4">
            <p class="text-sm text-text-2">{{ trans('firetab.fms.current') }}</p>
            <p class="text-xl font-semibold">
                <template v-if="vehicle.status !== null">S{{ vehicle.status }} · {{ vehicle.label }}</template>
                <template v-else>{{ trans('firetab.fms.none') }}</template>
            </p>
            <p v-if="vehicle.at" class="text-sm text-text-2">{{ vehicle.at }}</p>
        </section>

        <p v-if="incident" class="rounded-md bg-bg-2 p-3">
            {{ trans('firetab.fms.incident') }}: {{ incident.number ?? trans('firetab.list.no_number') }} · {{ incident.keyword }} · {{ incident.location }}
        </p>
        <p v-else class="rounded-md bg-bg-2 p-3 text-text-2">{{ trans('firetab.fms.no_incident') }}</p>

        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <button
                v-for="b in buttons"
                :key="b.code"
                type="button"
                :disabled="busy"
                class="min-h-20 rounded-md border-2 p-3 text-left disabled:opacity-60"
                :class="vehicle.status === b.code ? 'border-accent bg-accent text-white' : 'border-border hover:bg-bg-2'"
                @click="send(b.code)"
            >
                <span class="block text-2xl font-bold">S{{ b.code }}</span>
                <span class="block text-sm">{{ b.label }}</span>
            </button>
        </div>
    </div>
</template>
