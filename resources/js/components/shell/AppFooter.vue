<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import BrandLockup from '@/components/BrandLockup.vue';

// Footer of the main area (legacy assets/components/footer.php): lockup with "Verwaltungsportal der …" (only with type and
// city), copyright and version, legal links (https only, set in the settings).
defineProps<{ version: string }>();
const page = usePage<{ branding: { tagline: string | null }; legal: { imprint_url: string | null; privacy_url: string | null } }>();
const year = String(new Date().getFullYear());
</script>

<template>
    <footer class="ignis-footer" :aria-label="trans('branding.footer')">
        <div class="ignis-footer__brand">
            <BrandLockup size="footer" />
            <p v-if="page.props.branding.tagline" class="ignis-footer__tagline">{{ page.props.branding.tagline }}</p>
        </div>
        <div class="ignis-footer__center">
            <p>{{ trans('footer.copyright', { year }) }}</p>
            <span class="ignis-footer__version">{{ version }}</span>
        </div>
        <nav v-if="page.props.legal.imprint_url || page.props.legal.privacy_url" class="ignis-footer__legal" :aria-label="trans('branding.legal_nav')">
            <a v-if="page.props.legal.imprint_url" :href="page.props.legal.imprint_url" target="_blank" rel="noopener noreferrer">{{ trans('branding.imprint') }}</a>
            <a v-if="page.props.legal.privacy_url" :href="page.props.legal.privacy_url" target="_blank" rel="noopener noreferrer">{{ trans('branding.privacy') }}</a>
        </nav>
    </footer>
</template>
