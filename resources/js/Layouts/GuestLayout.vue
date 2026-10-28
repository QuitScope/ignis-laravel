<script setup lang="ts">
import BrandFooter from '@/components/BrandFooter.vue';
</script>

<template>
    <div class="flex min-h-screen flex-col bg-bg text-text">
        <main class="flex w-full flex-1 items-center justify-center p-4">
            <div class="w-full"><slot /></div>
        </main>
        <BrandFooter />
    </div>
</template>
