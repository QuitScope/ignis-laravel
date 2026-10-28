<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { computed } from 'vue';
import { trans } from 'laravel-vue-i18n';
import { DropdownMenuContent, DropdownMenuItem, DropdownMenuPortal, DropdownMenuRoot, DropdownMenuSeparator, DropdownMenuTrigger } from 'reka-ui';
import DiscordDmSwitch from '@/components/DiscordDmSwitch.vue';
import FaIcon from '@/components/FaIcon.vue';
import ThemeSwitch from '@/components/ThemeSwitch.vue';
import { faIcons } from '@/lib/faIcons';
import { can } from '@/lib/navigation';

export type AccountUser = {
    name: string | null;
    username: string;
    role: { name: string; color: string } | null;
    is_super_admin: boolean;
    permissions: string[];
    personnel: { id: number; full_name: string } | null;
};

// Account menu of the topbar (legacy assets/components/topbar.php): initials as the trigger, the panel with
// identity and role, appearance, Discord DMs, own record and sign-out.
const props = defineProps<{ user: AccountUser }>();
const display = computed(() => props.user.name ?? props.user.username);
const initials = computed(() => [...display.value].slice(0, 2).join('').toUpperCase());
const roleColors: Record<string, string> = {
    primary: 'var(--accent)',
    secondary: 'var(--text-3)',
    success: 'var(--ok)',
    danger: 'var(--danger)',
    warning: 'var(--warn)',
    info: 'var(--info)',
    light: 'var(--text-2)',
    dark: 'var(--text-3)',
};
const roleColor = computed(() => roleColors[props.user.role?.color ?? ''] ?? 'var(--text-3)');
const profile = computed(() => (props.user.personnel && can(props.user, 'personnel.view') ? `/personnel/${props.user.personnel.id}` : null));
const logout = () => router.post('/logout');
</script>

<template>
    <DropdownMenuRoot :modal="false">
        <DropdownMenuTrigger class="ignis-topbar__user" :aria-label="trans('app.topbar.account')">
            <span class="ignis-topbar__avatar" aria-hidden="true">{{ initials }}</span>
            <FaIcon :icon="faIcons['chevron-down']" class="ignis-menu__caret" />
        </DropdownMenuTrigger>
        <DropdownMenuPortal>
            <DropdownMenuContent align="end" :side-offset="8" :collision-padding="8" class="ignis-menu__panel ignis-menu__panel--account">
                <div class="ignis-menu__identity">
                    <span class="ignis-topbar__avatar" aria-hidden="true">{{ initials }}</span>
                    <span class="ignis-menu__identity-text">
                        <span class="ignis-menu__identity-name">{{ display }}</span>
                        <span v-if="user.role" class="ignis-menu__identity-role">
                            <span class="ignis-menu__role-dot" :style="{ background: roleColor }" />{{ user.role.name }}
                        </span>
                    </span>
                </div>
                <DropdownMenuSeparator class="ignis-menu__sep" />
                <ThemeSwitch />
                <DiscordDmSwitch />
                <DropdownMenuSeparator class="ignis-menu__sep" />
                <DropdownMenuItem v-if="profile" as-child>
                    <Link :href="profile" class="ignis-menu__item"><FaIcon :icon="faIcons['id-badge']" /> {{ trans('personnel.profile.my') }}</Link>
                </DropdownMenuItem>
                <DropdownMenuItem class="ignis-menu__item" @select="logout">
                    <FaIcon :icon="faIcons['arrow-right-from-bracket']" /> {{ trans('auth.logout') }}
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenuPortal>
    </DropdownMenuRoot>
</template>
