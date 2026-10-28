<script setup lang="ts">
import { ref, watch } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Option = { id: number; name: string };

const props = defineProps<{ ranks: Option[]; titles: Option[]; requireCharacterId: boolean }>();

const form = useForm({
    full_name: '',
    title_id: '' as number | '',
    birth_date: '',
    gender: 0,
    discord_id: '',
    phone: '',
    service_number: '',
    hired_at: new Date().toISOString().slice(0, 10),
    rank_id: (props.ranks[0]?.id ?? '') as number | '',
    character_id: '',
});
const save = () => form.post('/personnel');

const hint = ref<{ ok: boolean; text: string } | null>(null);
let timer: ReturnType<typeof setTimeout> | undefined;
watch(
    () => form.service_number,
    (value) => {
        clearTimeout(timer);
        hint.value = null;
        const number = value.trim();
        if (!number) return;
        timer = setTimeout(async () => {
            try {
                const token = decodeURIComponent(document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/)?.[1] ?? '');
                const res = await fetch('/api/personnel/check-dienstnr', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': token },
                    body: JSON.stringify({ dienstnr: number }),
                });
                const body = await res.json();
                if (form.service_number.trim() !== number) return;
                hint.value = body.error
                    ? { ok: false, text: trans('personnel.service_number_invalid') }
                    : { ok: body.available, text: trans(body.available ? 'personnel.service_number_free' : 'personnel.service_number_taken') };
            } catch {
                hint.value = null;
            }
        }, 400);
    },
);

const select = 'h-9 w-full rounded-md border border-border bg-card px-3 text-sm';
</script>

<template>
    <div class="max-w-2xl space-y-4">
        <h1 class="text-2xl font-semibold">{{ trans('personnel.create_title') }}</h1>

        <form class="grid gap-4 rounded-lg border border-border bg-card p-4 shadow-(--shadow) sm:grid-cols-2" @submit.prevent="save">
            <div class="space-y-1 sm:col-span-2">
                <Label for="full_name">{{ trans('personnel.field.full_name') }}</Label>
                <Input id="full_name" v-model="form.full_name" maxlength="255" required />
                <p v-if="form.errors.full_name" class="text-sm text-danger" role="alert">{{ form.errors.full_name }}</p>
            </div>
            <div class="space-y-1">
                <Label for="title_id">{{ trans('personnel.field.title') }}</Label>
                <select id="title_id" v-model="form.title_id" :class="select">
                    <option value="">{{ trans('personnel.no_title') }}</option>
                    <option v-for="t in titles" :key="t.id" :value="t.id">{{ t.name }}</option>
                </select>
                <p v-if="form.errors.title_id" class="text-sm text-danger" role="alert">{{ form.errors.title_id }}</p>
            </div>
            <div class="space-y-1">
                <Label for="gender">{{ trans('personnel.field.gender') }}</Label>
                <select id="gender" v-model.number="form.gender" :class="select" required>
                    <option :value="0">{{ trans('personnel.gender.0') }}</option>
                    <option :value="1">{{ trans('personnel.gender.1') }}</option>
                    <option :value="2">{{ trans('personnel.gender.2') }}</option>
                </select>
                <p v-if="form.errors.gender" class="text-sm text-danger" role="alert">{{ form.errors.gender }}</p>
            </div>
            <div class="space-y-1">
                <Label for="birth_date">{{ trans('personnel.field.birth_date') }}</Label>
                <Input id="birth_date" v-model="form.birth_date" type="date" required />
                <p v-if="form.errors.birth_date" class="text-sm text-danger" role="alert">{{ form.errors.birth_date }}</p>
            </div>
            <div class="space-y-1">
                <Label for="hired_at">{{ trans('personnel.field.hired_at') }}</Label>
                <Input id="hired_at" v-model="form.hired_at" type="date" required />
                <p v-if="form.errors.hired_at" class="text-sm text-danger" role="alert">{{ form.errors.hired_at }}</p>
            </div>
            <div class="space-y-1">
                <Label for="service_number">{{ trans('personnel.field.service_number') }}</Label>
                <Input id="service_number" v-model="form.service_number" maxlength="255" required />
                <p v-if="form.errors.service_number" class="text-sm text-danger" role="alert">{{ form.errors.service_number }}</p>
                <p v-else-if="hint" class="text-sm" :class="hint.ok ? 'text-ok' : 'text-danger'" aria-live="polite">{{ hint.text }}</p>
            </div>
            <div class="space-y-1">
                <Label for="rank_id">{{ trans('personnel.field.rank') }}</Label>
                <select id="rank_id" v-model.number="form.rank_id" :class="select" required>
                    <option v-for="r in ranks" :key="r.id" :value="r.id">{{ r.name }}</option>
                </select>
                <p v-if="form.errors.rank_id" class="text-sm text-danger" role="alert">{{ form.errors.rank_id }}</p>
            </div>
            <div class="space-y-1">
                <Label for="discord_id">{{ trans('personnel.field.discord_id') }}</Label>
                <Input id="discord_id" v-model="form.discord_id" inputmode="numeric" maxlength="20" />
                <p v-if="form.errors.discord_id" class="text-sm text-danger" role="alert">{{ form.errors.discord_id }}</p>
            </div>
            <div class="space-y-1">
                <Label for="phone">{{ trans('personnel.field.phone') }}</Label>
                <Input id="phone" v-model="form.phone" maxlength="255" />
                <p v-if="form.errors.phone" class="text-sm text-danger" role="alert">{{ form.errors.phone }}</p>
            </div>
            <div class="space-y-1">
                <Label for="character_id">{{ trans('personnel.field.character_id') }}</Label>
                <Input id="character_id" v-model="form.character_id" maxlength="255" :required="requireCharacterId" />
                <p v-if="form.errors.character_id" class="text-sm text-danger" role="alert">{{ form.errors.character_id }}</p>
            </div>
            <div class="flex items-center justify-between gap-2 sm:col-span-2">
                <Button as-child variant="ghost">
                    <Link href="/personnel">{{ trans('personnel.back') }}</Link>
                </Button>
                <Button type="submit" :disabled="form.processing">{{ trans('personnel.save') }}</Button>
            </div>
        </form>
    </div>
</template>
