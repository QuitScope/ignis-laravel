<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';

// The leader search may also answer federation hits with a string id `fed:{link}:{remote}` (Stammdaten).
export type Hit = { id: number; name: string; identifier?: string };

const props = defineProps<{ url: string; placeholder: string }>();
const emit = defineEmits<{ pick: [hit: Hit] }>();

const q = ref('');
const hits = ref<Hit[]>([]);
const searched = ref(false);

const search = async () => {
    const res = await fetch(`${props.url}?q=${encodeURIComponent(q.value)}`, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
    hits.value = res.ok ? await res.json() : [];
    searched.value = true;
};
const pick = (h: Hit) => {
    emit('pick', h);
    hits.value = [];
    searched.value = false;
    q.value = '';
};
</script>

<template>
    <div class="space-y-2">
        <div class="flex gap-2" role="search">
            <input
                v-model="q"
                type="search"
                maxlength="50"
                autocomplete="off"
                :placeholder="placeholder"
                :aria-label="placeholder"
                class="block min-h-11 w-full rounded-md border border-border bg-bg-2 px-3 py-2 text-text focus-visible:outline-2 focus-visible:outline-accent"
                @keydown.enter.prevent="search"
            />
            <Button type="button" variant="secondary" class="min-h-11" @click="search">{{ trans('firetab.search') }}</Button>
        </div>
        <ul v-if="hits.length" class="divide-y divide-border rounded-md border border-border">
            <li v-for="h in hits" :key="h.id">
                <button type="button" class="flex min-h-11 w-full items-center gap-3 px-4 py-2 text-left hover:bg-bg-2" @click="pick(h)">
                    <span v-if="h.identifier" class="font-mono text-sm text-text-2">{{ h.identifier }}</span>
                    <span>{{ h.name }}</span>
                </button>
            </li>
        </ul>
        <p v-else-if="searched" class="text-sm text-text-2">{{ trans('firetab.search.none') }}</p>
    </div>
</template>
