<?php

namespace Modules\Forms\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\InboxNotification;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Forms\Events\FormDecided;
use Modules\Forms\Models\FormSubmission;
use Throwable;

/** The processing side (legacy FormsController adminList/adminView/decide): view or edit reads, edit decides. */
class FormAdminController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', FormSubmission::class);
        $filters = FormSubmission::filters($request);

        return Inertia::render('Forms/Admin/Index', [
            'submissions' => FormSubmission::listing($filters)->paginate(FormSubmission::PER_PAGE)->withQueryString()->through(fn (FormSubmission $s) => $s->row()),
            'filters' => $filters,
            'statuses' => FormSubmission::STATUSES,
        ]);
    }

    /** The admin area is known, so a rightless account gets 403 before any lookup: it never learns whether an id exists. */
    public function show(Request $request, string $publicId): Response
    {
        $this->authorize('viewAny', FormSubmission::class);
        $submission = $this->find($publicId);

        return Inertia::render('Forms/Admin/Show', [
            'submission' => $submission->present(),
            'statuses' => FormSubmission::STATUSES,
            'can' => ['decide' => self::canDecide($request->user(), $submission)],
            'own' => $submission->isOwnedBy($request->user()),
        ]);
    }

    /**
     * PUT /forms/admin/{publicId}: status (any to any, as legacy) and remark. Only a real change is written and audited
     * (row locked, compared and saved in one transaction); after the commit it is announced (FormDecided) and, for a
     * new status or a new remark, sent to the applicant (legacy 1.3-6).
     */
    public function update(Request $request, string $publicId): RedirectResponse
    {
        $this->authorize('viewAny', FormSubmission::class);
        $user = $request->user();
        abort_unless(self::canDecide($user, $this->find($publicId)), 403);
        $data = $request->validate([
            'status' => ['required', 'string', Rule::in(FormSubmission::STATUSES)],
            'decision_note' => ['nullable', 'string', 'max:5000'],
        ]);
        $note = trim((string) ($data['decision_note'] ?? ''));
        $note = $note === '' ? null : $note;

        $changed = DB::transaction(function () use ($publicId, $user, $data, $note) {
            $submission = FormSubmission::query()->with('type:id,name,icon,is_absence')->where('public_id', $publicId)->lockForUpdate()->firstOrFail();
            $oldStatus = $submission->status;
            $statusChanged = $oldStatus !== $data['status'];
            $noteChanged = ($submission->decision_note ?? '') !== ($note ?? '');
            if (! $statusChanged && ! $noteChanged) {
                return false;
            }

            $name = $user->displayName();
            $id = $submission->public_id;
            $context = ['id' => $submission->id, 'public_id' => $id];
            if ($submission->decided_by_name !== $name) {
                AuditLogger::log($user, "Bearbeiter geändert [ID: {$id}]", $name, 'Anträge', true, $context);
            }
            if ($statusChanged) {
                AuditLogger::log($user, "Status geändert [ID: {$id}]", 'Neuer Status: '.__("forms.status.{$data['status']}"), 'Anträge', true, $context);
            }
            if ($noteChanged) {
                AuditLogger::log($user, "Bemerkung geändert [ID: {$id}]", '"'.($note ?? '').'"', 'Anträge', true, $context);
            }
            $submission->forceFill([
                'status' => $data['status'], 'decision_note' => $note,
                'decided_by' => $user->id, 'decided_by_name' => $name, 'decided_at' => now(),
            ])->save();

            DB::afterCommit(function () use ($submission, $oldStatus, $statusChanged, $note, $name, $id) {
                try {
                    FormDecided::dispatch($submission, $oldStatus);
                } catch (Throwable $e) {
                    report($e);
                }
                try {
                    $applicant = $submission->personnel_id === null ? null : User::query()->where('personnel_id', $submission->personnel_id)->first();
                    if ($applicant !== null && ($statusChanged || $note !== null)) {
                        $applicant->notify(new InboxNotification(
                            'antrag',
                            __('forms.notification.title', ['id' => $id]),
                            __('forms.notification.message', ['status' => __("forms.status.{$submission->status}"), 'name' => $name]),
                            "/forms/{$id}",
                        ));
                    }
                } catch (Throwable $e) {
                    report($e);
                }
            });

            return true;
        });

        return back()->with('status', $changed ? 'forms.decided' : 'forms.unchanged');
    }

    /** decide plus "never one's own", also for super admins (Gate::before lets them through the policy). */
    public static function canDecide(User $user, FormSubmission $submission): bool
    {
        return $user->can('decide', $submission) && ! $submission->isOwnedBy($user);
    }

    private function find(string $publicId): FormSubmission
    {
        return FormSubmission::query()->with('type:id,name,icon,is_absence')->where('public_id', $publicId)->firstOrFail();
    }
}
