<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { DropdownMenuContent, DropdownMenuItem, DropdownMenuPortal, DropdownMenuRoot, DropdownMenuTrigger } from 'reka-ui';
import FaIcon from '@/components/FaIcon.vue';
import { faIcons } from '@/lib/faIcons';
import type { NavItem } from '@/lib/navigation';

// "+ Neu" in the topbar: the quick actions the viewer may use (lib/navigation.ts quickActions), each with the icon
// of its sidebar entry as in the legacy menu. Below 900 px only the plus (skin.css).
defineProps<{ actions: NavItem[] }>();
</script>

<template>
    <DropdownMenuRoot v-if="actions.length" :modal="false">
        <DropdownMenuTrigger class="ignis-btn ignis-btn--secondary ignis-topbar__new" :aria-label="trans('app.topbar.new')">
            <FaIcon :icon="faIcons.plus" />
            <span class="ignis-topbar__new-label">{{ trans('app.topbar.new') }}</span>
            <FaIcon :icon="faIcons['chevron-down']" class="ignis-menu__caret" />
        </DropdownMenuTrigger>
        <DropdownMenuPortal>
            <DropdownMenuContent align="end" :side-offset="8" :collision-padding="8" class="ignis-menu__panel">
                <DropdownMenuItem v-for="a in actions" :key="a.href" as-child>
                    <Link :href="a.href" class="ignis-menu__item">
                        <FaIcon :icon="faIcons[a.icon]" />
                        {{ trans(a.label) }}
                    </Link>
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenuPortal>
    </DropdownMenuRoot>
</template>
