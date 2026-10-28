<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Support\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The five personnel catalogues are the same list with a dialog; subclasses only say which table,
 * which fields and what protects a row. Authorisation: viewAny for the page, manage for changes
 * (the Form Requests check manage first, so 403 comes before 422).
 */
abstract class LookupController extends Controller
{
    /** @var class-string<Model> */
    protected string $model;

    protected string $page;

    protected string $label;

    protected string $module;

    protected string $order = 'priority';

    protected string $usageTable = 'personnel';

    protected string $usageColumn;

    protected ?string $flag = null;

    /** @var list<string> editable columns, in form order */
    protected array $columns;

    public function index(): Response
    {
        $this->authorize('personnel-settings.viewAny');
        $used = DB::table($this->usageTable)->selectRaw($this->usageColumn.' as k, count(*) as c')
            ->whereNotNull($this->usageColumn)->groupBy($this->usageColumn)->pluck('c', 'k');

        return Inertia::render($this->page, [
            'rows' => $this->model::query()->orderBy($this->order)->orderBy('id')->get()
                ->map(fn (Model $m) => ['id' => $m->getKey(), 'used' => (int) ($used[$m->getKey()] ?? 0)]
                    + collect($this->columns)->mapWithKeys(fn (string $c) => [$c => $m->getAttribute($c)])->all())
                ->all(),
            'can' => ['manage' => (bool) request()->user()?->can('personnel-settings.manage')],
        ]);
    }

    protected function save(FormRequest $request, ?Model $row): RedirectResponse
    {
        $data = $request->validated();
        $row ??= new ($this->model);
        $creating = ! $row->exists;

        if ($this->flag !== null && ! $creating && $row->getAttribute($this->flag) && ! ($data[$this->flag] ?? false)
            && ! $this->model::query()->where($this->flag, true)->whereKeyNot($row->getKey())->exists()) {
            throw ValidationException::withMessages([$this->flag => trans('lookup.error.last_flag')]);
        }

        foreach ($this->columns as $c) {
            $row->setAttribute($c, $data[$c] ?? (str_starts_with($c, 'is_') ? false : null));
        }
        $row->save();

        AuditLogger::log($request->user(), $this->label.($creating ? ' erstellt' : ' aktualisiert [ID: '.$row->getKey().']'),
            'Name: '.$row->getAttribute($this->nameColumn()), $this->module, true, ['id' => $row->getKey()]);

        return back();
    }

    public function destroy(int $id): RedirectResponse
    {
        $this->authorize('personnel-settings.manage');
        $row = $this->model::query()->findOrFail($id);

        $used = DB::table($this->usageTable)->where($this->usageColumn, $id)->count();
        if ($used > 0) {
            throw ValidationException::withMessages(['delete' => trans('lookup.error.in_use', ['count' => $used])]);
        }
        $this->guardDelete($row);

        try {
            DB::transaction(fn () => $row->delete());
        } catch (QueryException $e) {
            if (! in_array($e->errorInfo[0] ?? null, ['23000', '23503'], true)) {
                throw $e;
            }
            throw ValidationException::withMessages(['delete' => trans('lookup.error.in_use', ['count' => max(1, DB::table($this->usageTable)->where($this->usageColumn, $id)->count())])]);
        }
        AuditLogger::log(request()->user(), $this->label.' gelöscht [ID: '.$id.']', 'Name: '.$row->getAttribute($this->nameColumn()),
            $this->module, true, ['id' => $id]);

        return back();
    }

    protected function guardDelete(Model $row): void {}

    protected function nameColumn(): string
    {
        return 'name';
    }
}
