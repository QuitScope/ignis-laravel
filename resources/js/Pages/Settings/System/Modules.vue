<script setup lang="ts">
import { computed, reactive, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';

type Module = { name: string; label: string; description: string; group: 'einsatz' | 'verwaltung'; enabled: boolean; depends: string[] };

const props = defineProps<{ modules: Module[] }>();

const page = usePage();
const errors = computed(() => (page.props.errors ?? {}) as Record<string, string>);
const card = 'rounded-lg border border-border bg-card p-4 shadow-(--shadow)';
const groups = (['einsatz', 'verwaltung'] as const)
    .map((key) => ({ key, modules: props.modules.filter((m) => m.group === key) }))
    .filter((g) => g.modules.length > 0);

const on = reactive<Record<string, boolean>>(Object.fromEntries(props.modules.map((m) => [m.name, m.enabled])));
const changed = computed(() => Object.fromEntries(props.modules.filter((m) => on[m.name] !== m.enabled).map((m) => [m.name, on[m.name]])));
const saving = ref(false);

function save() {
    router.put('/settings/system/modules', { modules: changed.value }, {
        preserveScroll: true,
        preserveState: 'errors',
        onStart: () => (saving.value = true),
        onFinish: () => (saving.value = false),
    });
}
</script>

<template>
    <div class="space-y-4">
        <div>
            <h1 class="text-2xl font-semibold">{{ trans('settings.modules.title') }}</h1>
            <p class="mt-1 text-sm text-text-2">{{ trans('settings.modules.lead') }}</p>
        </div>

        <p v-if="!modules.length" :class="[card, 'max-w-2xl text-sm text-text-2']">{{ trans('settings.modules.empty') }}</p>

        <form v-else class="max-w-2xl space-y-4" @submit.prevent="save">
            <p v-if="errors.modules" class="text-sm text-danger" role="alert">{{ errors.modules }}</p>
            <fieldset v-for="g in groups" :key="g.key" :class="[card, 'space-y-3']">
                <legend class="px-1 text-lg font-medium">{{ trans('settings.modules.group.' + g.key) }}</legend>
                <div v-for="m in g.modules" :key="m.name" class="flex items-start gap-3">
                    <Checkbox
                        :id="`module-${m.name}`"
                        :model-value="on[m.name]"
                        :aria-describedby="`module-${m.name}-hint`"
                        :aria-invalid="errors[`modules.${m.name}`] ? true : undefined"
                        class="mt-0.5"
                        @update:model-value="(v) => (on[m.name] = v === true)"
                    />
                    <div :id="`module-${m.name}-hint`" class="text-sm">
                        <label :for="`module-${m.name}`" class="font-medium">{{ m.label }}</label>
                        <p v-if="m.description" class="text-text-2">{{ m.description }}</p>
                        <p v-if="m.depends.length" class="text-text-2">{{ trans('settings.modules.depends', { modules: m.depends.join(', ') }) }}</p>
                        <p v-if="errors[`modules.${m.name}`]" class="text-danger" role="alert">{{ errors[`modules.${m.name}`] }}</p>
                    </div>
                </div>
            </fieldset>
            <Button type="submit" :disabled="saving || !Object.keys(changed).length">{{ trans('lookup.save') }}</Button>
        </form>
    </div>
</template>
