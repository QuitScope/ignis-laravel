<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { Button } from '@/components/ui/button';

type Category = { id: number; title: string; compartment: number; items: { id: number; title: string; quantity: number }[] };

// The vehicle page's loadout tab: read-only list of the categories of the vehicle's type, grouped by compartment.
defineProps<{ categories: Category[]; canManage: boolean }>();
</script>

<template>
    <div class="space-y-4">
        <div v-if="canManage" class="flex justify-end">
            <Button as-child size="sm" variant="ghost">
                <Link href="/settings/vehicles/loadouts">{{ trans('loadouts.tab.manage') }}</Link>
            </Button>
        </div>
        <p v-if="!categories.length" class="text-sm text-text-2">{{ trans('loadouts.tab.empty') }}</p>
        <template v-for="n in [0, 1, 2]" :key="n">
            <section v-if="categories.some((c) => c.compartment === n)" class="space-y-2">
                <h3 class="font-medium">{{ trans(`loadouts.compartment.${n}`) }}</h3>
                <div v-for="c in categories.filter((c) => c.compartment === n)" :key="c.id">
                    <h4 class="text-sm font-medium text-text-2">{{ c.title }}</h4>
                    <ul class="divide-y divide-border text-sm">
                        <li v-for="i in c.items" :key="i.id" class="flex justify-between gap-3 py-1.5">
                            <span class="min-w-0 break-words">{{ i.title }}</span>
                            <span class="tabular-nums">{{ i.quantity }}×</span>
                        </li>
                        <li v-if="!c.items.length" class="py-1.5 text-text-2">{{ trans('loadouts.item.empty') }}</li>
                    </ul>
                </div>
            </section>
        </template>
    </div>
</template>
