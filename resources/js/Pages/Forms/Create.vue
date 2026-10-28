<script setup lang="ts">
import { computed } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formIcon } from '@/lib/formIcons';

type Field = {
    key: string;
    label: string;
    type: string;
    options: string[];
    required: boolean;
    placeholder: string | null;
    hint: string | null;
    width: 'full' | 'half';
    readonly: boolean;
    value: string | boolean;
};

const props = defineProps<{ type: { id: number; name: string; description: string | null; icon: string | null }; fields: Field[] }>();

const form = useForm({ answers: Object.fromEntries(props.fields.filter((f) => !f.readonly).map((f) => [f.key, f.value])) as Record<string, string | boolean> });
const errors = computed(() => form.errors as Record<string, string | undefined>);

const text = (key: string) => String(form.answers[key] ?? '');
const set = (key: string, e: Event) => (form.answers[key] = (e.target as HTMLInputElement).value);
const submit = () => form.post(`/forms/new/${props.type.id}`);
const field = 'w-full rounded-md border border-border bg-card px-3 py-2 text-sm';
const inputType = (t: string) => (['email', 'number', 'date', 'time', 'tel'].includes(t) ? t : 'text');
</script>

<template>
    <div class="max-w-3xl space-y-4">
        <div class="flex items-start gap-3">
            <component :is="formIcon(type.icon)" class="mt-1 size-6 shrink-0 text-accent" aria-hidden="true" />
            <div>
                <h1 class="text-2xl font-semibold">{{ type.name }}</h1>
                <p v-if="type.description" class="mt-1 text-sm text-text-2">{{ type.description }}</p>
            </div>
        </div>

        <form class="grid gap-4 rounded-lg border border-border bg-card p-5 shadow-(--shadow) sm:grid-cols-2" novalidate @submit.prevent="submit">
            <div v-for="f in fields" :key="f.key" class="space-y-1" :class="f.width === 'half' ? '' : 'sm:col-span-2'">
                <template v-if="f.type === 'checkbox'">
                    <label class="flex items-center gap-2 text-sm">
                        <input
                            :id="`f-${f.key}`"
                            type="checkbox"
                            :checked="f.readonly ? f.value === true : form.answers[f.key] === true"
                            :disabled="f.readonly"
                            @change="form.answers[f.key] = ($event.target as HTMLInputElement).checked"
                        />
                        {{ f.label }}<span v-if="f.required" aria-hidden="true"> *</span>
                    </label>
                </template>
                <template v-else>
                    <Label :for="`f-${f.key}`">{{ f.label }}<span v-if="f.required && !f.readonly" aria-hidden="true"> *</span></Label>
                    <Input v-if="f.readonly" :id="`f-${f.key}`" :model-value="String(f.value)" readonly disabled />
                    <textarea
                        v-else-if="f.type === 'textarea'"
                        :id="`f-${f.key}`"
                        :value="text(f.key)"
                        rows="5"
                        maxlength="10000"
                        :required="f.required"
                        :placeholder="f.placeholder ?? undefined"
                        :class="field"
                        @input="set(f.key, $event)"
                    />
                    <select v-else-if="f.type === 'select'" :id="`f-${f.key}`" :value="text(f.key)" :required="f.required" :class="field" @change="set(f.key, $event)">
                        <option value="">–</option>
                        <option v-for="o in f.options" :key="o" :value="o">{{ o }}</option>
                    </select>
                    <Input
                        v-else
                        :id="`f-${f.key}`"
                        :model-value="text(f.key)"
                        :type="inputType(f.type)"
                        @update:model-value="(v) => (form.answers[f.key] = String(v))"
                        :required="f.required"
                        :placeholder="f.placeholder ?? undefined"
                    />
                </template>
                <p v-if="f.readonly" class="text-xs text-text-2">{{ trans('forms.create.readonly') }}</p>
                <p v-else-if="f.hint" class="text-xs text-text-2">{{ f.hint }}</p>
                <p v-if="errors[f.key]" class="text-xs text-danger" role="alert">{{ errors[f.key] }}</p>
            </div>

            <div class="flex justify-end gap-2 sm:col-span-2">
                <Button variant="ghost" as-child>
                    <Link href="/forms">{{ trans('forms.create.back') }}</Link>
                </Button>
                <Button type="submit" :disabled="form.processing">{{ trans('forms.create.submit') }}</Button>
            </div>
        </form>
    </div>
</template>
