<?php

namespace App\Http\Controllers\Settings;

use App\Http\Requests\Settings\EmsQualificationRequest;
use App\Models\EmsQualification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class EmsQualificationController extends LookupController
{
    protected string $model = EmsQualification::class;

    protected string $page = 'Settings/Personnel/EmsQualifications';

    protected string $label = 'RD-Qualifikation';

    protected string $module = 'RD-Qualifikationen';

    protected string $usageColumn = 'ems_qualification_id';

    protected ?string $flag = 'is_none';

    protected array $columns = ['priority', 'name', 'name_male', 'name_female', 'abbreviation', 'is_none', 'is_trainable'];

    protected function guardDelete(Model $row): void
    {
        if ($row->getAttribute('is_none')) {
            throw ValidationException::withMessages(['delete' => trans('lookup.error.none_row')]);
        }
    }

    public function store(EmsQualificationRequest $request): RedirectResponse
    {
        return $this->save($request, null);
    }

    public function update(EmsQualificationRequest $request, int $id): RedirectResponse
    {
        return $this->save($request, EmsQualification::query()->findOrFail($id));
    }
}
