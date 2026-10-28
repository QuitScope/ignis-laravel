<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { trans, transChoice } from 'laravel-vue-i18n';
import HoverCard from '@/components/personnel/HoverCard.vue';
import VehicleStatusBadge from '@/components/vehicles/VehicleStatusBadge.vue';

// Vehicle name linking to its page, with the hover card (GET /api/vehicles/{id}/card).
defineProps<{ id: number; name: string }>();

type VehicleCard = {
    name: string;
    identifier: string;
    type: string;
    service_label: string;
    license_plate: string | null;
    state: 'operable' | 'blocked' | 'inactive';
    blocking_defects: number;
    info_defects: number;
};
const asCard = (c: unknown) => c as VehicleCard;
</script>

<template>
    <HoverCard :url="`/api/vehicles/${id}/card`">
        <Link :href="`/vehicles/${id}`" class="hover:underline">{{ name }}</Link>
        <template #card="{ card }">
            <span class="block truncate font-medium">{{ asCard(card).name }}</span>
            <span class="block text-xs text-text-2">{{ asCard(card).identifier }}</span>
            <span class="mt-2 block space-y-1 text-xs">
                <span class="block">{{ asCard(card).type }} · {{ asCard(card).service_label }}</span>
                <span v-if="asCard(card).license_plate" class="block">{{ trans('vehicles.field.license_plate') }}: {{ asCard(card).license_plate }}</span>
                <span class="block"><VehicleStatusBadge :state="asCard(card).state" :blocking="asCard(card).blocking_defects" /></span>
                <span v-if="asCard(card).info_defects" class="block text-text-2">
                    {{ transChoice('vehicles.card.info_defects', asCard(card).info_defects) }}
                </span>
            </span>
        </template>
    </HoverCard>
</template>
