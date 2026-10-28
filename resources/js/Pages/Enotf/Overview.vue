<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';
import SearchPick, { type Hit } from '@/components/firetab/SearchPick.vue';
import { Button } from '@/components/ui/button';
import TabletLayout from '@/Layouts/TabletLayout.vue';
import { xsrfToken } from '@/lib/draftSave';
import { type EnotfStatus, useEnotfSession } from '@/lib/enotfSession';

defineOptions({ layout: TabletLayout });

type Protocol = {
    enr: string;
    patient: string;
    created_at: string | null;
    can_withdraw: boolean;
};
type Category = {
    name: string;
    links: { id: number; title: string; url: string }[];
};

const shares = ref<EnotfStatus['shares']>([]);
useEnotfSession((st) => (shares.value = st.shares ?? []));
const shareTarget = ref<Record<number, string>>({});
const shareError = ref('');
const answer = async (id: number, action: 'accept' | 'reject') => {
    shareError.value = '';
    const target = shareTarget.value[id] ?? '';
    const res = await fetch(`/enotf/share/${id}/${action}`, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrfToken() },
        body: JSON.stringify(action === 'accept' && target ? { target_enr: target } : {}),
    }).catch(() => null);
    const body = (await res?.json().catch(() => null)) as { url?: string; message?: string } | null;
    if (res?.ok && action === 'accept' && body?.url) return location.assign(body.url);
    if (!res?.ok) shareError.value = body?.message || trans('enotf.share.failed');
    shares.value = shares.value.filter((s) => s.id !== id);
};

const props = defineProps<{
    vehicle: { identifier: string; name: string };
    crew: { position: number; name: string; self: boolean }[];
    protocols: Protocol[];
    categories: Category[];
    canWithdrawAll: boolean;
    logbook: boolean;
}>();

const page = usePage<{
    errors?: Record<string, string>;
    flash: {
        status: string | null;
        status_params?: Record<string, string | number> | null;
    };
}>();
const flashParams = computed(() => Object.fromEntries(Object.entries(page.props.flash?.status_params ?? {}).map(([k, v]) => [k, String(v)])));
const positions: Record<number, string> = {
    1: 'enotf.login.driver',
    2: 'enotf.login.codriver',
    3: 'enotf.login.trainee',
};

const fmt = (iso: string | null) => (iso ? new Date(iso).toLocaleString() : '');
const withdrawAll = () => {
    if (window.confirm(trans('enotf.overview.withdraw_confirm'))) router.post('/enotf/protocols/withdraw-all');
};
const seat = (position: number) => props.crew.find((m) => m.position === position);
const name = (position: number, hit: Hit) => router.post('/enotf/crew/join', { position, personnel_id: hit.id }, { preserveScroll: true });
const crewError = computed(() => {
    const e = page.props.errors as Record<string, string | undefined> | undefined;
    return e?.position ?? e?.members ?? '';
});
const leave = () => router.post('/enotf/crew/leave');
const endCrew = () => {
    if (window.confirm(trans('enotf.crew.end_confirm'))) router.post('/enotf/crew/end');
};
</script>

