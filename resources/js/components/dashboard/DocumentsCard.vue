<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { localDate } from '@/lib/utils';
import OwnListCard from './OwnListCard.vue';

type Item = { title: string; type_label: string; date: string | null; href: string };

defineProps<{ data: { total: number; items: Item[]; href: string; reason?: 'no_profile' } }>();
</script>

<template>
    <OwnListCard
        title-id="home-documents-title"
        :title="trans('home.documents.title')"
        :total="data.total"
        :href="data.href"
        :columns="[trans('dashboard.col.doc_type'), trans('dashboard.col.document'), trans('dashboard.col.issued')]"
        :empty="!data.items.length"
        :no-profile="data.reason === 'no_profile'"
        empty-icon="file-lines"
        :empty-title="trans('dashboard.own.documents.empty_title')"
        :empty-text="trans('dashboard.own.documents.empty_text')"
        :no-profile-text="trans('dashboard.own.documents.no_profile')"
    >
        <tr v-for="item in data.items" :key="item.href">
            <td><span class="ignis-chip">{{ item.type_label }}</span></td>
            <td><Link :href="item.href">{{ item.title }}</Link></td>
            <td>{{ item.date ? localDate(item.date) : '' }}</td>
        </tr>
    </OwnListCard>
</template>
