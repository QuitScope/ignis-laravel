<script setup lang="ts">
import { ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { Button } from '@/components/ui/button';
import DefectDialog from './DefectDialog.vue';
import { type Assignable, type DefectRow, defectTone, time } from './defects';

// The vehicle page's defects tab: its newest defects (up to 50), the report button and the full-list link.
const props = defineProps<{
    vehicleId: number;
    defects: DefectRow[];
    users: Assignable[];
    can: { manage: boolean; createDefect: boolean; deleteDefect: boolean };
}>();

const opened = ref<number | null>(null);
</script>

<template>
    <div class="space-y-3">
        <div class="flex flex-wrap gap-2">
            <Button v-if="can.createDefect" as-child size="sm">
                <Link :href="`/vehicles/defects/create?vehicle=${props.vehicleId}`">{{ trans('vehicles.defects.report') }}</Link>
            </Button>
            <Button as-child size="sm" variant="ghost">
                <Link :href="`/vehicles/defects?vehicle=${props.vehicleId}`">{{ trans('vehicles.defects.show_all') }}</Link>
            </Button>
        </div>
        <ul v-if="defects.length" class="divide-y divide-border text-sm">
            <li v-for="d in defects" :key="d.id" class="py-2">
                <button type="button" class="w-full text-left hover:underline" @click="opened = d.id">
                    <span class="flex flex-wrap items-center gap-2">
                        <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="defectTone[d.status]">{{ trans(`vehicles.defect_status.${d.status}`) }}</span>
                        <span v-if="d.is_blocking && d.status !== 'resolved'" class="rounded-full bg-(--danger-soft) px-2 py-0.5 text-xs font-medium text-danger">
                            {{ trans('vehicles.defects.blocking') }}
                        </span>
                        <span class="font-medium">{{ d.title }}</span>
                        <span class="text-xs text-text-2">{{ trans(`vehicles.defect_category.${d.category}`) }}</span>
                    </span>
                    <span class="block text-xs text-text-2">
                        {{ time(d.created_at) }}<template v-if="d.assignee"> · {{ d.assignee }}</template>
                        <template v-if="d.last_event?.details"> · {{ d.last_event.details }}</template>
                    </span>
                </button>
            </li>
        </ul>
        <p v-else class="text-sm text-text-2">{{ trans('vehicles.defects.none') }}</p>

        <DefectDialog :defect-id="opened" :users="users" :can="{ manage: can.manage, delete: can.deleteDefect }" @close="opened = null" />
    </div>
</template>
