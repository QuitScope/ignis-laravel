<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import FaIcon from '@/components/FaIcon.vue';
import OwnListCard from '@/components/dashboard/OwnListCard.vue';
import type { Row } from '@/components/forms/SubmissionList.vue';
import { faIcons } from '@/lib/faIcons';
import type { Tone } from '@/lib/dashboardWidgets';

// "Eigene Anträge" (legacy applications.php) with "+ Antrag einreichen" in the head.
defineProps<{ data: { total: number; items: Row[]; href: string; reason?: 'no_profile' } }>();

const tone: Record<string, Tone> = { in_progress: 'info', rejected: 'danger', deferred: 'warn', accepted: 'ok' };
const at = (iso: string) => new Date(iso).toLocaleString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }).replace(',', ' |');
</script>

<template>
    <OwnListCard
        title-id="home-applications-title"
        :title="trans('dashboard.own.applications.title')"
        :total="data.total"
        :href="data.href"
        :columns="[trans('dashboard.col.type'), trans('dashboard.col.status'), trans('dashboard.col.number'), trans('dashboard.col.handler'), trans('dashboard.col.submitted')]"
        :empty="!data.items.length"
        :no-profile="data.reason === 'no_profile'"
        empty-icon="clipboard-list"
        :empty-title="trans('dashboard.own.applications.empty_title')"
        :empty-text="trans('dashboard.own.applications.empty_text')"
        :no-profile-text="trans('dashboard.own.applications.no_profile')"
    >
        <template #actions>
            <Link href="/forms" class="ignis-btn ignis-btn--secondary ignis-btn--sm"><FaIcon :icon="faIcons.plus" />{{ trans('home.apply') }}</Link>
        </template>
        <tr v-for="item in data.items" :key="item.public_id">
            <td>{{ item.type }}</td>
            <td><span class="ignis-chip ignis-chip--dot" :data-tone="tone[item.status]">{{ trans(`forms.status.${item.status}`) }}</span></td>
            <td><Link :href="item.href" class="ignis-mono">{{ item.public_id }}</Link></td>
            <td>{{ item.decided_by_name ?? '-' }}</td>
            <td>{{ item.submitted_at ? at(item.submitted_at) : '' }}</td>
        </tr>
    </OwnListCard>
</template>
