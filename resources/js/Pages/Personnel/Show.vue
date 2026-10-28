<script setup lang="ts">
import { ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { localDate } from '@/lib/utils';
import Avatar from '@/components/personnel/Avatar.vue';
import LogList, { type LogEntry } from '@/components/personnel/LogList.vue';
import Pagination from '@/components/Pagination.vue';
import RankBadge from '@/components/personnel/RankBadge.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Option = { id: number; name: string };
type Page<T> = { data: T[]; from: number | null; to: number | null; total: number; prev_page_url: string | null; next_page_url: string | null };
type Paged = Page<LogEntry>;
type LegacyRow = { public_id: string; type: number; type_label: string; issuer: string; issued_on: string | null; is_archived: boolean };
type DocumentRow = { id: number; public_id: string; title: string; status: 'draft' | 'issued'; issued_at: string | null; issuer: string | null };

const props = defineProps<{
    person: {
        id: number;
        full_name: string;
        display_name: string;
        title_id: number | null;
        gender: number;
        birth_date: string;
        hired_at: string;
        service_number: string;
        discord_id: string | null;
        phone: string | null;
        character_id: string | null;
        extra_qualification: string | null;
        rank_id: number;
        rank: { label: string; is_archive: boolean };
        fire_qualification_id: number;
        fire: string | null;
        ems_qualification_id: number;
        ems: string | null;
        specialty_ids: number[];
        picture_url: string | null;
    };
    account: { id: number; username: string; is_active: boolean; can_update: boolean; can_unlink: boolean } | null;
    freeAccounts: { id: number; username: string }[];
    tab: 'notes' | 'history' | 'documents';
    notes: Paged;
    history: Paged;
    documents: Page<DocumentRow> | null;
    legacyDocuments: Page<LegacyRow> | null;
    showArchived: boolean;
    templates: { id: number; name: string; category: string | null }[];
    ranks: Option[];
    titles: Option[];
    fireQualifications: Option[];
    emsQualifications: Option[];
    specialties: { id: number; number: number; name: string }[];
    requireCharacterId: boolean;
    can: { update: boolean; delete_comments: boolean; manage_documents: boolean; delete_legacy_documents: boolean; link: boolean; invite: boolean; invite_discord: boolean };
}>();

const base = `/personnel/${props.person.id}`;
const editing = ref(false);

const form = useForm({
    full_name: props.person.full_name,
    title_id: props.person.title_id ?? ('' as number | ''),
    birth_date: props.person.birth_date,
    gender: props.person.gender,
    discord_id: props.person.discord_id ?? '',
    phone: props.person.phone ?? '',
    service_number: props.person.service_number,
    hired_at: props.person.hired_at,
    rank_id: props.person.rank_id,
    fire_qualification_id: props.person.fire_qualification_id,
    ems_qualification_id: props.person.ems_qualification_id,
    character_id: props.person.character_id ?? '',
    extra_qualification: props.person.extra_qualification ?? '',
});
const save = () => form.put(base, { preserveScroll: true, onSuccess: () => (editing.value = false) });

const specialtyForm = useForm({ specialties: [...props.person.specialty_ids] });
const saveSpecialties = () => specialtyForm.put(`${base}/specialties`, { preserveScroll: true });

const pictureForm = useForm({ picture: null as File | null });
const uploadPicture = () => pictureForm.post(`${base}/picture`, { preserveScroll: true, forceFormData: true, onSuccess: () => pictureForm.reset() });
const removePicture = () => {
    if (confirm(trans('personnel.picture.remove_confirm'))) {
        router.delete(`${base}/picture`, { preserveScroll: true });
    }
};

const documentForm = useForm({ template_id: null as number | null });
const createDocument = () => documentForm.post(`${base}/documents`);

const archiveError = ref(false);
const archiveLegacy = async (docid: string, archived: boolean) => {
    archiveError.value = false;
    const token = decodeURIComponent(document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/)?.[1] ?? '');
    try {
        const res = await fetch('/api/documents/archive', {
            method: 'POST',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': token },
            body: JSON.stringify({ docid, archived }),
        });
        if (!res.ok) {
            archiveError.value = true;

            return;
        }
    } catch {
        archiveError.value = true;

        return;
    }
    router.reload({ only: ['legacyDocuments'] });
};
const deleteLegacy = (docid: string) => {
    if (confirm(trans('documents.legacy_delete_confirm', { id: docid }))) {
        router.post('/personnel/document-delete', { docid, pid: String(props.person.id) }, { preserveScroll: true });
    }
};

