<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { Search } from '@lucide/vue';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import {
    debounce,
    flatten,
    isChoose,
    matchActions,
    moveActive,
    safeHref,
    searchUrl,
    sections,
    shouldSearch,
    type PaletteAction,
    type PaletteOption,
    type SearchGroup,
    type SearchResponse,
    type SearchScope,
} from '@/lib/searchPalette';

// Command palette (legacy @emergencyforge/ui CommandPalette via assets/js/ui/palette.js): local actions from the
// navigation plus the server's groups. Typing waits 200 ms, a newer query aborts the older request and a late
// answer of an older one is ignored. Focus trap, Esc and focus return come from the dialog (reka-ui).
const props = defineProps<{ actions: PaletteAction[] }>();
const open = defineModel<boolean>('open', { required: true });

const query = ref('');
const scope = ref('all');
const scopes = ref<SearchScope[]>([]);
const groups = ref<SearchGroup[]>([]);
const status = ref<'idle' | 'loading' | 'done' | 'error'>('idle');
const active = ref(0);
const input = ref<HTMLInputElement | null>(null);
let controller: AbortController | null = null;
let scopesLoaded = false;

const list = computed(() => sections(matchActions(props.actions, query.value), trans('search.actions'), groups.value));
const options = computed(() => flatten(list.value));
const activeId = computed(() => options.value[active.value]?.id);
const hitCount = computed(() => groups.value.reduce((n, g) => n + g.items.length, 0));
const live = computed(() =>
    status.value === 'loading' ? trans('search.loading')
    : status.value === 'error' ? trans('search.error')
    : status.value === 'done' ? (hitCount.value > 0 ? trans('search.count', { count: String(hitCount.value) }) : trans('search.empty', { q: query.value.trim() }))
    : '',
);

async function fetchJson(url: string, signal?: AbortSignal): Promise<SearchResponse> {
    const res = await fetch(url, { signal, credentials: 'same-origin', headers: { Accept: 'application/json' } });
    if (!res.ok) throw new Error(String(res.status));

    return (await res.json()) as SearchResponse;
}

async function run() {
    controller?.abort();
    if (!shouldSearch(query.value)) {
        controller = null;
        groups.value = [];
        status.value = 'idle';
        return;
    }
    const mine = (controller = new AbortController());
    status.value = 'loading';
    try {
        const data = await fetchJson(searchUrl(query.value.trim(), scope.value), mine.signal);
        if (controller !== mine) return;
        groups.value = Array.isArray(data.results) ? data.results : [];
        status.value = 'done';
    } catch {
        if (controller !== mine) return;
        groups.value = [];
        status.value = 'error';
    }
}

const debounced = debounce(run);
watch(query, () => debounced.call());
watch(scope, () => {
    debounced.cancel();
    run();
});
watch(options, () => (active.value = 0));

watch(open, (isOpen) => {
    if (isOpen && !scopesLoaded) {
        scopesLoaded = true;
        fetchJson(searchUrl('', 'all'))
            .then((data) => (scopes.value = Array.isArray(data.scopes) ? data.scopes : []))
            .catch(() => (scopesLoaded = false));
    }
    if (!isOpen) {
        debounced.cancel();
        controller?.abort();
        controller = null;
        query.value = '';
        scope.value = 'all';
        groups.value = [];
        status.value = 'idle';
    }
});
onBeforeUnmount(() => {
    debounced.cancel();
    controller?.abort();
});

function choose(option: PaletteOption | undefined) {
    const href = safeHref(option?.href);
    if (href === null) return;
    open.value = false;
    router.visit(href);
}

function onKeydown(e: KeyboardEvent) {
    const next = moveActive(active.value, options.value.length, e.key);
    if (next !== null) {
        e.preventDefault();
        active.value = next;
        document.getElementById(options.value[next].id)?.scrollIntoView({ block: 'nearest' });
    } else if (isChoose(e)) {
        e.preventDefault();
        choose(options.value[active.value]);
    }
}

