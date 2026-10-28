<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import { trans } from 'laravel-vue-i18n';
import { DropdownMenuItemIndicator, DropdownMenuLabel, DropdownMenuRadioGroup, DropdownMenuRadioItem } from 'reka-ui';
import FaIcon from '@/components/FaIcon.vue';
import { faIcons } from '@/lib/faIcons';
import { applyTheme, shouldRollback, THEMES, type ThemeMode } from '@/lib/theme';

// "Darstellung" in the account menu (legacy topbar): three tiles, the chosen one with a tick. Menu radio items:
// arrow keys and aria-checked come from reka-ui, no :has() needed (Chromium 103).
const page = usePage<{ theme: ThemeMode }>();
const current = ref<ThemeMode>(page.props.theme);
watch(() => page.props.theme, (t) => (current.value = t));
const icons = { dark: faIcons.moon, light: faIcons.sun, system: faIcons['circle-half-stroke'] };

const choose = (theme: ThemeMode) => {
    current.value = theme;
    applyTheme(theme);
    router.post('/profile/theme', { theme }, {
        preserveScroll: true,
        onFinish: (visit) => {
            if (!shouldRollback(visit)) return;
            current.value = page.props.theme;
            applyTheme(page.props.theme);
        },
    });
};
</script>

<template>
    <DropdownMenuLabel class="ignis-menu__heading">{{ trans('theme.label') }}</DropdownMenuLabel>
    <DropdownMenuRadioGroup :model-value="current" class="ignis-theme-options" @update:model-value="(t) => choose(t as ThemeMode)">
        <DropdownMenuRadioItem v-for="t in THEMES" :key="t" :value="t" class="ignis-menu__item" @select.prevent>
            <FaIcon :icon="icons[t]" />
            {{ trans(`theme.${t}`) }}
            <DropdownMenuItemIndicator as-child><FaIcon :icon="faIcons.check" class="ignis-menu__check" /></DropdownMenuItemIndicator>
        </DropdownMenuRadioItem>
    </DropdownMenuRadioGroup>
</template>
