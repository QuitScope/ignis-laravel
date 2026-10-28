<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { Button } from '@/components/ui/button';
import { MAP_TYPES, type MapType } from '@/lib/leafletMap';

defineProps<{ canWrite: boolean; mode: 'none' | 'pick' | 'zone'; type: MapType; hasLocation: boolean }>();
defineEmits<{ type: [t: MapType]; marker: []; zone: []; location: [] }>();
</script>

<template>
    <div class="flex flex-wrap items-center gap-2">
        <div role="group" :aria-label="trans('firetab.map.type')" class="flex flex-wrap gap-1">
            <Button
                v-for="t in MAP_TYPES"
                :key="t"
                type="button"
                :variant="t === type ? 'default' : 'secondary'"
                :aria-pressed="t === type"
                class="min-h-11"
                @click="$emit('type', t)"
            >
                {{ trans(`firetab.map.types.${t}`) }}
            </Button>
        </div>
        <template v-if="canWrite">
            <Button type="button" class="min-h-11" :disabled="mode === 'zone'" @click="$emit('marker')">{{ trans('firetab.map.add_marker') }}</Button>
            <Button type="button" class="min-h-11" :disabled="mode === 'zone'" @click="$emit('zone')">{{ trans('firetab.map.draw_zone') }}</Button>
            <Button v-if="hasLocation" type="button" variant="secondary" class="min-h-11" @click="$emit('location')">{{ trans('firetab.map.set_location') }}</Button>
        </template>
    </div>
</template>
