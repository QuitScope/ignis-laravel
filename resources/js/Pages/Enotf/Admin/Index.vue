<script setup lang="ts">
import { Link, router } from "@inertiajs/vue3";
import { trans } from "laravel-vue-i18n";
import { ref, watch } from "vue";
import Pagination from "@/components/Pagination.vue";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";

type Row = {
    id: number;
    enr: string;
    status: string;
    hidden: boolean;
    vehicles: string[];
    mission_date: string | null;
    created_at: string | null;
    released_at: string | null;
};

const props = defineProps<{
    protocols: {
        data: Row[];
        from: number | null;
        to: number | null;
        total: number;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    filters: { segment: string; q: string; sort: string; dir: string };
    counts: Record<string, number>;
    can: { hide: boolean };
    federated: number | null;
}>();

const segments = ["open", "released", "withdrawn", "hidden"] as const;
const cols = [
    "enr",
    "mission_date",
    "created_at",
    "released_at",
    "status",
] as const;
const q = ref(props.filters.q);
const selected = ref<number[]>([]);

function load(extra: Record<string, string> = {}) {
    const all: Record<string, string> = {
        segment: props.filters.segment,
        q: q.value,
        sort: props.filters.sort,
        dir: props.filters.dir,
        ...extra,
    };
    router.get(
        "/enotf/admin",
        Object.fromEntries(Object.entries(all).filter(([, v]) => v !== "")),
        { preserveState: true, replace: true },
    );
}
watch(q, () => load());
function sortBy(col: string) {
    load({
        sort: col,
        dir:
            props.filters.sort === col && props.filters.dir === "asc"
                ? "desc"
                : "asc",
    });
}
function hide() {
    router.post(
        "/enotf/admin/hide",
        { ids: selected.value },
        { preserveScroll: true, onSuccess: () => (selected.value = []) },
    );
}
const toggle = (id: number) =>
    (selected.value = selected.value.includes(id)
        ? selected.value.filter((x) => x !== id)
        : [...selected.value, id]);
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-2xl font-semibold">
                {{ trans("enotf.admin.title") }}
            </h1>
            <Link v-if="federated !== null" href="/federation/protocols" class="text-accent">
                {{ trans("federation.views.link", { count: String(federated) }) }}
            </Link>
            <Link
                v-if="can.hide"
                href="/enotf/admin/empty"
                class="text-accent"
                >{{ trans("enotf.admin.empty.link") }}</Link
            >
        </div>

        <nav class="flex flex-wrap gap-2" aria-label="tabs">
            <Link
                v-for="s in segments"
                :key="s"
                :href="`/enotf/admin?segment=${s}`"
                class="rounded-md border px-3 py-1 text-sm"
                :class="
                    filters.segment === s
                        ? 'border-accent text-accent'
                        : 'border-border text-text-2'
                "
                >{{ trans(`enotf.admin.segment.${s}`) }} ({{ counts[s] }})</Link
            >
        </nav>

        <div class="flex flex-wrap items-end gap-3">
            <Input
                v-model="q"
                type="search"
                maxlength="100"
                class="w-64"
                :placeholder="trans('enotf.admin.search')"
                :aria-label="trans('enotf.admin.search')"
            />
            <Button
                v-if="
                    can.hide && selected.length && filters.segment !== 'hidden'
                "
                variant="ghost"
                size="sm"
                @click="hide"
                >{{ trans("enotf.admin.hide") }} ({{ selected.length }})</Button
            >
        </div>

        <div
            class="overflow-x-auto rounded-lg border border-border bg-card shadow-(--shadow)"
        >
            <table class="w-full text-left text-sm">
                <thead class="border-b border-border text-text-2">
                    <tr>
                        <th v-if="can.hide" class="w-8 px-3 py-2" />
                        <th
                            v-for="c in cols"
                            :key="c"
                            class="px-3 py-2 font-medium"
                            :aria-sort="
                                filters.sort === c
                                    ? filters.dir === 'asc'
                                        ? 'ascending'
                                        : 'descending'
                                    : undefined
                            "
                        >
                            <button type="button" @click="sortBy(c)">
                                {{ trans(`enotf.admin.col.${c}`) }}
                            </button>
                        </th>
                        <th class="px-3 py-2 font-medium">
                            {{ trans("enotf.admin.col.vehicles") }}
                        </th>
                        <th class="px-3 py-2" />
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="p in protocols.data"
                        :key="p.id"
                        class="border-b border-border last:border-0"
                    >
                        <td v-if="can.hide" class="px-3 py-2">
                            <input
                                type="checkbox"
                                :checked="selected.includes(p.id)"
                                :disabled="p.hidden"
                                @change="toggle(p.id)"
                            />
                        </td>
                        <td class="px-3 py-2">{{ p.enr }}</td>
                        <td class="px-3 py-2 tabular-nums">
                            {{ p.mission_date }}
                        </td>
                        <td class="px-3 py-2 tabular-nums">
                            {{ p.created_at }}
                        </td>
                        <td class="px-3 py-2 tabular-nums">
                            {{ p.released_at }}
                        </td>
                        <td class="px-3 py-2">
                            {{ trans(`enotf.admin.status.${p.status}`) }}
                        </td>
                        <td class="px-3 py-2">{{ p.vehicles.join(", ") }}</td>
                        <td class="px-3 py-2 text-right whitespace-nowrap">
                            <Link
                                :href="`/enotf/qm/${encodeURIComponent(p.enr)}`"
                                class="mr-3 text-accent"
                                >{{ trans("enotf.qm.link") }}</Link
                            >
                            <Link
                                :href="`/enotf/p/${encodeURIComponent(p.enr)}`"
                                class="text-accent"
                                >{{ trans("enotf.admin.open") }}</Link
                            >
                        </td>
                    </tr>
                    <tr v-if="protocols.data.length === 0">
                        <td
                            colspan="8"
                            class="px-3 py-6 text-center text-text-2"
                        >
                            {{ trans("enotf.admin.none") }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <Pagination
            :from="protocols.from"
            :to="protocols.to"
            :total="protocols.total"
            :prev-url="protocols.prev_page_url"
            :next-url="protocols.next_page_url"
        />
    </div>
</template>
