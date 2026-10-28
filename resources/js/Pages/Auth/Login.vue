<script setup lang="ts">
import { useForm, usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';
import BrandMark from '@/components/BrandMark.vue';
import FaIcon from '@/components/FaIcon.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import LoginLayout from '@/Layouts/LoginLayout.vue';
import { faIcons } from '@/lib/faIcons';

defineOptions({ layout: LoginLayout });

defineProps<{
    error: string | null;
    registrationMode: 'open' | 'code' | 'closed';
    city: string | null;
}>();

const page = usePage<{ app: { name: string; version: string }; legal: { imprint_url: string | null; privacy_url: string | null } }>();
const version = computed(() => (/^\d/.test(page.props.app.version) ? `v${page.props.app.version}` : page.props.app.version));

const form = useForm({ code: '' });
const submitCode = () => form.post('/invite');
</script>

<template>
    <div class="twplus-login__brand"><BrandMark size="login" /></div>
    <div class="twplus-login__well">
        <h1 class="twplus-login__title">{{ trans('auth.login.title') }}</h1>
        <p class="twplus-login__lead">{{ trans('auth.login.lead', { name: city ?? page.props.app.name }) }}</p>

        <div v-if="error" role="alert" class="ignis-alert ignis-alert--danger mb-6">
            <FaIcon :icon="faIcons['triangle-exclamation']" class="ignis-alert__icon" />
            <div class="ignis-alert__body">
                <strong>{{ trans('auth.error.title') }}</strong><br />{{ error }}
            </div>
        </div>
        <div v-else-if="registrationMode === 'closed'" role="status" class="ignis-alert ignis-alert--warn mb-6">
            <FaIcon :icon="faIcons.lock" class="ignis-alert__icon" />
            <div class="ignis-alert__body">{{ trans('auth.login.closed_hint') }}</div>
        </div>
        <div v-else-if="registrationMode === 'code'" role="status" class="ignis-alert ignis-alert--info mb-6">
            <FaIcon :icon="faIcons.ticket" class="ignis-alert__icon" />
            <div class="ignis-alert__body">
                <strong>{{ trans('auth.login.code_title') }}</strong><br />{{ trans('auth.login.code_hint') }}
            </div>
        </div>

        <!-- POST: the code must never end up in a URL (history, Referer, logs). Spacing as in the legacy page, whose
             mb-3/mb-4 were Bootstrap's 16/24 px and whose label was inline-block with 8 px below. -->
        <template v-if="registrationMode === 'code'">
            <form class="mb-6" @submit.prevent="submitCode">
                <label class="mb-2 block text-[0.82rem] leading-[21px] font-medium text-text-2" for="code">{{ trans('auth.login.code_label') }}</label>
                <Input
                    id="code"
                    v-model="form.code"
                    name="code"
                    type="text"
                    required
                    autocomplete="one-time-code"
                    :placeholder="trans('auth.login.code_placeholder')"
                    class="mb-4 text-sm"
                />
                <Button type="submit" variant="secondary" class="w-full">{{ trans('auth.login.code_submit') }}</Button>
            </form>
            <p class="mb-4 pt-[3px] text-center text-[0.85em] text-text-3">{{ trans('auth.login.or') }}</p>
        </template>

        <div class="twplus-login__actions">
            <Button as-child size="lg" class="w-full">
                <a href="/auth/discord"><FaIcon :icon="faIcons.discord" />{{ trans('auth.login.discord') }}</a>
            </Button>
        </div>
    </div>
    <p class="twplus-login__foot">
        <span>ıgnıs {{ version }} ·</span>
        <span>EmergencyForge</span>
    </p>
    <p v-if="page.props.legal.imprint_url || page.props.legal.privacy_url" class="mt-2 mb-2 text-center text-xs text-text-2">
        <a v-if="page.props.legal.imprint_url" :href="page.props.legal.imprint_url" target="_blank" rel="noopener noreferrer" class="hover:underline">{{ trans('branding.imprint') }}</a>
        <span v-if="page.props.legal.imprint_url && page.props.legal.privacy_url" class="mx-2">|</span>
        <a v-if="page.props.legal.privacy_url" :href="page.props.legal.privacy_url" target="_blank" rel="noopener noreferrer" class="hover:underline">{{ trans('branding.privacy') }}</a>
    </p>
</template>
