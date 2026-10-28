<?php

namespace App\Http\Controllers\Settings;

use App\Http\Requests\Settings\FireQualificationRequest;
use App\Models\FireQualification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class FireQualificationController extends LookupController
{
    protected string $model = FireQualification::class;

    protected string $page = 'Settings/Personnel/FireQualifications';

    protected string $label = 'FW-Qualifikation';

    protected string $module = 'FW-Qualifikationen';

    protected string $usageColumn = 'fire_qualification_id';

    protected ?string $flag = 'is_none';

    protected array $columns = ['priority', 'short_name', 'name', 'name_male', 'name_female', 'is_none'];

    protected function guardDelete(Model $row): void
    {
        if ($row->getAttribute('is_none')) {
            throw ValidationException::withMessages(['delete' => trans('lookup.error.none_row')]);
        }
    }

    public function store(FireQualificationRequest $request): RedirectResponse
    {
        return $this->save($request, null);
    }

    public function update(FireQualificationRequest $request, int $id): RedirectResponse
    {
        return $this->save($request, FireQualification::query()->findOrFail($id));
    }
}
