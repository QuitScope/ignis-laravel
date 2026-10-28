<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { Check, CircleHelp, X } from '@lucide/vue';
import { Button } from '@/components/ui/button';

// The signed-in person's answer to an invitation; the parent sends it and passes the stored value back.
defineProps<{ current: string | null; busy: boolean }>();
const emit = defineEmits<{ respond: [value: 'accepted' | 'declined' | 'tentative'] }>();

const options = [
    { value: 'accepted', icon: Check },
    { value: 'declined', icon: X },
    { value: 'tentative', icon: CircleHelp },
] as const;
</script>

<template>
    <div role="group" :aria-label="trans('calendar.response.label')" class="flex flex-wrap items-center gap-2">
        <Button
            v-for="o in options"
            :key="o.value"
            type="button"
            size="sm"
            :variant="current === o.value ? 'default' : 'outline'"
            :aria-pressed="current === o.value"
            :disabled="busy"
            :data-test="`respond-${o.value}`"
            @click="emit('respond', o.value)"
        >
            <component :is="o.icon" aria-hidden="true" />
            {{ trans(`calendar.response.${o.value}`) }}
        </Button>
    </div>
</template>