function focusInput(e: Event) {
    e.preventDefault();
    input.value?.focus();
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            :show-close-button="false"
            class="top-[12vh] max-h-[76vh] translate-y-0 grid-rows-[auto_auto_1fr] gap-0 overflow-hidden border-border bg-bg-2 p-0 sm:max-w-xl"
            @open-auto-focus="focusInput"
        >
            <DialogTitle class="sr-only">{{ trans('search.dialog_title') }}</DialogTitle>
            <DialogDescription class="sr-only">{{ trans('search.dialog_description') }}</DialogDescription>
            <div class="flex items-center gap-2 border-b border-border px-3">
                <Search class="size-4 shrink-0 text-text-2" aria-hidden="true" />
                <input
                    ref="input"
                    v-model="query"
                    type="text"
                    role="combobox"
                    :aria-expanded="options.length > 0"
                    aria-controls="palette-listbox"
                    aria-autocomplete="list"
                    :aria-activedescendant="activeId"
                    :aria-label="trans('search.input_label')"
                    :placeholder="trans('search.placeholder')"
                    maxlength="100"
                    autocomplete="off"
                    spellcheck="false"
                    class="h-12 w-full bg-transparent text-sm outline-none placeholder:text-text-2"
                    @keydown="onKeydown"
                />
            </div>
            <div v-if="scopes.length > 1" class="flex flex-wrap gap-1.5 border-b border-border px-3 py-2" role="group" :aria-label="trans('search.scopes_label')">
                <button
                    v-for="s in [{ key: 'all', label: trans('search.scope_all') }, ...scopes]"
                    :key="s.key"
                    type="button"
                    :aria-pressed="scope === s.key"
                    class="rounded-full border px-2.5 py-0.5 text-xs focus-visible:outline-2 focus-visible:outline-offset-2"
                    :class="scope === s.key ? 'border-accent bg-(--accent-soft) text-accent' : 'border-border text-text-2 hover:bg-(--fill-3)'"
                    @click="scope = s.key"
                >
                    {{ s.label }}
                </button>
            </div>
            <div class="min-h-0 overflow-y-auto">
                <div id="palette-listbox" role="listbox" :aria-label="trans('search.dialog_title')">
                    <div v-for="section in list" :key="section.key" role="group" :aria-labelledby="`${section.key}-heading`">
                        <div
                            :id="`${section.key}-heading`"
                            role="presentation"
                            class="sticky top-0 z-[1] bg-bg-2 px-3 pt-2 pb-1 text-xs font-medium uppercase tracking-wide text-text-2"
                        >
                            {{ section.label }}
                        </div>
                        <div
                            v-for="option in section.options"
                            :id="option.id"
                            :key="option.id"
                            role="option"
                            :aria-selected="option.id === activeId"
                            class="mx-1 flex cursor-pointer items-baseline gap-2 rounded-md px-2 py-1.5 text-sm"
                            :class="option.id === activeId ? 'bg-(--fill-3)' : ''"
                            @mousemove="active = options.indexOf(option)"
                            @click="choose(option)"
                        >
                            <span class="truncate">{{ option.label }}</span>
                            <span v-if="option.approx" class="shrink-0 text-xs text-text-2" :title="trans('search.approx')">
                                <span aria-hidden="true">≈</span><span class="sr-only">{{ trans('search.approx') }}</span>
                            </span>
                            <span v-if="option.sub" class="ml-auto truncate text-xs text-text-2">{{ option.sub }}</span>
                        </div>
                    </div>
                </div>
                <p v-if="status === 'loading'" class="px-3 py-3 text-sm text-text-2">{{ trans('search.loading') }}</p>
                <p v-else-if="status === 'error'" class="px-3 py-3 text-sm text-danger">{{ trans('search.error') }}</p>
                <p v-else-if="status === 'done' && hitCount === 0" class="px-3 py-3 text-sm text-text-2">{{ trans('search.empty', { q: query.trim() }) }}</p>
                <p v-else-if="status === 'idle' && query.trim().length === 1" class="px-3 py-3 text-sm text-text-2">{{ trans('search.hint') }}</p>
            </div>
            <p class="sr-only" role="status" aria-live="polite">{{ live }}</p>
        </DialogContent>
    </Dialog>
</template>