const noteForm = useForm({ type: 0, content: '' });
const addNote = () => noteForm.post(`${base}/notes`, { preserveScroll: true, onSuccess: () => noteForm.reset('content') });

const linkAccount = ref<number | null>(null);
const linkForm = useForm({ personnel_id: props.person.id });
const link = () => linkAccount.value !== null && linkForm.post(`/users/${linkAccount.value}/personnel`, { preserveScroll: true });
const cancelEdit = () => {
    form.reset();
    form.clearErrors();
    editing.value = false;
};
const unlinkForm = useForm({ unlink: 1, personnel_id: null as number | null });
const unlink = () => {
    if (props.account && confirm(trans('account_link.unlink_confirm'))) {
        unlinkForm.post(`/users/${props.account.id}/personnel`, { preserveScroll: true });
    }
};

const inviteResult = ref('');
const inviting = ref(false);
async function invite(via?: 'discord') {
    inviting.value = true;
    inviteResult.value = '';
    try {
        const token = decodeURIComponent(document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/)?.[1] ?? '');
        const res = await fetch(`${base}/invite`, {
            method: 'POST',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': token },
            body: JSON.stringify(via ? { via } : {}),
        });
        const body = await res.json();
        if (!res.ok) {
            inviteResult.value = body.message ?? trans('account_link.failed');
            return;
        }
        if (body.discord) {
            inviteResult.value = body.discord.sent ? trans('account_link.discord_sent') : trans('account_link.discord_failed', { message: body.discord.message ?? '', url: body.url });
            return;
        }
        try {
            await navigator.clipboard.writeText(body.url);
            inviteResult.value = trans(body.existing ? 'account_link.invite_existing' : 'account_link.invite_created');
        } catch {
            inviteResult.value = trans('account_link.invite_copy_failed', { url: body.url });
        }
    } catch {
        inviteResult.value = trans('account_link.failed');
    } finally {
        inviting.value = false;
    }
}

