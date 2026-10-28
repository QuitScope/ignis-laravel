<script setup lang="ts">
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { dateLabel } from '@/lib/dashboardWidgets';
import { safeHttpsLink } from '@/lib/announcements';
import EmptyState from './EmptyState.vue';
import FaIcon from '@/components/FaIcon.vue';
import { faIcons } from '@/lib/faIcons';

// "Ankündigungen" (legacy changelog.php): list, or an empty state with the refresh command; a failed fetch is a warning.
type Entry = { id: number; title: string; url: string; excerpt: string | null; pinned: boolean; created_at: string };

defineProps<{ data: { items: Entry[]; problem: string | null; blog_url: string | null } }>();

const page = usePage<{ locale: string }>();
const locale = computed(() => page.props.locale);
</script>

<template>
    <section class="ignis-card" aria-labelledby="home-announcements-title">
        <div class="ignis-card__header">
            <h2 id="home-announcements-title" class="ignis-card__title">{{ trans('announcements.widget.title') }}</h2>
            <div v-if="safeHttpsLink(data.blog_url)" class="ignis-card__actions">
                <a :href="safeHttpsLink(data.blog_url) ?? undefined" target="_blank" rel="noopener noreferrer" class="ignis-btn ignis-btn--secondary ignis-btn--sm">
                    {{ trans('dashboard.announcements.blog') }} <FaIcon :icon="faIcons['arrow-up-right-from-square']" />
                </a>
            </div>
        </div>
        <p v-if="data.problem && data.items.length" class="ignis-card__problem" role="status">
            {{ trans('announcements.widget.problem', { code: data.problem }) }}
        </p>
        <EmptyState
            v-if="!data.items.length"
            :icon="data.problem ? 'triangle-exclamation' : 'bullhorn'"
            :tone="data.problem ? 'warn' : undefined"
            :title="trans('dashboard.announcements.empty_title')"
            :text="data.problem ? trans('announcements.widget.problem', { code: data.problem }) : trans('dashboard.announcements.empty_text')"
            code="php artisan announcements:refresh"
        />
        <ul v-else class="ignis-announcements">
            <li v-for="e in data.items" :key="e.id">
                <div class="ignis-announcements__row">
                    <a v-if="safeHttpsLink(e.url)" :href="safeHttpsLink(e.url) ?? undefined" target="_blank" rel="noopener noreferrer" class="ignis-announcements__title">{{ e.title }}</a>
                    <span v-else class="ignis-announcements__title">{{ e.title }}</span>
                    <time class="ignis-announcements__date" :datetime="e.created_at">{{ dateLabel(e.created_at, locale) }}</time>
                </div>
                <p v-if="e.excerpt" class="ignis-announcements__preview">{{ e.excerpt }}</p>
            </li>
        </ul>
    </section>
</template>
