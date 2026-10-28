<script setup lang="ts">
/**
 * Page "Diagnose" (legacy protokoll/diagnose.php): overview = topic column + 3 boxes; ?t=haupt|weitere = category column
 * (with search) -> sheet ?q (Trauma with its 3rd level, sheets show only the severity), haupt = radio, weitere = multiple;
 * a search hit selects its code and jumps to its sheet; ?t=text = free text.
 */
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';
import SummaryBox from '@/components/enotf/ui/SummaryBox.vue';
import TileColumn from '@/components/enotf/ui/TileColumn.vue';
import TileLink from '@/components/enotf/ui/TileLink.vue';
import { type Code, DIAG_STEPS, DIAG_TREE, diagLabel, diagSearch, diagSteps, toggleExclusive, useEnotfForm, useEnotfWizard } from '@/lib/enotfUi';

const form = useEnotfForm();
const wizard = useEnotfWizard();
const t = (k: string) => trans(`enotf.ui.dg.${k}`);

const TOPICS: Record<string, string[] | undefined> = { haupt: ['diagnose_haupt'], weitere: undefined, text: undefined };
const topic = computed(() => (wizard.topic.value && wizard.topic.value in TOPICS && !form.readonly ? wizard.topic.value : null));
const field = computed(() => (topic.value === 'haupt' ? 'diagnose_haupt' : 'diagnose_weitere'));
const step = computed(() => (wizard.step.value ? (DIAG_STEPS[Math.min(wizard.step.value, DIAG_STEPS.length) - 1] ?? null) : null));
const TRAUMA = DIAG_TREE.findIndex((c) => c.subs);
const traumaSteps = diagSteps(TRAUMA);

const weitere = computed(() => (Array.isArray(form.values.diagnose_weitere) ? (form.values.diagnose_weitere as Code[]) : []));
const hauptText = computed(() => (form.values.diagnose_haupt == null ? '' : catalogLabel(form.values.diagnose_haupt as Code)));
const catalogLabel = (c: Code) => (diagLabel(c) === String(c) ? t('unknown') : diagLabel(c));

// ── search (legacy addition): hits replace the sheet; a tap selects and jumps to the sheet ──
const term = ref('');
const hits = computed(() => diagSearch(term.value));
const chosen = (code: Code) => (topic.value === 'haupt' ? String(form.values.diagnose_haupt ?? '') === String(code) : weitere.value.includes(code));
const choose = (code: number, q: number) => {
    if (topic.value === 'haupt') form.set('diagnose_haupt', code);
    else form.set('diagnose_weitere', toggleExclusive(weitere.value, code));
    term.value = '';
    wizard.open(topic.value, q);
};
</script>

<template>
    <div class="flex gap-4">
        <div v-if="!form.readonly" class="enotf-col w-48 shrink-0">
            <TileLink v-for="(req, k) in TOPICS" :key="k" :t="String(k)" :requires="req">{{ t(`topic.${k}`) }}</TileLink>
        </div>

        <div v-if="topic === 'text'" class="enotf-box max-w-xl flex-1">
            <h2 class="enotf-box__title">{{ t('freitext') }}</h2>
            <textarea
                :value="typeof form.values.diagnose === 'string' ? form.values.diagnose : ''"
                rows="5"
                maxlength="10000"
                placeholder="..."
                class="enotf-input resize-none"
                :aria-label="t('freitext')"
                @input="form.set('diagnose', ($event.target as HTMLTextAreaElement).value)"
            />
        </div>

        <div v-else-if="topic" class="flex min-w-0 flex-1 gap-4">
            <div class="enotf-col w-48 shrink-0">
                <input v-model="term" type="search" autocomplete="off" class="enotf-input mb-2" :placeholder="trans('enotf.ui.search')" :aria-label="t('search')" />
                <TileLink v-for="(c, i) in DIAG_TREE" :key="c.label" :t="topic" :q="diagSteps(i)[0]" :covers="diagSteps(i)">{{ c.label }}</TileLink>
            </div>
            <div v-if="term.trim()" class="enotf-col w-72 shrink-0" role="group" :aria-label="t('hits')">
                <button v-for="h in hits" :key="h.code" type="button" class="enotf-tile" :aria-pressed="chosen(h.code)" @click="choose(h.code, h.q)">
                    <span>{{ h.label }}</span>
                </button>
                <p v-if="!hits.length" class="px-3 py-3 text-sm text-(--enotf-label)">{{ t('no_hit') }}</p>
            </div>
            <template v-else-if="step">
                <div v-if="step.sub" class="enotf-col w-48 shrink-0">
                    <TileLink v-for="(q, n) in traumaSteps" :key="q" :t="topic" :q="q">{{ DIAG_TREE[TRAUMA].subs![n].label }}</TileLink>
                </div>
                <TileColumn
                    v-for="(codes, n) in step.cols"
                    :key="`${field}-${wizard.step.value}-${n}`"
                    class="w-56 shrink-0"
                    :field="field"
                    :options="codes.map((c) => [c, diagLabel(c, true)] as [Code, string])"
                    :multiple="topic === 'weitere'"
                />
            </template>
        </div>

        <div v-else class="grid min-w-0 flex-1 grid-cols-2 content-start gap-4">
            <SummaryBox :title="t('topic.haupt')" t="haupt" :requires="['diagnose_haupt']">
                <span class="enotf-input">{{ hauptText || ' ' }}</span>
            </SummaryBox>
            <SummaryBox :title="t('topic.weitere')" t="weitere">
                <span class="enotf-input">{{ weitere.map((c) => diagLabel(c)).join(', ') || ' ' }}</span>
            </SummaryBox>
            <SummaryBox class="col-span-2" :title="t('topic.text')" t="text">
                <p class="enotf-input min-h-32 whitespace-pre-wrap">{{ form.values.diagnose ?? '' }}</p>
            </SummaryBox>
        </div>
    </div>
</template>
