<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';

// "Verwaltungsportal der ..." (only with organisation type and city) and the legal links (https only, set in the settings).
const page = usePage<{ branding: { tagline: string | null }; legal: { imprint_url: string | null; privacy_url: string | null } }>();
</script>

<template>
    <footer :aria-label="trans('branding.footer')" v-if="page.props.branding.tagline || page.props.legal.imprint_url || page.props.legal.privacy_url" class="flex flex-wrap items-center justify-between gap-2 border-t border-border px-6 py-3 text-sm text-text-2">
        <p v-if="page.props.branding.tagline">{{ page.props.branding.tagline }}</p>
        <nav v-if="page.props.legal.imprint_url || page.props.legal.privacy_url" class="flex gap-3" :aria-label="trans('branding.legal_nav')">
            <a v-if="page.props.legal.imprint_url" :href="page.props.legal.imprint_url" target="_blank" rel="noopener noreferrer" class="hover:underline">{{ trans('branding.imprint') }}</a>
            <a v-if="page.props.legal.privacy_url" :href="page.props.legal.privacy_url" target="_blank" rel="noopener noreferrer" class="hover:underline">{{ trans('branding.privacy') }}</a>
        </nav>
    </footer>
</template>
