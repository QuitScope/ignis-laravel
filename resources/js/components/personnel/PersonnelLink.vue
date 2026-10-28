<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import HoverCard from '@/components/personnel/HoverCard.vue';

// Name linking to the record, with its hover card. Only where the record id is already known.
defineProps<{ id: number; name: string }>();
</script>

<template>
    <HoverCard :url="`/api/personnel/${id}/card`">
        <Link :href="`/personnel/${id}`" class="hover:underline">{{ name }}</Link>
    </HoverCard>
</template>