const select = 'h-9 w-full rounded-md border border-border bg-card px-3 text-sm';
const card = 'rounded-lg border border-border bg-card p-4 shadow-(--shadow)';
const rows = () => [
    ['personnel.field.service_number', props.person.service_number],
    ['personnel.field.birth_date', props.person.birth_date],
    ['personnel.field.hired_at', props.person.hired_at],
    ['personnel.field.gender', trans(`personnel.gender.${props.person.gender}`)],
    ['personnel.field.discord_id', props.person.discord_id],
    ['personnel.field.phone', props.person.phone],
    ['personnel.field.character_id', props.person.character_id],
    ['personnel.field.ems', props.person.ems ?? trans('personnel.profile.none')],
    ['personnel.field.fire', props.person.fire ?? trans('personnel.profile.none')],
    ['personnel.profile.extra_qualification', props.person.extra_qualification],
];
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div class="flex items-center gap-3">
                <h1 class="text-2xl font-semibold">{{ person.display_name }}</h1>
                <RankBadge :label="person.rank.label" :archive="person.rank.is_archive" />
            </div>
            <div class="flex gap-2">
                <Button as-child variant="ghost">
                    <Link href="/personnel">{{ trans('personnel.back') }}</Link>
                </Button>
                <Button v-if="can.update && !editing" @click="editing = true">{{ trans('personnel.profile.edit') }}</Button>
            </div>
        </div>

        <div class="grid gap-4 lg:grid-cols-3">
            <section :class="[card, 'lg:col-span-2']" aria-labelledby="master-data">
                <h2 id="master-data" class="mb-3 text-lg font-medium">{{ trans('personnel.profile.master_data') }}</h2>

                <dl v-if="!editing" class="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                    <div v-for="[label, value] in rows()" :key="String(label)">
                        <dt class="text-text-2">{{ trans(label as string) }}</dt>
                        <dd>{{ value || '–' }}</dd>
                    </div>
                </dl>

                <form v-else class="grid gap-4 sm:grid-cols-2" @submit.prevent="save">
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
                            <option v-for="g in [0, 1, 2]" :key="g" :value="g">{{ trans(`personnel.gender.${g}`) }}</option>
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
                    </div>
                    <div class="space-y-1">
                        <Label for="rank_id">{{ trans('personnel.field.rank') }}</Label>
                        <select id="rank_id" v-model.number="form.rank_id" :class="select" required>
                            <option v-for="r in ranks" :key="r.id" :value="r.id">{{ r.name }}</option>
                        </select>
                        <p v-if="form.errors.rank_id" class="text-sm text-danger" role="alert">{{ form.errors.rank_id }}</p>
                    </div>
                    <div class="space-y-1">
                        <Label for="ems_qualification_id">{{ trans('personnel.field.ems') }}</Label>
                        <select id="ems_qualification_id" v-model.number="form.ems_qualification_id" :class="select" required>
                            <option v-for="q in emsQualifications" :key="q.id" :value="q.id">{{ q.name }}</option>
                        </select>
                        <p v-if="form.errors.ems_qualification_id" class="text-sm text-danger" role="alert">{{ form.errors.ems_qualification_id }}</p>
                    </div>
                    <div class="space-y-1">
                        <Label for="fire_qualification_id">{{ trans('personnel.field.fire') }}</Label>
                        <select id="fire_qualification_id" v-model.number="form.fire_qualification_id" :class="select" required>
                            <option v-for="q in fireQualifications" :key="q.id" :value="q.id">{{ q.name }}</option>
                        </select>
                        <p v-if="form.errors.fire_qualification_id" class="text-sm text-danger" role="alert">{{ form.errors.fire_qualification_id }}</p>
                    </div>
                    <div class="space-y-1">
                        <Label for="extra_qualification">{{ trans('personnel.profile.extra_qualification') }}</Label>
                        <Input id="extra_qualification" v-model="form.extra_qualification" maxlength="255" />
                        <p v-if="form.errors.extra_qualification" class="text-sm text-danger" role="alert">{{ form.errors.extra_qualification }}</p>
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
                    <div class="flex justify-end gap-2 sm:col-span-2">
                        <Button type="button" variant="ghost" @click="cancelEdit">{{ trans('personnel.profile.cancel') }}</Button>
                        <Button type="submit" :disabled="form.processing">{{ trans('personnel.profile.save') }}</Button>
                    </div>
                </form>
            </section>

            <div class="space-y-4">
                <section :class="[card, 'flex flex-col items-center gap-3']" aria-labelledby="picture">
                    <h2 id="picture" class="self-start text-lg font-medium">{{ trans('personnel.picture.title') }}</h2>
                    <Avatar :src="person.picture_url" :name="person.full_name" />
                    <form v-if="can.update" class="flex w-full flex-col gap-2" @submit.prevent="uploadPicture">
                        <Label for="picture_file">{{ trans('personnel.picture.upload') }}</Label>
                        <input
                            id="picture_file"
                            type="file"
                            accept="image/png,image/jpeg,image/webp"
                            aria-describedby="picture_hint"
                            class="text-sm"
                            @input="pictureForm.picture = ($event.target as HTMLInputElement).files?.[0] ?? null"
                        />
                        <p id="picture_hint" class="text-xs text-text-2">{{ trans('personnel.picture.hint') }}</p>
                        <p v-if="pictureForm.errors.picture" class="text-sm text-danger" role="alert">{{ pictureForm.errors.picture }}</p>
                        <div class="flex gap-2">
                            <Button type="submit" size="sm" :disabled="!pictureForm.picture || pictureForm.processing">{{ trans('personnel.picture.upload') }}</Button>
                            <Button v-if="person.picture_url" type="button" size="sm" variant="ghost" @click="removePicture">{{ trans('personnel.picture.remove') }}</Button>
                        </div>
                    </form>
                </section>

                <section :class="card" aria-labelledby="account">
                    <h2 id="account" class="mb-2 text-lg font-medium">{{ trans('personnel.profile.account') }}</h2>
                    <div v-if="account" class="flex flex-wrap items-center gap-2 text-sm">
                        <Link v-if="account.can_update" :href="`/users/${account.id}/edit`" class="font-medium underline">{{ account.username }}</Link>
                        <span v-else>{{ account.username }}</span>
                        <span v-if="!account.is_active" class="text-text-2">({{ trans('personnel.profile.inactive') }})</span>
                        <Button v-if="account.can_unlink" variant="ghost" size="sm" :disabled="unlinkForm.processing" @click="unlink">{{ trans('account_link.unlink') }}</Button>
                        <p v-if="unlinkForm.errors.personnel_id" class="w-full text-sm text-danger" role="alert">{{ unlinkForm.errors.personnel_id }}</p>
                    </div>
                    <div v-else class="space-y-2">
                        <p class="text-sm text-text-2">{{ trans('personnel.profile.no_account') }}</p>
                        <form v-if="can.link && freeAccounts.length" class="flex flex-wrap items-end gap-2" @submit.prevent="link">
                            <div class="min-w-0 flex-1 space-y-1">
                                <Label for="link_account">{{ trans('account_link.pick_account') }}</Label>
                                <select id="link_account" v-model="linkAccount" :class="select" required>
                                    <option :value="null" disabled>{{ trans('account_link.pick_account_placeholder') }}</option>
                                    <option v-for="u in freeAccounts" :key="u.id" :value="u.id">{{ u.username }}</option>
                                </select>
                            </div>
                            <Button type="submit" size="sm" :disabled="linkForm.processing || linkAccount === null">{{ trans('account_link.link') }}</Button>
                        </form>
                        <p v-if="linkForm.errors.personnel_id" class="text-sm text-danger" role="alert">{{ linkForm.errors.personnel_id }}</p>
                        <div v-if="can.invite" class="flex flex-wrap items-center gap-2">
                            <Button variant="secondary" size="sm" :disabled="inviting" @click="invite()">{{ trans('account_link.invite') }}</Button>
                            <Button v-if="can.invite_discord" variant="secondary" size="sm" :disabled="inviting" @click="invite('discord')">{{ trans('account_link.invite_discord') }}</Button>
                            <span class="text-xs break-all" role="status">{{ inviteResult }}</span>
                        </div>
                    </div>
                </section>

                <section :class="card" aria-labelledby="specialties">
                    <h2 id="specialties" class="mb-2 text-lg font-medium">{{ trans('personnel.profile.specialties') }}</h2>
                    <form v-if="can.update" class="space-y-2" @submit.prevent="saveSpecialties">
                        <p v-if="!specialties.length" class="text-sm text-text-2">{{ trans('personnel.profile.no_specialties') }}</p>
                        <label v-for="s in specialties" :key="s.id" class="flex items-center gap-2 text-sm">
                            <input v-model="specialtyForm.specialties" type="checkbox" :value="s.id" class="size-4" />
                            <span>{{ s.number }} · {{ s.name }}</span>
                        </label>
                        <p v-if="specialtyForm.errors.specialties" class="text-sm text-danger" role="alert">{{ specialtyForm.errors.specialties }}</p>
                        <Button type="submit" size="sm" :disabled="specialtyForm.processing">{{ trans('personnel.profile.save') }}</Button>
                    </form>
                    <ul v-else class="space-y-1 text-sm">
                        <li v-if="!person.specialty_ids.length" class="text-text-2">{{ trans('personnel.profile.no_specialties') }}</li>
                        <li v-for="s in specialties.filter((x) => person.specialty_ids.includes(x.id))" :key="s.id">{{ s.number }} · {{ s.name }}</li>
                    </ul>
                </section>
            </div>
        </div>

        <section :class="card" :aria-label="trans('personnel.profile.tab_notes')">
            <div class="mb-3 flex gap-2" role="tablist">
                <Button as-child :variant="tab === 'notes' ? 'default' : 'ghost'" size="sm">
                    <Link :href="`${base}?tab=notes`" preserve-scroll role="tab" :aria-selected="tab === 'notes'">{{ trans('personnel.profile.tab_notes') }}</Link>
                </Button>
                <Button as-child :variant="tab === 'history' ? 'default' : 'ghost'" size="sm">
                    <Link :href="`${base}?tab=history`" preserve-scroll role="tab" :aria-selected="tab === 'history'">{{ trans('personnel.profile.tab_history') }}</Link>
                </Button>
                <Button v-if="documents" as-child :variant="tab === 'documents' ? 'default' : 'ghost'" size="sm">
                    <Link :href="`${base}?tab=documents`" preserve-scroll role="tab" :aria-selected="tab === 'documents'">{{ trans('personnel.profile.tab_documents') }}</Link>
                </Button>
            </div>

            <template v-if="tab === 'notes'">
                <form v-if="can.update" class="mb-4 grid gap-2 sm:grid-cols-[10rem_1fr_auto] sm:items-start" @submit.prevent="addNote">
                    <div class="space-y-1">
                        <Label for="note_type">{{ trans('personnel.profile.note_type') }}</Label>
                        <select id="note_type" v-model.number="noteForm.type" :class="select">
                            <option v-for="t in [0, 1, 2]" :key="t" :value="t">{{ trans(`personnel.note_type.${t}`) }}</option>
                        </select>
                    </div>
                    <div class="space-y-1">
                        <Label for="note_content">{{ trans('personnel.profile.note_content') }}</Label>
                        <textarea id="note_content" v-model="noteForm.content" rows="2" maxlength="5000" required class="w-full rounded-md border border-border bg-card px-3 py-2 text-sm" />
                        <p v-if="noteForm.errors.content" class="text-sm text-danger" role="alert">{{ noteForm.errors.content }}</p>
                        <p v-if="noteForm.errors.type" class="text-sm text-danger" role="alert">{{ noteForm.errors.type }}</p>
                    </div>
                    <Button type="submit" class="sm:mt-6" :disabled="noteForm.processing">{{ trans('personnel.profile.note_add') }}</Button>
                </form>
                <LogList :entries="notes" :empty="trans('personnel.profile.no_notes')" :delete-url="`${base}/notes`" />
            </template>
            <template v-else-if="tab === 'documents' && documents">
                <form v-if="can.manage_documents" class="mb-4 flex flex-wrap items-end gap-2" @submit.prevent="createDocument">
                    <div class="min-w-0 flex-1 space-y-1">
                        <Label for="document_template">{{ trans('documents.create') }}</Label>
                        <select id="document_template" v-model="documentForm.template_id" :class="select" required :disabled="!templates.length">
                            <option :value="null" disabled>{{ templates.length ? trans('documents.pick_template') : trans('documents.no_templates') }}</option>
                            <option v-for="t in templates" :key="t.id" :value="t.id">{{ t.category ? `${t.category} · ${t.name}` : t.name }}</option>
                        </select>
                        <p v-if="documentForm.errors.template_id" class="text-sm text-danger" role="alert">{{ documentForm.errors.template_id }}</p>
                    </div>
                    <Button type="submit" :disabled="documentForm.processing || documentForm.template_id === null">{{ trans('documents.create') }}</Button>
                </form>
                <p v-if="!documents.data.length" class="text-sm text-text-2">{{ trans('documents.empty') }}</p>
                <table v-else class="w-full text-sm">
                    <thead class="text-left text-text-2">
                        <tr>
                            <th class="py-1 font-medium">{{ trans('documents.col.title') }}</th>
                            <th class="py-1 font-medium">{{ trans('documents.col.public_id') }}</th>
                            <th class="py-1 font-medium">{{ trans('documents.col.status') }}</th>
                            <th class="py-1 font-medium">{{ trans('documents.col.issued') }}</th>
                            <th class="py-1"><span class="sr-only">{{ trans('documents.open') }}</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="d in documents.data" :key="d.id" class="border-t border-border">
                            <td class="py-1">{{ d.title }}</td>
                            <td class="py-1 tabular-nums">{{ d.public_id }}</td>
                            <td class="py-1">{{ trans(`documents.status.${d.status}`) }}</td>
                            <td class="py-1">{{ d.issued_at ? new Date(d.issued_at).toLocaleDateString() : '–' }}<template v-if="d.issuer"> · {{ d.issuer }}</template></td>
                            <td class="py-1 text-right">
                                <Link :href="d.status === 'draft' ? `/documents/${d.id}/edit` : `/documents/${d.id}`" class="underline">
                                    {{ trans(d.status === 'draft' ? 'documents.edit' : 'documents.open') }}
                                </Link>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <Pagination
                    v-if="documents.total > documents.data.length"
                    class="mt-3"
                    :from="documents.from"
                    :to="documents.to"
                    :total="documents.total"
                    :prev-url="documents.prev_page_url"
                    :next-url="documents.next_page_url"
                />
                <section v-if="legacyDocuments" class="mt-6" aria-labelledby="legacy-documents">
                    <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                        <h3 id="legacy-documents" class="font-medium">{{ trans('documents.legacy_title') }}</h3>
                        <Link :href="`${base}?tab=documents${showArchived ? '' : '&archived=1'}`" preserve-scroll class="text-sm underline">
                            {{ trans(showArchived ? 'documents.show_current' : 'documents.show_archived') }}
                        </Link>
                    </div>
                    <p v-if="archiveError" role="alert" class="mb-2 text-sm text-danger">{{ trans('documents.archive_failed') }}</p>
                    <p v-if="!legacyDocuments.data.length" class="text-sm text-text-2">{{ trans('documents.empty') }}</p>
                    <table v-else class="w-full text-sm">
                        <thead class="text-left text-text-2">
                            <tr>
                                <th class="py-1 font-medium">{{ trans('documents.col.type') }}</th>
                                <th class="py-1 font-medium">{{ trans('documents.col.public_id') }}</th>
                                <th class="py-1 font-medium">{{ trans('documents.col.issuer') }}</th>
                                <th class="py-1 font-medium">{{ trans('documents.col.issued') }}</th>
                                <th class="py-1"><span class="sr-only">{{ trans('documents.open') }}</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="d in legacyDocuments.data" :key="d.public_id" class="border-t border-border">
                                <td class="py-1">{{ d.type_label }}</td>
                                <td class="py-1 tabular-nums">{{ d.public_id }}</td>
                                <td class="py-1">{{ d.issuer }}</td>
                                <td class="py-1">{{ d.issued_on ? localDate(d.issued_on) : '–' }}</td>
                                <td class="space-x-3 py-1 text-right">
                                    <Link :href="`/personnel/document-view?docid=${encodeURIComponent(d.public_id)}`" class="underline">{{ trans('documents.view') }}</Link>
                                    <button v-if="can.manage_documents" type="button" class="underline" @click="archiveLegacy(d.public_id, !d.is_archived)">
                                        {{ trans(d.is_archived ? 'documents.restore' : 'documents.archive') }}
                                    </button>
                                    <button v-if="can.delete_legacy_documents" type="button" class="text-danger underline" @click="deleteLegacy(d.public_id)">
                                        {{ trans('documents.delete') }}
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <Pagination
                        v-if="legacyDocuments.total > legacyDocuments.data.length"
                        class="mt-3"
                        :from="legacyDocuments.from"
                        :to="legacyDocuments.to"
                        :total="legacyDocuments.total"
                        :prev-url="legacyDocuments.prev_page_url"
                        :next-url="legacyDocuments.next_page_url"
                    />
                </section>
            </template>
            <LogList v-else :entries="history" :empty="trans('personnel.profile.no_history')" :delete-url="`${base}/notes`" />
        </section>
    </div>
</template>
