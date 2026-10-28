<?php

namespace App\Http\Controllers;

use App\Discord\DiscordBot;
use App\Documents\DocumentListing;
use App\Events\PersonnelSaved;
use App\Http\Requests\PersonnelNoteRequest;
use App\Http\Requests\PersonnelSpecialtiesRequest;
use App\Http\Requests\UpdatePersonnelRequest;
use App\Models\Document;
use App\Models\DocumentTemplate;
use App\Models\EmsQualification;
use App\Models\FireQualification;
use App\Models\LegacyDocument;
use App\Models\Personnel;
use App\Models\PersonnelLogEntry;
use App\Models\PersonnelTitle;
use App\Models\Rank;
use App\Models\Specialty;
use App\Models\User;
use App\Personnel\PersonnelLogger;
use App\Support\ActorName;
use App\Support\AuditLogger;
use App\Support\ImageUpload;
use App\Support\Settings;
use App\Support\UploadRejected;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/** One person's file: master data, picture, specialties, notes and history. */
class PersonnelProfileController extends Controller
{
    private const PER_PAGE = 6;

    private const PICTURE_DISK = 'profile-pictures';

    public function show(Request $request, Personnel $personnel): Response
    {
        $this->authorize('view', $personnel);
        $actor = $request->user();

        $personnel->load(['rank', 'fireQualification', 'emsQualification', 'title', 'specialties:id', 'user.roles']);
        $canDeleteComments = $actor->can('deleteComments', $personnel);
        $viewDocuments = $actor->can('document.view');
        $requested = $request->query('tab');
        $tab = $requested === 'history' || ($requested === 'documents' && $viewDocuments) ? $requested : 'notes';
        $manageDocuments = $actor->can('document.manage');
        $showArchived = $request->boolean('archived');

        $entries = fn (string $pageName, bool $notes) => $personnel->logEntries()
            ->when($notes, fn ($q) => $q->whereIn('type', PersonnelLogEntry::NOTE_TYPES), fn ($q) => $q->whereNotIn('type', PersonnelLogEntry::NOTE_TYPES))
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate(self::PER_PAGE, pageName: $pageName)->withQueryString()
            ->through(fn (PersonnelLogEntry $e) => [
                'id' => $e->id,
                'type' => $e->type,
                'content' => $e->content,
                'author' => $e->author,
                'created_at' => $e->created_at?->toIso8601String(),
                'can_delete' => $canDeleteComments && in_array($e->type, PersonnelLogEntry::NOTE_TYPES, true),
            ]);

        $held = $personnel->specialties->pluck('id')->all();
        $account = $personnel->user;
        $gender = $personnel->gender;

        return Inertia::render('Personnel/Show', [
            'person' => [
                'id' => $personnel->id,
                'full_name' => $personnel->full_name,
                'display_name' => $personnel->displayName(),
                'title_id' => $personnel->title_id,
                'gender' => $gender,
                'birth_date' => $personnel->birth_date->toDateString(),
                'hired_at' => $personnel->hired_at->toDateString(),
                'service_number' => $personnel->service_number,
                'discord_id' => $personnel->discord_id,
                'phone' => $personnel->phone,
                'character_id' => $personnel->character_id,
                'extra_qualification' => $personnel->extra_qualification,
                'rank_id' => $personnel->rank_id,
                'rank' => ['label' => $personnel->rank->labelFor($gender), 'is_archive' => $personnel->rank->is_archive],
                'fire_qualification_id' => $personnel->fire_qualification_id,
                'fire' => $personnel->fireQualification->is_none ? null : $personnel->fireQualification->labelFor($gender),
                'ems_qualification_id' => $personnel->ems_qualification_id,
                'ems' => $personnel->emsQualification->is_none ? null : $personnel->emsQualification->labelFor($gender),
                'specialty_ids' => $held,
                'picture_url' => $personnel->pictureUrl(),
            ],
            'account' => $account ? ['id' => $account->id, 'username' => $account->username, 'is_active' => $account->is_active, 'can_update' => $actor->can('update', $account), 'can_unlink' => $actor->can('linkPersonnel', $account)] : null,
            'freeAccounts' => $account === null ? self::linkableAccounts($actor) : [],
            'tab' => $tab,
            'notes' => $entries('notes_page', true),
            'history' => $entries('history_page', false),
            'documents' => $viewDocuments ? $personnel->documents()->with('issuer.personnel')
                ->when(! $manageDocuments, fn ($q) => $q->where('status', Document::ISSUED))
                ->orderByDesc('created_at')->orderByDesc('id')
                ->paginate(self::PER_PAGE, pageName: 'documents_page')->withQueryString()
                ->through(fn (Document $d) => [
                    'id' => $d->id,
                    'public_id' => $d->public_id,
                    'title' => $d->title,
                    'status' => $d->status,
                    'issued_at' => $d->issued_at?->toIso8601String(),
                    'issuer' => $d->issuer ? ActorName::for($d->issuer) : null,
                ]) : null,
            'legacyDocuments' => $viewDocuments ? DocumentListing::forPersonnel($personnel, $showArchived)->withQueryString()
                ->through(fn (LegacyDocument $d) => [
                    'public_id' => $d->public_id,
                    'type' => $d->type,
                    'type_label' => $d->typeLabel(),
                    'issuer' => $d->issuerLabel(),
                    'issued_on' => $d->issued_on?->toDateString(),
                    'is_archived' => $d->is_archived,
                ]) : null,
            'showArchived' => $showArchived,
            'templates' => $manageDocuments ? DocumentTemplate::where('is_active', true)->orderBy('category')->orderBy('name')->get(['id', 'name', 'category'])->all() : [],
            'ranks' => Rank::orderBy('is_archive')->orderBy('priority')->get(['id', 'name'])->all(),
            'titles' => PersonnelTitle::orderBy('priority')->orderBy('name')->get(['id', 'name'])->all(),
            'fireQualifications' => FireQualification::orderBy('priority')->get(['id', 'name'])->all(),
            'emsQualifications' => EmsQualification::orderBy('priority')->get(['id', 'name'])->all(),
            'specialties' => Specialty::where('is_disabled', false)->orWhereIn('id', $held)->orderBy('number')->get(['id', 'number', 'name'])->all(),
            'requireCharacterId' => (bool) app(Settings::class)->get('personnel.require_character_id', false),
            'can' => [
                'update' => $actor->can('update', $personnel),
                'delete_comments' => $canDeleteComments,
                'manage_documents' => $manageDocuments,
                'delete_legacy_documents' => $actor->can('manageDocs', $personnel),
                'link' => $account === null && $actor->hasPermission(['admin', 'users.edit']),
                'invite' => $invite = $account === null && $actor->can('update', $personnel) && $actor->can('createRegistrationCode', User::class),
                'invite_discord' => $invite && DiscordBot::snowflake($personnel->discord_id) && app(DiscordBot::class)->active(),
            ],
        ]);
    }

