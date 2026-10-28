<?php

namespace Modules\Forms\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Personnel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Forms\Models\FormField;
use Modules\Forms\Models\FormSubmission;
use Modules\Forms\Models\FormType;
use Modules\Forms\Support\AnswerValidator;
use Modules\Forms\Support\AutoFill;
use Modules\Forms\Support\PublicId;

/** The applicant's side (legacy FormsController select/create/store/view, OwnListsController::applications). */
class FormSubmissionController extends Controller
{
    /** GET /forms: the active types; every account sees them, submitting needs a linked record. */
    public function select(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Forms/Select', [
            'types' => FormType::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->orderBy('id')->get(['id', 'name', 'description', 'icon'])
                ->map(fn (FormType $t) => ['id' => $t->id, 'name' => $t->name, 'description' => $t->description, 'icon' => $t->icon])->all(),
            'can' => ['submit' => $user->can('create', FormSubmission::class) && $user->personnel_id !== null, 'manage' => $user->can('viewAny', FormSubmission::class)],
        ]);
    }

    public function create(Request $request, FormType $type): Response
    {
        $personnel = $this->applicant($request, $type);

        return Inertia::render('Forms/Create', [
            'type' => ['id' => $type->id, 'name' => $type->name, 'description' => $type->description, 'icon' => $type->icon],
            'fields' => $type->fields->map(function (FormField $f) use ($personnel) {
                $value = AutoFill::forField($f, $personnel);

                return [
                    'key' => $f->key, 'label' => $f->label, 'type' => $f->type, 'options' => $f->type === 'select' ? ($f->options ?? []) : [],
                    'required' => $f->required, 'placeholder' => $f->placeholder, 'hint' => $f->hint, 'width' => $f->width, 'readonly' => $f->readonly,
                    'value' => $f->type === 'checkbox' ? AnswerValidator::checked($value) : $value,
                ];
            })->all(),
        ]);
    }

    /** POST /forms/new/{type}: answers in `answers`; type and applicant come from the route and the account only. */
    public function store(Request $request, FormType $type): RedirectResponse
    {
        $personnel = $this->applicant($request, $type);
        $input = $request->input('answers');
        $values = AnswerValidator::validate($type, is_array($input) ? $input : []);

        $submission = (new FormSubmission)->forceFill([
            'form_type_id' => $type->id,
            'personnel_id' => $personnel->id,
            'applicant_name' => AutoFill::nameWithNumber($personnel),
            'applicant_rank' => AutoFill::rank($personnel),
            'legacy_discord_id' => null,
            'status' => 'new',
            'submitted_at' => now(),
            'answers' => $type->fields->map(function (FormField $f) use ($values, $personnel) {
                $value = $f->readonly ? AutoFill::forField($f, $personnel) : $values[$f->key];

                return ['key' => $f->key, 'label' => $f->label, 'type' => $f->type, 'width' => $f->width,
                    'value' => $f->type === 'checkbox' && is_string($value) ? AnswerValidator::checked($value) : $value];
            })->values()->all(),
        ]);
        app(PublicId::class)->save($submission);

        return redirect("/forms/{$submission->public_id}")->with('status', 'forms.submitted');
    }

    /** GET /forms/{publicId}: owner, view or edit; anyone else gets the same 404 as for a missing id (no existence oracle). */
    public function show(Request $request, string $publicId): Response
    {
        $submission = FormSubmission::query()->with('type:id,name,icon')->where('public_id', $publicId)->firstOrFail();
        abort_unless($request->user()->can('view', $submission), 404);

        return Inertia::render('Forms/Show', [
            'submission' => $submission->present(),
            'can' => ['manage' => $request->user()->can('viewAny', FormSubmission::class)],
        ]);
    }

    /** GET /me/applications: the account's own submissions (legacy OwnRecords::applications). */
    public function mine(Request $request): Response
    {
        $filters = FormSubmission::filters($request);

        return Inertia::render('Me/Applications', [
            'submissions' => FormSubmission::listing($filters)->where('personnel_id', $request->user()->personnel_id ?? 0)
                ->paginate(FormSubmission::PER_PAGE)->withQueryString()->through(fn (FormSubmission $s) => $s->row()),
            'filters' => $filters,
            'statuses' => FormSubmission::STATUSES,
        ]);
    }

    private function applicant(Request $request, FormType $type): Personnel
    {
        abort_unless($type->is_active, 404);
        $this->authorize('create', FormSubmission::class);
        $personnel = $request->user()->personnel;
        abort_if($personnel === null, 403);

        return $personnel;
    }
}
