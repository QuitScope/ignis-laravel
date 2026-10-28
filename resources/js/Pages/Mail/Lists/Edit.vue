<script setup lang="ts">
import { computed, ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type RuleKey = 'role' | 'rank' | 'ems_qualification' | 'fire_qualification' | 'specialty';
type Option = { id: number; name: string; badge?: string | null };
type MailboxOption = { id: number; kind: 'personal' | 'group'; address: string; name: string; deliverable: boolean };
type ListData = {
    id: number;
    name: string;
    local: string;
    domain: string;
    kind: 'static' | 'dynamic';
    senders: 'all' | 'managers';
    members: number[];
    rule: Record<RuleKey, number[]>;
};

/** Create (list null) or edit a distribution list: static members or a dynamic rule (any criterion matches). */
const props = defineProps<{ list: ListData | null; defaultDomain: string; domains: string[]; mailboxes: MailboxOption[]; criteria: Record<RuleKey, Option[]> }>();

const ruleKeys: RuleKey[] = ['role', 'rank', 'ems_qualification', 'fire_qualification', 'specialty'];
const emptyRule = (): Record<RuleKey, number[]> => ({ role: [], rank: [], ems_qualification: [], fire_qualification: [], specialty: [] });

const form = useForm({
    name: props.list?.name ?? '',
    local: props.list?.local ?? '',
    domain: props.list?.domain ?? props.defaultDomain,
    kind: props.list?.kind ?? 'static',
    senders: props.list?.senders ?? 'all',
    members: [...(props.list?.members ?? [])],
    rule: { ...emptyRule(), ...(props.list?.rule ?? {}) },
});

const filter = ref('');
const shown = computed(() => {
    const q = filter.value.trim().toLowerCase();

    return q === '' ? props.mailboxes : props.mailboxes.filter((m) => m.name.toLowerCase().includes(q) || m.address.includes(q));
});

function submit() {
    if (props.list) form.put(`/mail/lists/${props.list.id}`);
    else form.post('/mail/lists');
}

function destroy() {
    if (props.list && confirm(trans('mail.lists.delete_confirm', { name: props.list.name }))) router.delete(`/mail/lists/${props.list.id}`);
}

const error = (key: string) => (form.errors as Record<string, string | undefined>)[key];
const label = 'block text-sm font-medium';
const select = 'h-9 rounded-md border border-border bg-card px-3 text-sm';
</script>

<template>
    <form class="mx-auto w-full max-w-3xl space-y-5" @submit.prevent="submit">
        <h1 class="text-2xl font-semibold">{{ trans(list ? 'mail.lists.edit_title' : 'mail.lists.new') }}</h1>

        <div>
            <label :class="label" for="list-name">{{ trans('mail.lists.name') }}</label>
            <Input id="list-name" v-model="form.name" required maxlength="150" class="mt-1" />
            <p v-if="error('name')" class="mt-1 text-sm text-danger" role="alert">{{ error('name') }}</p>
        </div>

        <div>
            <label :class="label" for="list-local">{{ trans('mail.lists.address') }}</label>
            <div class="mt-1 flex items-center gap-2">
                <Input id="list-local" v-model="form.local" required maxlength="64" autocomplete="off" />
                <span aria-hidden="true">@</span>
                <select v-model="form.domain" :class="select" :aria-label="trans('mail.admin.domain')">
                    <option v-for="d in domains" :key="d" :value="d">{{ d }}</option>
                </select>
            </div>
            <p v-if="error('local')" class="mt-1 text-sm text-danger" role="alert">{{ error('local') }}</p>
            <p v-if="error('domain')" class="mt-1 text-sm text-danger" role="alert">{{ error('domain') }}</p>
        </div>

        <fieldset>
            <legend :class="label">{{ trans('mail.lists.kind') }}</legend>
            <div class="mt-1 flex gap-4 text-sm">
                <label v-for="k in ['static', 'dynamic']" :key="k" class="flex items-center gap-2">
                    <input v-model="form.kind" type="radio" name="kind" :value="k" />
                    {{ trans(`mail.lists.kind_${k}`) }}
                </label>
            </div>
            <p v-if="error('kind')" class="mt-1 text-sm text-danger" role="alert">{{ error('kind') }}</p>
        </fieldset>

        <fieldset>
            <legend :class="label">{{ trans('mail.lists.senders') }}</legend>
            <div class="mt-1 flex gap-4 text-sm">
                <label v-for="s in ['all', 'managers']" :key="s" class="flex items-center gap-2">
                    <input v-model="form.senders" type="radio" name="senders" :value="s" />
                    {{ trans(`mail.lists.senders_${s}`) }}
                </label>
            </div>
            <p v-if="error('senders')" class="mt-1 text-sm text-danger" role="alert">{{ error('senders') }}</p>
        </fieldset>

        <fieldset v-if="form.kind === 'static'" class="space-y-2">
            <legend :class="label">{{ trans('mail.lists.members') }}</legend>
            <p class="text-xs text-text-2">{{ trans('mail.lists.members_hint') }}</p>
            <Input v-model="filter" :placeholder="trans('mail.lists.filter')" :aria-label="trans('mail.lists.filter')" autocomplete="off" />
            <ul class="max-h-80 space-y-1 overflow-y-auto rounded-md border border-border bg-card p-2 text-sm">
                <li v-for="m in shown" :key="m.id">
                    <label class="flex items-center gap-2">
                        <input v-model="form.members" type="checkbox" :value="m.id" :disabled="!m.deliverable && !form.members.includes(m.id)" />
                        <span>{{ m.name }} <span class="text-text-2">· {{ m.address }}</span></span>
                        <span v-if="!m.deliverable" class="text-xs text-text-2">({{ trans('mail.lists.not_deliverable') }})</span>
                    </label>
                </li>
                <li v-if="shown.length === 0" class="text-text-2">{{ trans('mail.lists.no_mailboxes') }}</li>
            </ul>
            <p v-if="error('members')" class="text-sm text-danger" role="alert">{{ error('members') }}</p>
        </fieldset>

        <div v-else class="space-y-3">
            <p class="text-sm text-text-2">{{ trans('mail.lists.rule_hint') }}</p>
            <fieldset v-for="key in ruleKeys" :key="key" class="rounded-md border border-border p-3">
                <legend class="px-1 text-sm font-medium">{{ trans(`mail.lists.rule.${key}`) }}</legend>
                <div class="flex flex-wrap gap-x-4 gap-y-1 text-sm">
                    <label v-for="o in criteria[key]" :key="o.id" class="flex items-center gap-2">
                        <input v-model="form.rule[key]" type="checkbox" :value="o.id" />
                        <img v-if="o.badge" :src="o.badge" alt="" class="h-4 w-auto" />
                        {{ o.name }}
                    </label>
                    <span v-if="criteria[key].length === 0" class="text-text-2">{{ trans('mail.lists.no_options') }}</span>
                </div>
            </fieldset>
            <p v-if="error('rule')" class="text-sm text-danger" role="alert">{{ error('rule') }}</p>
        </div>

        <div class="flex flex-wrap gap-2">
            <Button type="submit" :disabled="form.processing">{{ trans('mail.lists.save') }}</Button>
            <Button as-child variant="ghost"><Link href="/mail/lists">{{ trans('mail.lists.cancel') }}</Link></Button>
            <Button v-if="list" type="button" variant="ghost" class="ml-auto text-danger" @click="destroy">{{ trans('mail.lists.delete') }}</Button>
        </div>
    </form>
</template>
