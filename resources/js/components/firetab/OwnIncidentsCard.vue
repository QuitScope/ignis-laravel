<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import OwnListCard from '@/components/dashboard/OwnListCard.vue';

// "Eigene fireTab-Protokolle" (legacy fire-protocols.php): incidents the own record leads; open = not finalised.
type Item = { id: number; label: string; location: string | null; started_at: string | null; open: boolean; href: string };

defineProps<{ data: { total: number; items: Item[]; href: string; reason?: 'no_profile' } }>();
</script>

<template>
    <OwnListCard
        title-id="home-own-incidents-title"
        :title="trans('dashboard.own.incidents.title')"
        :total="data.total"
        :href="data.href"
        :columns="[trans('dashboard.col.status'), trans('dashboard.col.number'), trans('dashboard.col.location'), trans('dashboard.col.start')]"
        :empty="!data.items.length"
        :no-profile="data.reason === 'no_profile'"
        empty-icon="fire"
        :empty-title="trans('dashboard.own.incidents.empty_title')"
        :empty-text="trans('dashboard.own.incidents.empty_text')"
        :no-profile-text="trans('dashboard.own.incidents.no_profile')"
    >
        <tr v-for="item in data.items" :key="item.id">
            <td>
                <span class="ignis-chip ignis-chip--dot" :data-tone="item.open ? 'info' : 'ok'">
                    {{ trans(item.open ? 'dashboard.own.incidents.open' : 'dashboard.own.incidents.done') }}
                </span>
            </td>
            <td><Link :href="item.href" class="ignis-mono">{{ item.label }}</Link></td>
            <td>{{ item.location ?? '-' }}</td>
            <td>{{ item.started_at ?? '' }}</td>
        </tr>
    </OwnListCard>
</template>