<template>
    <div class="mx-auto max-w-4xl space-y-6">
        <header class="flex flex-wrap items-center justify-between gap-2">
            <h1 class="text-2xl font-semibold">
                {{
                    trans('enotf.overview.title', {
                        name: `${vehicle.identifier} ${vehicle.name}`,
                    })
                }}
            </h1>
            <div class="flex flex-wrap gap-2">
                <Link href="/enotf/create" class="inline-flex min-h-11 items-center rounded-md bg-accent px-4 text-white">{{
                    trans('enotf.overview.create')
                }}</Link>
                <Link href="/enotf/fahrzeuginfo" class="inline-flex min-h-11 items-center rounded-md border border-border px-4">{{ trans('enotf.vehicleinfo.title') }}</Link>
                <Link v-if="logbook" href="/enotf/fahrtenbuch" class="inline-flex min-h-11 items-center rounded-md border border-border px-4">{{ trans('enotf.logbook.link') }}</Link>
                <Link href="/enotf/hospitals" class="inline-flex min-h-11 items-center rounded-md border border-border px-4">{{ trans('enotf.hospital.title') }}</Link>
            </div>
        </header>

        <p v-if="page.props.flash?.status" role="status" class="rounded-md bg-(--ok-soft) px-3 py-2 text-sm text-ok">
            {{ trans(page.props.flash.status, flashParams) }}
        </p>

        <section v-if="shares.length || shareError" class="space-y-2 rounded-md border border-accent p-3" aria-live="polite">
            <h2 class="font-semibold">{{ trans('enotf.share.incoming') }}</h2>
            <div v-for="s in shares" :key="s.id" class="flex flex-wrap items-center gap-2 text-sm">
                <span class="flex-1">{{ trans('enotf.share.offer', { enr: s.enr, vehicle: s.from }) }}</span>
                <select v-model="shareTarget[s.id]" class="min-h-11 rounded-md border border-border bg-bg px-2" :aria-label="trans('enotf.share.target')">
                    <option value="">{{ trans('enotf.share.new') }}</option>
                    <option v-for="p in protocols" :key="p.enr" :value="p.enr">{{ trans('enotf.share.merge_into', { enr: p.enr }) }}</option>
                </select>
                <Button class="min-h-11" @click="answer(s.id, 'accept')">{{ trans('enotf.share.accept') }}</Button>
                <Button variant="secondary" class="min-h-11" @click="answer(s.id, 'reject')">{{ trans('enotf.share.reject') }}</Button>
            </div>
            <p v-if="shareError" role="alert" class="rounded-md border border-danger bg-(--danger-soft) p-2 text-sm">{{ shareError }}</p>
        </section>

        <section class="space-y-2 rounded-md border border-border p-3">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="font-semibold">{{ trans('enotf.overview.crew') }}</h2>
                <div class="flex flex-wrap gap-2">
                    <Button variant="secondary" class="min-h-11" @click="leave">{{ trans('enotf.crew.leave') }}</Button>
                    <Button variant="destructive" class="min-h-11" @click="endCrew">{{ trans('enotf.crew.end') }}</Button>
                </div>
            </div>
            <div v-for="position in [1, 2, 3]" :key="position" class="flex flex-wrap items-center gap-2 text-sm">
                <span class="w-28 text-text-2">{{ trans(positions[position] ?? '') }}</span>
                <span v-if="seat(position)" class="flex-1">{{ seat(position)?.name }} <span v-if="seat(position)?.self" class="text-text-3">{{ trans('enotf.crew.you') }}</span></span>
                <SearchPick
                    v-else-if="position > 1"
                    class="flex-1"
                    url="/enotf/personnel/search"
                    :placeholder="trans('enotf.login.member_search')"
                    @pick="(h) => name(position, h)"
                />
                <span v-else class="flex-1 text-text-3">{{ trans('enotf.crew.free') }}</span>
            </div>
            <p v-if="crewError" role="alert" class="rounded-md border border-danger bg-(--danger-soft) p-2 text-sm">{{ crewError }}</p>
        </section>

        <section class="space-y-2">
            <div class="flex items-center justify-between gap-2">
                <h2 class="text-lg font-semibold">
                    {{ trans('enotf.overview.protocols') }}
                </h2>
                <Button v-if="canWithdrawAll" variant="destructive" class="min-h-11" @click="withdrawAll">{{ trans('enotf.overview.withdraw_all') }}</Button>
            </div>
            <ul v-if="protocols.length" class="divide-y divide-border rounded-md border border-border">
                <li v-for="p in protocols" :key="p.enr">
                    <a :href="`/enotf/p/${encodeURIComponent(p.enr)}`" class="flex min-h-11 items-center gap-3 px-4 py-2 hover:bg-bg-2">
                        <span class="font-mono text-sm">{{ p.enr }}</span>
                        <span class="flex-1">{{ p.patient || trans('enotf.overview.no_patient') }}</span>
                        <span class="text-sm text-text-2">{{ fmt(p.created_at) }}</span>
                    </a>
                </li>
            </ul>
            <p v-else class="text-text-2">{{ trans('enotf.overview.none') }}</p>
        </section>

        <section v-if="categories.length" class="space-y-4">
            <h2 class="text-lg font-semibold">
                {{ trans('enotf.overview.quicklinks') }}
            </h2>
            <div v-for="c in categories" :key="c.name" class="space-y-2">
                <h3 class="text-sm font-semibold text-text-2">{{ c.name }}</h3>
                <div class="grid grid-cols-2 gap-2">
                    <a
                        v-for="l in c.links"
                        :key="l.id"
                        :href="l.url"
                        class="flex min-h-11 items-center justify-center rounded-md border border-border bg-bg-2 p-3 text-center hover:bg-bg"
                        >{{ l.title }}</a
                    >
                </div>
            </div>
        </section>
    </div>
</template>
