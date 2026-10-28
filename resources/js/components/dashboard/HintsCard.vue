<script setup lang="ts">
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import FaIcon from '@/components/FaIcon.vue';
import { faIcons, type FaIconName } from '@/lib/faIcons';
import { since, type Tone } from '@/lib/dashboardWidgets';
import Bezel from './Bezel.vue';

// "Hinweise" (legacy hints.php): one card per hint with a toned glyph, optional progress and action. `:since` in title
// or text becomes the time elapsed since `since`.
type Hint = {
    tone: Tone;
    icon: string;
    title: string;
    text?: string;
    href?: string;
    action?: { label: string; href: string };
    progress?: { value: number; label: string };
    since?: string;
};

const props = defineProps<{ data: { hints: Hint[] } }>();

const page = usePage<{ locale: string }>();
const now = new Date();
const fill = (s: string | undefined, at: string | undefined) => (s && at ? s.replace(':since', since(at, now, page.props.locale)) : s);
const icon = (name: string) => faIcons[name as FaIconName] ?? faIcons['circle-info'];
const hints = computed(() => props.data.hints.map((h) => ({ ...h, title: fill(h.title, h.since), text: fill(h.text, h.since) })));
</script>

<template>
    <Bezel icon="lightbulb" title-id="home-hints-title" class="ignis-hints">
        <template #title>{{ trans('dashboard.hints.title') }}</template>
        <div class="ignis-bezel__scroll">
            <div v-for="(hint, i) in hints" :key="i" class="ignis-hint">
                <div class="ignis-hint__head">
                    <span class="ignis-glyph ignis-glyph--sm" :data-tone="hint.tone"><FaIcon :icon="icon(hint.icon)" /></span>
                    <h3 class="ignis-hint__title">
                        <Link v-if="hint.href" :href="hint.href">{{ hint.title }}</Link>
                        <template v-else>{{ hint.title }}</template>
                    </h3>
                </div>
                <p v-if="hint.text" class="ignis-hint__text">{{ hint.text }}</p>
                <div
                    v-if="hint.progress"
                    class="ignis-progress ignis-progress--sm ignis-progress--labeled-head"
                    role="progressbar"
                    :aria-valuenow="hint.progress.value"
                    aria-valuemin="0"
                    aria-valuemax="100"
                    :aria-label="hint.progress.label"
                    :style="{ '--value': hint.progress.value / 100 }"
                >
                    <div class="ignis-progress__bar" />
                    <span class="ignis-progress__value" aria-hidden="true">{{ hint.progress.value }} %</span>
                </div>
                <Link v-if="hint.action" :href="hint.action.href" class="ignis-btn ignis-btn--accent-soft ignis-btn--sm ignis-hint__action">{{ hint.action.label }}</Link>
            </div>
        </div>
    </Bezel>
</template>
