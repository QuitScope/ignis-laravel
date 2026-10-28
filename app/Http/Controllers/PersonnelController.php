<?php

namespace App\Http\Controllers;

use App\Events\PersonnelDeleted;
use App\Events\PersonnelSaved;
use App\Http\Requests\StorePersonnelRequest;
use App\Models\EmsQualification;
use App\Models\FireQualification;
use App\Models\Personnel;
use App\Models\PersonnelTitle;
use App\Models\Rank;
use App\Personnel\PersonnelLogger;
use App\Support\ActorName;
use App\Support\AuditLogger;
use App\Support\Settings;
use App\Support\SqlLike;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PersonnelController extends Controller
{
    private const PER_PAGE = 25;

    private const SORTS = [
        'service_number' => 'personnel.service_number',
        'name' => 'personnel.full_name',
        'rank' => 'ranks.priority',
        'ems' => 'ems_qualifications.priority',
        'fire' => 'fire_qualifications.priority',
        'hired_at' => 'personnel.hired_at',
    ];

    public function index(Request $request): Response|HttpResponse
    {
        $this->authorize('viewList', Personnel::class);
        $actor = $request->user();

        $archive = $request->boolean('archive');
        $text = fn (string $key) => is_string($request->query($key)) ? $request->query($key) : '';
        $search = trim($text('search'));
        $filters = [];
        foreach (['rank', 'ems', 'fire'] as $key) {
            $filters[$key] = is_array($request->query($key)) ? 0 : max(0, $request->integer($key));
        }
        $sort = array_key_exists($text('sort'), self::SORTS) ? $text('sort') : 'hired_at';
        $dir = $text('dir') === 'desc' ? 'desc' : 'asc';

        $query = Personnel::query()
            ->join('ranks', 'ranks.id', '=', 'personnel.rank_id')
            ->join('fire_qualifications', 'fire_qualifications.id', '=', 'personnel.fire_qualification_id')
            ->join('ems_qualifications', 'ems_qualifications.id', '=', 'personnel.ems_qualification_id')
            ->where('ranks.is_archive', $archive)
            ->select('personnel.*')
            ->with(['rank', 'fireQualification', 'emsQualification', 'title'])
            ->when($search !== '', fn (Builder $q) => SqlLike::anyOf($q, ['personnel.full_name', 'personnel.service_number'], $search))
            ->when($filters['rank'], fn (Builder $q, int $id) => $q->where('personnel.rank_id', $id))
            ->when($filters['ems'], fn (Builder $q, int $id) => $q->where('personnel.ems_qualification_id', $id))
            ->when($filters['fire'], fn (Builder $q, int $id) => $q->where('personnel.fire_qualification_id', $id))
            ->orderBy(self::SORTS[$sort], $dir)->orderBy('personnel.id');

        if ($request->query('export') === 'csv') {
            return $this->exportCsv($query->get());
        }

        $canDelete = $actor->can('delete', Personnel::class);

        return Inertia::render('Personnel/Index', [
            'personnel' => $query->paginate(self::PER_PAGE)->withQueryString()->through(fn (Personnel $p) => [
                'id' => $p->id,
                'service_number' => $p->service_number,
                'full_name' => $p->displayName(),
                'rank' => $p->rank->labelFor($p->gender),
                'ems' => $p->emsQualification->is_none ? null : $p->emsQualification->labelFor($p->gender),
                'fire' => $p->fireQualification->is_none ? null : $p->fireQualification->short_name,
                'hired_at' => $p->hired_at->toDateString(),
                'can_delete' => $canDelete,
            ]),
            'filters' => ['search' => $search, 'archive' => $archive, 'sort' => $sort, 'dir' => $dir] + $filters,
            'ranks' => Rank::where('is_archive', $archive)->orderBy('priority')->get(['id', 'name'])->all(),
            'fireQualifications' => FireQualification::orderBy('priority')->get(['id', 'name'])->all(),
            'emsQualifications' => EmsQualification::orderBy('priority')->get(['id', 'name'])->all(),
            'can' => ['create' => $actor->can('create', Personnel::class)],
        ]);
    }

    /**
     * Same columns as the legacy export; `-` stands for "no qualification".
     *
     * @param  Collection<int, Personnel>  $rows
     */
    private function exportCsv(Collection $rows): HttpResponse
    {
        $lines = ['Dienstnummer;Name;Dienstgrad;RD-Qualifikation;FW-Qualifikation;Einstellungsdatum'];
        foreach ($rows as $p) {
            $lines[] = implode(';', [
                self::cell($p->service_number),
                self::cell($p->full_name),
                self::cell($p->rank->labelFor($p->gender)),
                $p->emsQualification->is_none ? '"-"' : self::cell($p->emsQualification->labelFor($p->gender)),
                $p->fireQualification->is_none ? '"-"' : self::cell($p->fireQualification->short_name),
                self::cell($p->hired_at->format('d.m.Y')),
            ]);
        }

        return response("\xEF\xBB\xBF".implode("\n", $lines)."\n", 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="mitarbeiter_export_'.now((string) config('ignis.timezone'))->format('Y-m-d').'.csv"',
        ]);
    }

    /** Quoted cell; a leading = + - @ tab or CR would make a spreadsheet run it as a formula, so it gets a leading quote. */
    private static function cell(string $value): string
    {
        if ($value !== '' && strpbrk($value[0], "=+-@\t\r") !== false) {
            $value = "'".$value;
        }

        return '"'.str_replace('"', '""', $value).'"';
    }

    public function create(): Response
    {
        $this->authorize('create', Personnel::class);

        return Inertia::render('Personnel/Create', [
            'ranks' => Rank::where('is_archive', false)->orderBy('priority')->get(['id', 'name'])->all(),
            'titles' => PersonnelTitle::orderBy('priority')->orderBy('name')->get(['id', 'name'])->all(),
            'requireCharacterId' => (bool) app(Settings::class)->get('personnel.require_character_id', false),
        ]);
    }

    public function store(StorePersonnelRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $author = ActorName::for($request->user());
        $log = new PersonnelLogger;
        $noQualification = fn (string $model) => $model::where('is_none', true)->value('id')
            ?? throw new \RuntimeException('Default data is missing: no "none" row in '.$model);

        try {
            $person = DB::transaction(function () use ($data, $author, $log, $noQualification) {
                $person = Personnel::create($data + [
                    'fire_qualification_id' => $noQualification(FireQualification::class),
                    'ems_qualification_id' => $noQualification(EmsQualification::class),
                ]);
                $log->created($person, $author);

                return $person;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['service_number' => trans('validation.unique', ['attribute' => 'service_number'])]);
        }

        event(new PersonnelSaved($person->id));
        AuditLogger::log($request->user(), 'Mitarbeiter erstellt', 'Name: '.$person->full_name.', Dienstnummer: '.$person->service_number,
            'Mitarbeiter', true, ['personnel_id' => $person->id]);

        return redirect("/personnel/{$person->id}");
    }

    public function destroy(Request $request, Personnel $personnel): RedirectResponse
    {
        $this->authorize('delete', $personnel);

        $blocked = fn () => throw ValidationException::withMessages(['delete' => trans('personnel.error.has_documents')]);
        if ($personnel->documents()->exists() || $personnel->legacyDocuments()->exists()) {
            $blocked();
        }

        $picture = $personnel->profile_picture;
        try {
            DB::transaction(fn () => $personnel->delete());
        } catch (QueryException $e) {
            if (! in_array($e->errorInfo[0] ?? null, ['23000', '23503'], true)) {
                throw $e;
            }
            $blocked();
        }
        Personnel::deletePictureFile($picture);
        event(new PersonnelDeleted($personnel->id));
        AuditLogger::log($request->user(), 'Mitarbeiter gelöscht [ID: '.$personnel->id.']', null, 'Mitarbeiter', true, ['personnel_id' => $personnel->id]);

        return redirect('/personnel');
    }
}
