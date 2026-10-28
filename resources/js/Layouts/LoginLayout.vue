<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import LoginStage from '@/components/auth/LoginStage.vue';

// Sign-in only (legacy login.php): panel with the shell on the left, stage on the right; below 960 px one column with the
// stage as background. Always dark (lib/pageSkin.ts). Clinic portal and installer keep GuestLayout.
const page = usePage<{ branding: { org: string | null } }>();
</script>

<template>
    <div class="twplus-login">
        <section class="twplus-login__panel">
            <div class="twplus-login__content"><slot /></div>
        </section>
        <aside class="twplus-login__visual" aria-hidden="true">
            <LoginStage :org="page.props.branding.org" />
        </aside>
    </div>
</template>
