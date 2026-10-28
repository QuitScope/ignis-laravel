<?php

namespace App\Http\Controllers\Settings;

use App\Http\Requests\Settings\PersonnelTitleRequest;
use App\Models\PersonnelTitle;
use Illuminate\Http\RedirectResponse;

class PersonnelTitleController extends LookupController
{
    protected string $model = PersonnelTitle::class;

    protected string $page = 'Settings/Personnel/Titles';

    protected string $label = 'Titel';

    protected string $module = 'Titel';

    protected string $usageColumn = 'title_id';

    protected array $columns = ['name', 'priority'];

    public function store(PersonnelTitleRequest $request): RedirectResponse
    {
        return $this->save($request, null);
    }

    public function update(PersonnelTitleRequest $request, int $id): RedirectResponse
    {
        return $this->save($request, PersonnelTitle::query()->findOrFail($id));
    }
}
