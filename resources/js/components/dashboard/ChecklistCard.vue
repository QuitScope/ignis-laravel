<script setup lang="ts">
import { computed, ref } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import FaIcon from '@/components/FaIcon.vue';
import { faIcons } from '@/lib/faIcons';
import { dismissSetup, isSetupDismissed } from '@/lib/dashboardWidgets';

// Legacy setup-checklist.php: centred empty state "… ist fast startklar" with progress and numbered steps; the first
// open required step carries "Jetzt einrichten". Hidden per browser (legacy localStorage key intra_setup_dismissed).
type Step = { key: string; label: string; done: boolean; href: string; required: boolean; next: boolean };

const props = defineProps<{ data: { steps: Step[] } }>();

const page = usePage<{ app: { name: string } }>();
const done = computed(() => props.data.steps.filter((s) => s.done).length);
const progress = computed(() => trans('dashboard.setup.progress', { done: String(done.value), total: String(props.data.steps.length) }));
const share = computed(() => (props.data.steps.length ? Math.round((done.value / props.data.steps.length) * 100) : 0));

const dismissed = ref(isSetupDismissed());
const hide = () => {
    dismissSetup();
    dismissed.value = true;
};
</script>

<template>
    <section v-if="!dismissed" class="ignis-setup" aria-labelledby="setup-title">
        <div class="ignis-setup__bar">
            <button type="button" class="ignis-btn ignis-btn--ghost ignis-btn--sm" @click="hide">{{ trans('dashboard.setup.dismiss') }}</button>
        </div>
        <div class="ignis-empty" data-tone="info">
            <span class="ignis-glyph" data-tone="info"><FaIcon :icon="faIcons['flag-checkered']" class="size-5" /></span>
            <h2 id="setup-title" class="ignis-empty__title">{{ trans('dashboard.setup.title', { name: page.props.app.name }) }}</h2>
            <p class="ignis-empty__text">{{ trans('dashboard.setup.lead') }}</p>
            <div class="ignis-empty__progress">
                <span aria-hidden="true">{{ progress }}</span>
                <div class="ignis-progress ignis-progress--sm" role="progressbar" :aria-label="progress" :aria-valuenow="share" aria-valuemin="0" aria-valuemax="100" :style="{ '--value': share / 100 }">
                    <div class="ignis-progress__bar" />
                </div>
            </div>
            <ol class="ignis-empty__steps">
                <li v-for="(step, i) in data.steps" :key="step.key" :data-state="step.done ? 'done' : step.next ? 'current' : 'todo'">
                    <span class="ignis-empty__step-no">
                        <template v-if="step.done"><FaIcon :icon="faIcons.check" /><span class="sr-only">{{ trans('dashboard.setup.done') }}</span></template>
                        <template v-else>{{ i + 1 }}</template>
                    </span>
                    <span>{{ step.label }}<template v-if="!step.required"> ({{ trans('dashboard.setup.optional') }})</template></span>
                    <Link v-if="!step.done" :href="step.href" class="ignis-btn ignis-btn--sm" :class="step.next ? 'ignis-btn--primary btn-primary-skin' : 'ignis-btn--secondary'">
                        {{ step.next ? trans('dashboard.setup.next') : trans('dashboard.setup.open') }}
                    </Link>
                </li>
            </ol>
        </div>
    </section>
</template>
