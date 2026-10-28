<script setup lang="ts">
/** Print view of one protocol (GET /enotf/print/{enr}, policy `print`). Plain text only, no external fonts. */
import { trans } from "laravel-vue-i18n";
import type { VNode } from "vue";
import Chart from "@/components/enotf/vitals/Chart.vue";
import History from "@/components/enotf/vitals/History.vue";
import { ageOn, emptyValue as empty, type FieldDef, fieldText as show, gcsSum, placeLine, printTitleKey } from "@/lib/enotfUi";
import { type BzUnit, NUMERIC, vitalPrint, type VitalRow } from "@/lib/enotfVitals";

defineOptions({ layout: (_h: unknown, page: VNode) => page });

const props = defineProps<{
    protocol: {
        enr: string;
        status: string;
        vehicles: string[];
        released_at: string | null;
    };
    crew: {
        slot: string;
        position: number;
        name: string | null;
        qualification: string | null;
    }[];
    values: Record<string, unknown>;
    schema: Record<string, FieldDef[]>;
    vitals: VitalRow[];
    medications: {
        id: number;
        name: string;
        dose: string | null;
        unit: string | null;
        route: string | null;
        given_at: string;
    }[];
    bz_unit: BzUnit;
    today: string;
    clinic?: boolean;
}>();

const positions: Record<number, string> = {
    1: "enotf.login.driver",
    2: "enotf.login.codriver",
    3: "enotf.login.trainee",
};

const gcs = gcsSum(props.values.d_gcs_1, props.values.d_gcs_2, props.values.d_gcs_3);
const age = ageOn(props.values.patgebdat, props.today);
const PLACES: Record<string, string> = { transp_adresse: "transp_poi", ziel_adresse: "ziel_poi" };
const vitalCodes: readonly string[] = NUMERIC;
const text = (f: FieldDef): string => {
    const v = props.values[f.key];
    if (f.key in PLACES) return placeLine(props.values[PLACES[f.key]], v);
    if (Object.values(PLACES).includes(f.key) || empty(v)) return "";
    if (vitalCodes.includes(f.key)) return vitalPrint(f.key, v, props.bz_unit);
    return show(f, v);
};
const sections = Object.entries(props.schema)
    .map(([slug, fields]) => ({
        slug,
        rows: fields.flatMap((f) => [
            ...(text(f) === ""
                ? []
                : [{ key: f.key, label: trans(`enotf.f.${f.key}`), text: text(f) }]),
            ...(f.key === "patgebdat" && age !== null
                ? [{ key: "age", label: trans("enotf.ui.rd.age"), text: trans("enotf.print.years", { n: String(age) }) }]
                : []),
            ...(f.key === "d_gcs_3" && gcs !== null
                ? [{ key: "gcs", label: trans("enotf.ui.eb.gcs"), text: String(gcs) }]
                : []),
        ]),
    }))
    .filter((s) => s.rows.length);
const signature =
    typeof props.values.pfname === "string" ? props.values.pfname : "";
const print = () => window.print();
</script>

<template>
    <div
        class="enotf-print mx-auto max-w-4xl space-y-6 bg-white p-6 text-black"
    >
        <header
            class="flex flex-wrap items-start justify-between gap-2 border-b border-black pb-3"
        >
            <div>
                <h1 class="text-2xl font-semibold">
                    {{ trans(printTitleKey(values.prot_by)) }}
                </h1>
                <p>{{ trans("enotf.protocol.title", { enr: protocol.enr }) }}</p>
                <p v-if="protocol.vehicles.length">
                    {{ protocol.vehicles.join(" / ") }}
                </p>
                <p class="text-sm">
                    {{
                        protocol.released_at
                            ? trans("enotf.print.released", {
                                  date: protocol.released_at,
                              })
                            : trans("enotf.print.not_released", {
                                  status: protocol.status,
                              })
                    }}
                </p>
            </div>
            <div class="no-print flex gap-3">
                <button
                    type="button"
                    class="min-h-11 rounded-md border border-black px-4"
                    @click="print"
                >
                    {{ trans("enotf.print.print") }}
                </button>
                <a
                    v-if="!clinic"
                    :href="`/enotf/p/${encodeURIComponent(protocol.enr)}`"
                    class="inline-flex min-h-11 items-center underline"
                    >{{ trans("enotf.protocol.back") }}</a
                >
            </div>
        </header>

        <section v-if="crew.length">
            <h2 class="mb-2 text-lg font-semibold">
                {{ trans("enotf.print.crew") }}
            </h2>
            <ul class="text-sm">
                <li v-for="c in crew" :key="`${c.slot}-${c.position}`">
                    {{ trans(`enotf.print.slot.${c.slot}`) }},
                    {{ trans(positions[c.position] ?? "") }}: {{ c.name ?? "" }}
                    <span v-if="c.qualification">({{ c.qualification }})</span>
                </li>
            </ul>
        </section>

        <section v-for="s in sections" :key="s.slug" class="print-block">
            <h2 class="mb-2 border-b border-black text-lg font-semibold">
                {{ trans(`enotf.section.${s.slug}`) }}
            </h2>
            <dl
                class="grid grid-cols-[minmax(0,1fr)_minmax(0,2fr)] gap-x-4 gap-y-1 text-sm"
            >
                <template v-for="r in s.rows" :key="r.key">
                    <dt class="font-medium">{{ r.label }}</dt>
                    <dd class="break-words">{{ r.text }}</dd>
                </template>
            </dl>
        </section>

        <section v-if="vitals.length" class="print-block">
            <h2 class="mb-2 text-lg font-semibold">
                {{ trans("enotf.print.vitals") }}
            </h2>
            <Chart :rows="vitals" />
            <History :rows="vitals" />
        </section>

        <section v-if="medications.length" class="print-block">
            <h2 class="mb-2 border-b border-black text-lg font-semibold">
                {{ trans("enotf.medications.title") }}
            </h2>
            <ul class="text-sm">
                <li v-for="m in [...medications].reverse()" :key="m.id">
                    {{ m.given_at.slice(11) }}: {{ m.name }} {{ m.dose }}
                    {{ m.unit }} {{ m.route }}
                </li>
            </ul>
        </section>

        <footer v-if="signature" class="print-block pt-6">
            <p class="signature text-3xl">{{ signature }}</p>
            <p class="w-72 border-t border-black text-xs">
                {{ trans("enotf.print.signature") }}
            </p>
        </footer>
    </div>
</template>

<style scoped>
.signature {
    font-family:
        "Segoe Script", "Bradley Hand", "Brush Script MT", "Apple Chancery",
        "URW Chancery L", cursive;
}
@media print {
    .no-print {
        display: none;
    }
    .enotf-print {
        max-width: none;
        padding: 0;
    }
    .print-block {
        break-inside: avoid;
    }
}
</style>
