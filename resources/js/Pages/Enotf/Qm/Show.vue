<script setup lang="ts">
import { Link, router } from "@inertiajs/vue3";
import { trans } from "laravel-vue-i18n";
import { ref } from "vue";
import { Button } from "@/components/ui/button";
import { xsrfToken } from "@/lib/draftSave";

type LogEntry = {
    id: number;
    kind: "status" | "comment" | "reopen";
    from: number | null;
    to: number | null;
    text: string | null;
    actor: string;
    at: string | null;
};

const props = defineProps<{
    protocol: {
        enr: string;
        status: string;
        qm_status: number;
        hidden: boolean;
        billed: boolean;
        vehicles: string[];
        created_at: string | null;
        released_at: string | null;
    };
    log: LogEntry[];
    can: { review: boolean };
}>();

const base = `/enotf/qm/${encodeURIComponent(props.protocol.enr)}`;
const qmStatus = ref(props.protocol.qm_status);
const comment = ref("");
const busy = ref(false);
const error = ref("");

async function send(
    method: string,
    path: string,
    body?: Record<string, unknown>,
) {
    busy.value = true;
    error.value = "";
    const res = await fetch(base + path, {
        method,
        credentials: "same-origin",
        headers: {
            Accept: "application/json",
            "Content-Type": "application/json",
            "X-Requested-With": "XMLHttpRequest",
            "X-XSRF-TOKEN": xsrfToken(),
        },
        body: body ? JSON.stringify(body) : undefined,
    }).catch(() => null);
    busy.value = false;
    if (res?.ok) {
        router.reload();
        return true;
    }
    const data = (await res?.json().catch(() => null)) as {
        message?: string;
    } | null;
    error.value = data?.message ?? trans("enotf.qm.failed");
    if (res?.status === 409) router.reload();
    return false;
}

const saveStatus = () =>
    send("PATCH", "/status", { qm_status: qmStatus.value });
const addComment = async () => {
    if (await send("POST", "/comments", { text: comment.value }))
        comment.value = "";
};
const reopen = () =>
    window.confirm(trans("enotf.qm.reopen_confirm")) && send("POST", "/reopen");
const statusLabel = (s: number | null) =>
    s === null ? "" : trans(`enotf.qm.status.${s}`);
</script>

<template>
    <div class="max-w-3xl space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-2xl font-semibold">
                {{ trans("enotf.qm.title", { enr: protocol.enr }) }}
            </h1>
            <Link
                :href="`/enotf/print/${encodeURIComponent(protocol.enr)}`"
                class="text-accent"
                >{{ trans("enotf.qm.print") }}</Link
            >
        </div>

        <dl
            class="grid grid-cols-2 gap-x-4 gap-y-1 rounded-lg border border-border bg-card p-4 text-sm shadow-(--shadow) sm:grid-cols-4"
        >
            <dt class="text-text-2">{{ trans("enotf.admin.col.status") }}</dt>
            <dd>
                {{ trans(`enotf.admin.status.${protocol.status}`)
                }}<span v-if="protocol.billed">
                    · {{ trans("enotf.qm.billed") }}</span
                >
            </dd>
            <dt class="text-text-2">{{ trans("enotf.admin.col.vehicles") }}</dt>
            <dd>{{ protocol.vehicles.join(", ") }}</dd>
            <dt class="text-text-2">
                {{ trans("enotf.admin.col.created_at") }}
            </dt>
            <dd class="tabular-nums">{{ protocol.created_at }}</dd>
            <dt class="text-text-2">
                {{ trans("enotf.admin.col.released_at") }}
            </dt>
            <dd class="tabular-nums">{{ protocol.released_at }}</dd>
            <dt class="text-text-2">{{ trans("enotf.qm.qm_status") }}</dt>
            <dd>{{ statusLabel(protocol.qm_status) }}</dd>
        </dl>

        <p
            v-if="error"
            role="alert"
            class="rounded-md border border-danger bg-(--danger-soft) px-3 py-2 text-sm"
        >
            {{ error }}
        </p>

        <div
            v-if="can.review"
            class="space-y-3 rounded-lg border border-border bg-card p-4 shadow-(--shadow)"
        >
            <div class="flex flex-wrap items-end gap-2">
                <label class="flex flex-col gap-1 text-sm">
                    {{ trans("enotf.qm.qm_status") }}
                    <select
                        v-model.number="qmStatus"
                        class="rounded-md border border-border bg-transparent px-2 py-1"
                    >
                        <option
                            v-for="s in [0, 1, 2, 3, 4]"
                            :key="s"
                            :value="s"
                        >
                            {{ statusLabel(s) }}
                        </option>
                    </select>
                </label>
                <Button
                    size="sm"
                    :disabled="busy || qmStatus === protocol.qm_status"
                    @click="saveStatus"
                    >{{ trans("enotf.qm.save_status") }}</Button
                >
                <Button
                    v-if="protocol.status === 'released' && !protocol.billed"
                    variant="ghost"
                    size="sm"
                    :disabled="busy"
                    @click="reopen"
                    >{{ trans("enotf.qm.reopen") }}</Button
                >
            </div>
            <label class="flex flex-col gap-1 text-sm">
                {{ trans("enotf.qm.comment") }}
                <textarea
                    v-model="comment"
                    maxlength="2000"
                    rows="3"
                    class="rounded-md border border-border bg-transparent px-2 py-1"
                />
            </label>
            <Button
                size="sm"
                :disabled="busy || comment.trim() === ''"
                @click="addComment"
                >{{ trans("enotf.qm.add_comment") }}</Button
            >
        </div>

        <section class="space-y-2">
            <h2 class="text-lg font-semibold">{{ trans("enotf.qm.log") }}</h2>
            <p v-if="log.length === 0" class="text-sm text-text-2">
                {{ trans("enotf.qm.log_empty") }}
            </p>
            <ul class="space-y-2">
                <li
                    v-for="e in log"
                    :key="e.id"
                    class="rounded-md border border-border bg-card px-3 py-2 text-sm"
                >
                    <div class="text-xs text-text-2">
                        {{ e.at }} · {{ e.actor }}
                    </div>
                    <div v-if="e.kind === 'status'">
                        {{
                            trans("enotf.qm.log_status", {
                                from: statusLabel(e.from),
                                to: statusLabel(e.to),
                            })
                        }}
                    </div>
                    <div v-else-if="e.kind === 'reopen'">
                        {{ trans("enotf.qm.log_reopen") }}
                    </div>
                    <div v-else class="whitespace-pre-wrap">{{ e.text }}</div>
                </li>
            </ul>
        </section>
    </div>
</template>
