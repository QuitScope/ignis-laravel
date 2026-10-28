<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { Button } from '@/components/ui/button';
import { formIcon } from '@/lib/formIcons';

type Type = { id: number; name: string; description: string | null; icon: string | null };

defineProps<{ types: Type[]; can: { submit: boolean; manage: boolean } }>();
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold">{{ trans('forms.select.title') }}</h1>
                <p class="mt-1 text-sm text-text-2">{{ trans('forms.select.intro') }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <Button variant="secondary" as-child>
                    <Link href="/me/applications">{{ trans('forms.select.mine') }}</Link>
                </Button>
                <Button v-if="can.manage" variant="secondary" as-child>
                    <Link href="/forms/admin">{{ trans('forms.admin.nav') }}</Link>
                </Button>
            </div>
        </div>

        <p v-if="!can.submit" class="rounded-md border border-border bg-card p-3 text-sm text-text-2" role="status">{{ trans('forms.select.no_record') }}</p>
        <p v-if="types.length === 0" class="text-sm text-text-2">{{ trans('forms.select.empty') }}</p>

        <ul class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <li v-for="t in types" :key="t.id">
                <component
                    :is="can.submit ? Link : 'div'"
                    :href="can.submit ? `/forms/new/${t.id}` : undefined"
                    class="flex h-full gap-3 rounded-lg border border-border bg-card p-4 shadow-(--shadow)"
                    :class="can.submit ? 'hover:border-accent' : 'opacity-70'"
                >
                    <component :is="formIcon(t.icon)" class="size-6 shrink-0 text-accent" aria-hidden="true" />
                    <span>
                        <span class="block font-medium">{{ t.name }}</span>
                        <span v-if="t.description" class="mt-1 block text-sm text-text-2">{{ t.description }}</span>
                    </span>
                </component>
            </li>
        </ul>
    </div>
</template>
