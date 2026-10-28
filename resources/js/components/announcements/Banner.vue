<script setup lang="ts">
import { computed, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { X } from '@lucide/vue';
import { safeHttpsLink, shownAnnouncements, TONE, type Announcement } from '@/lib/announcements';
import { xsrfToken } from '@/lib/draftSave';

// Hub announcements for the signed-in account (shared prop `announcements`, already filtered by the server). Dismissing
// removes the banner at once and puts it back when the server says no. Texts via mustache only, links https only.
const page = usePage<{ announcements?: Announcement[] }>();
const hidden = ref(new Set<string>());
const busy = ref(new Set<string>());
const failed = ref(false);
const items = computed(() => shownAnnouncements(page.props.announcements, hidden.value));

async function dismiss(id: string) {
    if (busy.value.has(id)) return;
    busy.value.add(id);
    hidden.value.add(id);
    failed.value = false;
    try {
        const res = await fetch('/api/announcements/dismiss', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrfToken() },
            body: JSON.stringify({ announcement_id: id }),
        });
        if (!res.ok) throw new Error(String(res.status));
    } catch {
        hidden.value.delete(id);
        failed.value = true;
    } finally {
        busy.value.delete(id);
    }
}
</script>

<template>
    <section v-if="items.length || failed" class="mb-4 space-y-2" :aria-label="trans('announcements.region')">
        <div
            v-for="a in items"
            :key="a.id"
            class="flex items-start gap-3 rounded-md border-l-4 px-3 py-2 text-sm"
            :class="TONE[a.type]"
            role="status"
        >
            <div class="min-w-0 flex-1">
                <p class="font-medium">
                    <span class="mr-2 text-xs uppercase tracking-wide text-text-2">{{ trans('announcements.type.' + a.type) }}</span>{{ a.title }}
                </p>
                <p v-if="a.message" class="mt-0.5 whitespace-pre-line text-text-2">{{ a.message }}</p>
                <a v-if="safeHttpsLink(a.link)" :href="safeHttpsLink(a.link) ?? undefined" target="_blank" rel="noopener noreferrer" class="mt-1 inline-block underline">{{ trans('announcements.open') }}</a>
            </div>
            <button type="button" class="rounded p-1 hover:bg-(--fill-3)" :aria-label="trans('announcements.dismiss')" :title="trans('announcements.dismiss')" @click="dismiss(a.id)">
                <X class="size-4" aria-hidden="true" />
            </button>
        </div>
        <p v-if="failed" class="text-sm text-danger" role="alert">{{ trans('announcements.dismiss_failed') }}</p>
    </section>
</template>
