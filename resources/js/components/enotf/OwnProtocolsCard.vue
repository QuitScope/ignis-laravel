<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import OwnListCard from '@/components/dashboard/OwnListCard.vue';

// "Eigene eNOTF-Protokolle" (legacy protocols.php): open = not released by the crew yet.
type Item = { id: number; label: string; vehicle: string | null; created_at: string | null; open: boolean; href: string };

defineProps<{ data: { total: number; items: Item[]; href: string; reason?: 'no_profile' } }>();
</script>

<template>
    <OwnListCard
        title-id="home-own-protocols-title"
        :title="trans('dashboard.own.protocols.title')"
        :total="data.total"
        :href="data.href"
        :columns="[trans('dashboard.col.status'), trans('dashboard.col.number'), trans('dashboard.col.vehicle'), trans('dashboard.col.sent')]"
        :empty="!data.items.length"
        :no-profile="data.reason === 'no_profile'"
        empty-icon="file-medical"
        :empty-title="trans('dashboard.own.protocols.empty_title')"
        :empty-text="trans('dashboard.own.protocols.empty_text')"
        :no-profile-text="trans('dashboard.own.protocols.no_profile')"
    >
        <tr v-for="item in data.items" :key="item.id">
            <td>
                <span class="ignis-chip ignis-chip--dot" :data-tone="item.open ? 'warn' : 'ok'">
                    {{ trans(item.open ? 'dashboard.own.protocols.open' : 'dashboard.own.protocols.released') }}
                </span>
            </td>
            <td><Link :href="item.href" class="ignis-mono">{{ item.label }}</Link></td>
            <td>{{ item.vehicle ?? '-' }}</td>
            <td>{{ item.created_at ?? '' }}</td>
        </tr>
    </OwnListCard>
</template>