    public function update(UpdatePersonnelRequest $request, Personnel $personnel, PersonnelLogger $log): RedirectResponse
    {
        $author = ActorName::for($request->user());
        $data = $request->validated();
        $oldRank = $personnel->rank->name;
        $oldFire = $personnel->fireQualification->name;
        $oldEms = $personnel->emsQualification->name;

        try {
            DB::transaction(function () use ($personnel, $data, $log, $author, $oldRank, $oldFire, $oldEms) {
                $personnel->fill($data);
                $dirty = array_keys($personnel->getDirty());
                $personnel->save();

                if (in_array('rank_id', $dirty, true)) {
                    $log->rankChanged($personnel, $oldRank, Rank::findOrFail($personnel->rank_id)->name, $author);
                }
                if (in_array('ems_qualification_id', $dirty, true)) {
                    $log->qualificationChanged($personnel, 'RD', $oldEms, EmsQualification::findOrFail($personnel->ems_qualification_id)->name, $author);
                }
                if (in_array('fire_qualification_id', $dirty, true)) {
                    $log->qualificationChanged($personnel, 'FW', $oldFire, FireQualification::findOrFail($personnel->fire_qualification_id)->name, $author);
                }
                if (array_diff($dirty, ['rank_id', 'ems_qualification_id', 'fire_qualification_id']) !== []) {
                    $log->modified($personnel, $author);
                }
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['service_number' => trans('validation.unique', ['attribute' => 'service_number'])]);
        }

        event(new PersonnelSaved($personnel->id));

        return redirect("/personnel/{$personnel->id}");
    }

    public function updateSpecialties(PersonnelSpecialtiesRequest $request, Personnel $personnel, PersonnelLogger $log): RedirectResponse
    {
        $changes = $personnel->specialties()->sync($request->validated('specialties') ?? []);

        if ($changes['attached'] !== [] || $changes['detached'] !== []) {
            $log->specialtiesModified($personnel, ActorName::for($request->user()));
        }
        event(new PersonnelSaved($personnel->id));

        return redirect("/personnel/{$personnel->id}");
    }

    public function uploadPicture(Request $request, Personnel $personnel, PersonnelLogger $log): RedirectResponse
    {
        $this->authorize('update', $personnel);
        $file = $request->file('picture');
        if (! $file instanceof UploadedFile) {
            throw ValidationException::withMessages(['picture' => trans('upload.missing')]);
        }

        try {
            $name = ImageUpload::store($file, self::PICTURE_DISK, Personnel::PICTURE_MAX_BYTES, Personnel::PICTURE_TYPES);
        } catch (UploadRejected $e) {
            throw ValidationException::withMessages(['picture' => $e->getMessage()]);
        }

        $old = $personnel->profile_picture;
        try {
            DB::transaction(function () use ($personnel, $name, $log, $request) {
                $personnel->update(['profile_picture' => $name]);
                $log->pictureChanged($personnel, false, ActorName::for($request->user()));
            });
        } catch (Throwable $e) {
            Storage::disk(self::PICTURE_DISK)->delete($name);
            throw $e;
        }
        Personnel::deletePictureFile($old);
        event(new PersonnelSaved($personnel->id));

        return redirect("/personnel/{$personnel->id}");
    }

    public function deletePicture(Request $request, Personnel $personnel, PersonnelLogger $log): RedirectResponse
    {
        $this->authorize('update', $personnel);
        $old = $personnel->profile_picture;

        if ($old !== null) {
            DB::transaction(function () use ($personnel, $log, $request) {
                $personnel->update(['profile_picture' => null]);
                $log->pictureChanged($personnel, true, ActorName::for($request->user()));
            });
            Personnel::deletePictureFile($old);
            event(new PersonnelSaved($personnel->id));
        }

        return redirect("/personnel/{$personnel->id}");
    }

    public function addNote(PersonnelNoteRequest $request, Personnel $personnel, PersonnelLogger $log): RedirectResponse
    {
        $log->note($personnel, (int) $request->validated('type'), $request->validated('content'), ActorName::for($request->user()));

        return redirect("/personnel/{$personnel->id}");
    }

    public function deleteNote(Request $request, Personnel $personnel, int $entry, PersonnelLogger $log): RedirectResponse
    {
        $this->authorize('deleteComments', $personnel);
        /** @var PersonnelLogEntry $row */
        $row = $personnel->logEntries()->findOrFail($entry);
        // System entries (rank changes, creation, documents) are the record; they stay.
        abort_unless(in_array($row->type, PersonnelLogEntry::NOTE_TYPES, true), 403);

        $log->delete($row);
        AuditLogger::log($request->user(), 'Profil-Kommentar gelöscht [ID: '.$row->id.']', null, 'Mitarbeiter', true, ['personnel_id' => $personnel->id]);

        return redirect("/personnel/{$personnel->id}");
    }

    /**
     * Active accounts without record the actor may link (UserPolicy::linkPersonnel: rank rules, own account only for a super admin).
     *
     * @return list<array{id: int, username: string}>
     */
    private static function linkableAccounts(User $actor): array
    {
        if (! $actor->hasPermission(['admin', 'users.edit'])) {
            return [];
        }

        // ponytail: the first 200 by name, filtered after; add a search field once instances have more free accounts.
        return User::query()->whereNull('personnel_id')->where('is_active', true)->with('roles')->orderBy('username')->limit(200)->get()
            ->filter(fn (User $u) => $actor->can('linkPersonnel', $u))
            ->map(fn (User $u) => ['id' => $u->id, 'username' => (string) $u->username])
            ->values()->all();
    }
}
