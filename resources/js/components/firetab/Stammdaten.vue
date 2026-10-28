<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { ref } from 'vue';
import SearchPick from '@/components/firetab/SearchPick.vue';
import { Button } from '@/components/ui/button';

export type Master = {
    number: string | null;
    location: string | null;
    keyword: string | null;
    started_date: string | null;
    started_time: string | null;
    // A number, `fed:{link}:{remote}` (federation hit) or `external` (keep the stored federation snapshot).
    leader_personnel_id: number | string | null;
    leader_name: string | null;
    caller_name: string | null;
    caller_contact: string | null;
    owner_name: string | null;
    owner_contact: string | null;
    notes?: string | null;
};

const props = defineProps<{ initial: Master; url: string; method: 'post' | 'put'; canEdit: boolean; submit: string }>();

const form = useForm({
    number: props.initial.number ?? '',
    location: props.initial.location ?? '',
    keyword: props.initial.keyword ?? '',
    started_date: props.initial.started_date ?? '',
    started_time: props.initial.started_time ?? '',
    leader_personnel_id: (props.initial.leader_personnel_id ?? 0) as number | string,
    caller_name: props.initial.caller_name ?? '',
    caller_contact: props.initial.caller_contact ?? '',
    owner_name: props.initial.owner_name ?? '',
    owner_contact: props.initial.owner_contact ?? '',
    notes: props.initial.notes ?? '',
});
const leaderName = ref(props.initial.leader_name);

const send = () => (props.method === 'post' ? form.post(props.url) : form.put(props.url, { preserveScroll: true }));
const input =
    'block min-h-11 w-full rounded-md border border-border bg-bg-2 px-3 py-2 text-text disabled:opacity-60 focus-visible:outline-2 focus-visible:outline-accent';
const text = [
    ['number', 'field.number'],
    ['location', 'field.location'],
    ['keyword', 'field.keyword'],
    ['caller_name', 'field.caller_name'],
    ['caller_contact', 'field.caller_contact'],
    ['owner_name', 'field.owner_name'],
    ['owner_contact', 'field.owner_contact'],
] as const;
</script>

<template>
    <form class="space-y-4" @submit.prevent="send">
        <div class="grid gap-4 md:grid-cols-2">
            <div v-for="[name, label] in text" :key="name">
                <label :for="`f-${name}`" class="mb-1 block text-sm text-text-2">{{ trans(`firetab.${label}`) }}</label>
                <input :id="`f-${name}`" v-model="form[name]" type="text" maxlength="255" :disabled="!canEdit" :class="input" />
                <p v-if="form.errors[name]" role="alert" class="mt-1 text-sm text-danger">{{ form.errors[name] }}</p>
            </div>
            <div>
                <label for="f-date" class="mb-1 block text-sm text-text-2">{{ trans('firetab.field.started_date') }}</label>
                <input id="f-date" v-model="form.started_date" type="date" :disabled="!canEdit" :class="input" />
                <p v-if="form.errors.started_date" role="alert" class="mt-1 text-sm text-danger">{{ form.errors.started_date }}</p>
            </div>
            <div>
                <label for="f-time" class="mb-1 block text-sm text-text-2">{{ trans('firetab.field.started_time') }}</label>
                <input id="f-time" v-model="form.started_time" type="time" :disabled="!canEdit" :class="input" />
                <p v-if="form.errors.started_time" role="alert" class="mt-1 text-sm text-danger">{{ form.errors.started_time }}</p>
            </div>
        </div>

        <div class="space-y-2">
            <p class="text-sm text-text-2">{{ trans('firetab.field.leader') }}: <strong class="text-text">{{ leaderName }}</strong></p>
            <SearchPick
                v-if="canEdit"
                url="/firetab/personnel/search"
                :placeholder="trans('firetab.field.leader_search')"
                @pick="(h) => ((form.leader_personnel_id = h.id as number | string), (leaderName = h.name))"
            />
            <p v-if="form.errors.leader_personnel_id" role="alert" class="text-sm text-danger">{{ form.errors.leader_personnel_id }}</p>
        </div>

        <div v-if="method === 'post'">
            <label for="f-notes" class="mb-1 block text-sm text-text-2">{{ trans('firetab.field.notes') }}</label>
            <textarea id="f-notes" v-model="form.notes" rows="4" maxlength="20000" :class="input" />
        </div>

        <Button v-if="canEdit" type="submit" class="min-h-11" :disabled="form.processing">{{ submit }}</Button>
    </form>
</template>
