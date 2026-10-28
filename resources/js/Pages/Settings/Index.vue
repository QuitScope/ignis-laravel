<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';
import FaIcon from '@/components/FaIcon.vue';
import { faIcons } from '@/lib/faIcons';
import { settingsGroups, type ModuleSwitches, type NavUser } from '@/lib/navigation';

// Settings overview (legacy /settings/index, templates/settings/index.php): one section per group, one tile per page the
// account may open. The page itself needs a sign-in only; every target checks its own right.
const page = usePage<{ app: { modules?: ModuleSwitches }; auth: { user: NavUser | null } }>();
const groups = computed(() => settingsGroups(page.props.auth.user, page.props.app.modules ?? []));
</script>

<template>
    <div class="space-y-6">
        <header class="twplus-page-header">
            <div>
                <h1>{{ trans('settings.index.title') }}</h1>
                <p class="twplus-page-header__description">{{ trans('settings.index.lead') }}</p>
            </div>
        </header>

        <div v-if="groups.length === 0" class="ignis-empty" role="status">
            <span class="ignis-glyph"><FaIcon :icon="faIcons.lock" /></span>
            <p class="ignis-empty__title">{{ trans('settings.index.empty_title') }}</p>
            <p class="ignis-empty__text">{{ trans('settings.index.empty_text') }}</p>
        </div>

        <section v-for="group in groups" :key="group.key" :aria-labelledby="`settings-${group.key}`">
            <h2 :id="`settings-${group.key}`" class="mb-3 text-base font-semibold">{{ trans(group.heading ?? '') }}</h2>
            <div class="twplus-link-grid">
                <Link v-for="item in group.items" :key="item.href" :href="item.href" class="twplus-link-card">
                    <span class="ignis-glyph"><FaIcon :icon="faIcons[item.icon]" /></span>
                    <span class="twplus-link-card__body">
                        <span class="twplus-link-card__title">{{ trans(item.label) }}</span>
                        <span v-if="item.description" class="twplus-link-card__description">{{ trans(item.description) }}</span>
                    </span>
                    <FaIcon :icon="faIcons['chevron-right']" class="twplus-link-card__arrow" />
                </Link>
            </div>
        </section>
    </div>
</template>
