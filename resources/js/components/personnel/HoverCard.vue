<script setup lang="ts">
import { onBeforeUnmount, ref } from 'vue';
import { trans } from 'laravel-vue-i18n';
import RankBadge from '@/components/personnel/RankBadge.vue';

// Preview card on hover/focus: loads `url` after a short delay, aborts when the pointer leaves first.
// Renders a personnel card unless the `card` slot (other card types, e.g. vehicles) takes over.
const props = defineProps<{ url: string }>();

type Card = {
    id: number | null;
    name: string;
    rank: { label: string; badge: string | null } | null;
    service_number: string | null;
    ems: string | null;
    fire: string | null;
    picture_url: string | null;
    linked_account: { username: string } | null;
};

const cache = new Map<string, Card>();
const open = ref(false);
const card = ref<Card | null>(null);
const failed = ref(false);
let timer: ReturnType<typeof setTimeout> | undefined;
let controller: AbortController | undefined;

async function load() {
    open.value = true;
    const hit = cache.get(props.url);
    if (hit !== undefined) {
        card.value = hit;
        failed.value = false;
        return;
    }
    if (controller) return;
    controller = new AbortController();
    try {
        const res = await fetch(props.url, { headers: { Accept: 'application/json' }, signal: controller.signal });
        if (res.ok) {
            const data = (await res.json()) as Card;
            cache.set(props.url, data);
            card.value = data;
        }
        failed.value = !res.ok;
    } catch {
    } finally {
        controller = undefined;
    }
}

function show() {
    clearTimeout(timer);
    timer = setTimeout(load, 300);
}

function hide() {
    clearTimeout(timer);
    controller?.abort();
    controller = undefined;
    open.value = false;
}

onBeforeUnmount(hide);
</script>

<template>
    <span class="relative inline-block" @mouseenter="show" @mouseleave="hide" @focusin="show" @focusout="hide" @keydown.esc="hide">
        <slot />
        <span
            v-if="open && (card || failed)"
            role="tooltip"
            class="absolute left-0 top-full z-20 mt-1 w-64 rounded-lg border border-border bg-card p-3 text-left text-sm shadow-(--shadow)"
        >
            <slot v-if="card" name="card" :card="card as unknown">
                <span class="flex items-center gap-3">
                    <img v-if="card.picture_url" :src="card.picture_url" alt="" class="size-12 rounded-full object-cover" />
                    <span class="min-w-0">
                        <span class="block truncate font-medium">{{ card.name }}</span>
                        <span v-if="card.service_number" class="block text-xs text-text-2">{{ trans('personnel.card.service_number', { number: card.service_number }) }}</span>
                    </span>
                </span>
                <span class="mt-2 block space-y-1 text-xs">
                    <span v-if="card.rank" class="block"><RankBadge :label="card.rank.label" /></span>
                    <span v-if="card.ems" class="block">{{ trans('personnel.card.ems', { value: card.ems }) }}</span>
                    <span v-if="card.fire" class="block">{{ trans('personnel.card.fire', { value: card.fire }) }}</span>
                    <span v-if="card.linked_account" class="block text-text-2">{{ trans('personnel.card.account', { name: card.linked_account.username }) }}</span>
                </span>
            </slot>
            <span v-else class="text-text-2">{{ trans('personnel.card.unavailable') }}</span>
        </span>
    </span>
</template>
