<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import { trans } from 'laravel-vue-i18n';
import { DropdownMenuCheckboxItem, DropdownMenuItemIndicator, DropdownMenuLabel } from 'reka-ui';
import FaIcon from '@/components/FaIcon.vue';
import { faIcons } from '@/lib/faIcons';

// In the account menu (legacy topbar). null: the bot is off or the account has no Discord id, then the switch is
// not shown at all (legacy preference()).
const page = usePage<{ discordDm: boolean | null }>();
const on = ref(page.props.discordDm === true);
watch(() => page.props.discordDm, (v) => (on.value = v === true));

const toggle = (value: boolean) => {
    on.value = value;
    router.post('/profile/discord-dm', { discord_dm: value }, { preserveScroll: true, onFinish: () => (on.value = page.props.discordDm === true) });
};
</script>

<template>
    <template v-if="page.props.discordDm !== null && page.props.discordDm !== undefined">
        <DropdownMenuLabel class="ignis-menu__heading">Discord</DropdownMenuLabel>
        <DropdownMenuCheckboxItem :model-value="on" class="ignis-menu__item" @update:model-value="(v) => toggle(v === true)" @select.prevent>
            <FaIcon :icon="faIcons.discord" />
            {{ trans('profile.discord_dm_menu') }}
            <DropdownMenuItemIndicator as-child><FaIcon :icon="faIcons.check" class="ignis-menu__check" /></DropdownMenuItemIndicator>
        </DropdownMenuCheckboxItem>
    </template>
</template>
