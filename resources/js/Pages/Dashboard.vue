<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { ChevronRight, SlidersHorizontal } from '@lucide/vue';
import { linkProps, loadTileIcons, tileIcon } from '@/lib/tileIcons';

type QuickLink = { id: number; title: string; url: string; icon: string | null };
type Category = { id: number; title: string; links: QuickLink[] };

defineProps<{ categories: Category[]; can: { configure: boolean } }>();
void loadTileIcons();
</script>

<template>
    <div class="space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold">{{ trans('dashboard.title') }}</h1>
                <p class="mt-1 text-sm text-text-2">{{ trans('dashboard.lead') }}</p>
            </div>
            <Link v-if="can.configure" href="/settings/dashboard" class="inline-flex h-9 items-center gap-2 rounded-md border border-border bg-card px-3 text-sm hover:bg-(--fill-3)">
                <SlidersHorizontal class="size-4" aria-hidden="true" />
                {{ trans('dashboard.configure') }}
            </Link>
        </div>

        <p v-if="!categories.length" class="rounded-md border border-border bg-card p-4 text-sm" role="status">
            {{ trans('dashboard.empty') }}
            <template v-if="can.configure"> {{ trans('dashboard.empty_manage') }}</template>
        </p>

        <section v-for="category in categories" :key="category.id" class="space-y-3">
            <h2 class="text-lg font-medium">{{ category.title }}</h2>
            <p v-if="!category.links.length" class="text-sm text-text-2">{{ trans('dashboard.no_links') }}</p>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <!-- Text only: titles and targets are user data, never markup. External targets leave in a new tab without opener. -->
                <a
                    v-for="link in category.links"
                    :key="link.id"
                    v-bind="linkProps(link.url)"
                    class="flex items-center gap-3 rounded-lg border border-border bg-card p-4 shadow-(--shadow) hover:bg-(--fill-3)"
                >
                    <component :is="tileIcon(link.icon)" class="size-5 shrink-0 text-accent" aria-hidden="true" />
                    <span class="min-w-0 flex-1">
                        <span class="block break-words font-medium">{{ link.title }}</span>
                        <span class="block text-xs text-text-2">{{ trans('dashboard.open') }}<template v-if="linkProps(link.url).target"> {{ trans('dashboard.new_tab') }}</template></span>
                    </span>
                    <ChevronRight class="size-4 shrink-0 text-text-2" aria-hidden="true" />
                </a>
            </div>
        </section>
    </div>
</template>
