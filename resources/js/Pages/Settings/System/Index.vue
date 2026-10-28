<script setup lang="ts">
import { Deferred, usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import UpdatePanel from '@/components/settings/UpdatePanel.vue';
import { formatBytes, relativeAge } from '@/lib/duration';

type Check = { key: string; status: string; message: string };
type Overview = {
    runtime: { php: string; laravel: string; db_driver: string | null; db_version: string | null };
    health: { status: string; checks: Check[] } | null;
    scheduler: { status: 'ok' | 'warn' | 'unknown'; last_run: string | null; age_seconds: number | null };
    queue: { pending: number | null; failed: number | null };
    counts: { personnel: number | null; documents: number | null; vehicles: number | null; sessions_24h: number | null };
    database: { size_bytes: number | null; tables: { name: string; bytes: number }[] | null };
    migrations: string[] | null;
};

defineProps<{
    version: string;
    updates: { check_enabled: boolean; latest_version: string | null; release_url: string | null };
    update: { can_self_update: boolean; reason: string | null; repo_configured: boolean };
    overview?: Overview;
}>();

const locale = usePage<{ locale?: string }>().props.locale ?? 'de';
const card = 'rounded-lg border border-border bg-card p-4 shadow-(--shadow)';
const tone: Record<string, string> = { ok: 'text-ok', degraded: 'text-warn', warn: 'text-warn', down: 'text-danger', unknown: 'text-text-2' };
const countKeys = ['personnel', 'documents', 'vehicles', 'sessions_24h'] as const;

const val = (v: number | string | null | undefined) => (v === null || v === undefined ? trans('settings.overview.unavailable') : String(v));

function bytes(n: number | null): string {
    return n === null ? trans('settings.overview.unavailable') : formatBytes(n, locale);
}
</script>

<template>
    <div class="space-y-4">
        <div>
            <h1 class="text-2xl font-semibold">{{ trans('settings.overview.title') }}</h1>
            <p class="mt-1 text-sm text-text-2">{{ trans('settings.overview.lead') }}</p>
        </div>

        <UpdatePanel :version="version" :updates="updates" :update="update" :locale="locale" />

        <Deferred data="overview">
            <template #fallback>
                <p class="text-sm text-text-2" role="status">{{ trans('settings.overview.loading') }}</p>
            </template>

            <div v-if="overview" class="grid max-w-3xl gap-4">
                <section v-if="overview.migrations?.length" :class="[card, 'border-warn']" aria-labelledby="ov-mig">
                    <h2 id="ov-mig" class="text-lg font-medium text-warn">{{ trans('settings.overview.migrations.title') }} ({{ overview.migrations.length }})</h2>
                    <details class="mt-2 text-sm">
                        <summary class="cursor-pointer">{{ trans('settings.overview.migrations.list') }}</summary>
                        <ul class="mt-1 list-disc pl-5 font-mono text-xs"><li v-for="m in overview.migrations" :key="m">{{ m }}</li></ul>
                    </details>
                </section>

                <section :class="[card, 'space-y-1 text-sm']" aria-labelledby="ov-runtime">
                    <h2 id="ov-runtime" class="text-lg font-medium">{{ trans('settings.overview.runtime.php') }} / {{ trans('settings.overview.runtime.db') }}</h2>
                    <p>{{ trans('settings.overview.runtime.php') }}: {{ overview.runtime.php }}</p>
                    <p>{{ trans('settings.overview.runtime.laravel') }}: {{ overview.runtime.laravel }}</p>
                    <p>{{ trans('settings.overview.runtime.db') }}: {{ val(overview.runtime.db_driver) }} {{ overview.runtime.db_version ?? '' }}</p>
                </section>

                <section :class="card" aria-labelledby="ov-health">
                    <h2 id="ov-health" class="text-lg font-medium">{{ trans('settings.overview.health.title') }}</h2>
                    <template v-if="overview.health">
                        <p class="mt-1 text-sm font-medium" :class="tone[overview.health.status]">{{ trans('settings.overview.health.status.' + overview.health.status) }}</p>
                        <details class="mt-2 text-sm">
                            <summary class="cursor-pointer">{{ trans('settings.overview.health.details') }}</summary>
                            <ul class="mt-1 space-y-1">
                                <li v-for="c in overview.health.checks" :key="c.key" :class="tone[c.status]">{{ c.message }}</li>
                            </ul>
                        </details>
                    </template>
                    <p v-else class="mt-1 text-sm text-text-2">{{ trans('settings.overview.unavailable') }}</p>
                </section>

                <section :class="card" aria-labelledby="ov-sched">
                    <h2 id="ov-sched" class="text-lg font-medium">{{ trans('settings.overview.scheduler.title') }}</h2>
                    <p class="mt-1 text-sm" :class="tone[overview.scheduler.status]">{{ trans('settings.overview.scheduler.' + overview.scheduler.status, { ago: overview.scheduler.age_seconds === null ? '' : relativeAge(overview.scheduler.age_seconds, locale) }) }}</p>
                </section>

                <section :class="[card, 'space-y-1 text-sm']" aria-labelledby="ov-queue">
                    <h2 id="ov-queue" class="text-lg font-medium">{{ trans('settings.overview.queue.title') }}</h2>
                    <p>{{ trans('settings.overview.queue.pending') }}: {{ val(overview.queue.pending) }}</p>
                    <p>{{ trans('settings.overview.queue.failed') }}: <span :class="overview.queue.failed ? 'text-danger' : ''">{{ val(overview.queue.failed) }}</span></p>
                </section>

                <section :class="[card, 'space-y-1 text-sm']" aria-labelledby="ov-counts">
                    <h2 id="ov-counts" class="text-lg font-medium">{{ trans('settings.overview.counts.title') }}</h2>
                    <p v-for="k in countKeys" :key="k">{{ trans('settings.overview.counts.' + k) }}: {{ val(overview.counts[k]) }}</p>
                </section>

                <section :class="[card, 'space-y-1 text-sm']" aria-labelledby="ov-db">
                    <h2 id="ov-db" class="text-lg font-medium">{{ trans('settings.overview.database.title') }}</h2>
                    <p>{{ trans('settings.overview.database.size') }}: {{ bytes(overview.database.size_bytes) }}</p>
                    <details v-if="overview.database.tables?.length">
                        <summary class="cursor-pointer">{{ trans('settings.overview.database.tables') }}</summary>
                        <table class="mt-1 w-full text-left">
                            <tbody>
                                <tr v-for="t in overview.database.tables" :key="t.name">
                                    <td class="py-0.5 font-mono text-xs">{{ t.name }}</td>
                                    <td class="py-0.5 text-right">{{ bytes(t.bytes) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </details>
                    <p v-else class="text-text-2">{{ trans('settings.overview.database.none') }}</p>
                </section>
            </div>
        </Deferred>
    </div>
</template>
