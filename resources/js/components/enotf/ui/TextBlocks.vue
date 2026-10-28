<script setup lang="ts">
/**
 * Anamnesis text blocks (legacy anamnese.php textbloecke): a main tile inserts its heading (newline mode 2) and opens its
 * sub-menu, tapping it again closes the menu; a sub tile inserts its sentence (mode 0). The parent inserts at the cursor.
 */
import { trans } from 'laravel-vue-i18n';
import { ref } from 'vue';

const emit = defineEmits<{ insert: [text: string, mode: number] }>();
const BLOCKS: Record<string, string[]> = {
    vorerkrankungen: ['keine', 'ne'],
    medikation: ['keine', 'ne'],
    allergien: ['keine'],
    drogen: ['nikotin', 'alkohol', 'opiate', 'benzo', 'foetor'],
};
const t = (k: string) => trans(`enotf.ui.an.tb.${k}`);
const open = ref<string | null>(null);
const main = (key: string) => {
    if (open.value === key) {
        open.value = null;
        return;
    }
    emit('insert', t(`${key}.text`), 2);
    open.value = key;
};
</script>

<template>
    <div class="flex">
        <div class="enotf-col w-56">
            <button v-for="(_, key) in BLOCKS" :key="key" type="button" class="enotf-tile" :aria-pressed="open === key" @mousedown.prevent @click="main(String(key))">
                <span>{{ t(String(key)) }}</span>
            </button>
        </div>
        <div v-if="open" class="enotf-col w-56">
            <button v-for="sub in BLOCKS[open]" :key="sub" type="button" class="enotf-tile" @mousedown.prevent @click="emit('insert', t(`${open}.${sub}.text`), 0)">
                <span>{{ t(`${open}.${sub}`) }}</span>
            </button>
        </div>
    </div>
</template>
