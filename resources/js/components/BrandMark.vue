<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import BrandLockup from '@/components/BrandLockup.vue';

// The uploaded logo when one is set (alt text = system name), else the ıgnıs lockup.
const page = usePage<{ app: { name: string }; branding: { logo_url: string | null } }>();
withDefaults(defineProps<{ size?: 'topbar' | 'login' | 'footer' }>(), { size: 'topbar' });
</script>

<template>
    <img v-if="page.props.branding.logo_url" :src="page.props.branding.logo_url" :alt="page.props.app.name" class="max-h-8 w-auto max-w-48 object-contain" />
    <BrandLockup v-else :size="size" />
</template>
