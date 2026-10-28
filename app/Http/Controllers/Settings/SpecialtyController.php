<?php

namespace App\Http\Controllers\Settings;

use App\Http\Requests\Settings\SpecialtyRequest;
use App\Models\Specialty;
use Illuminate\Http\RedirectResponse;

class SpecialtyController extends LookupController
{
    protected string $model = Specialty::class;

    protected string $page = 'Settings/Personnel/Specialties';

    protected string $label = 'Fachdienst';

    protected string $module = 'Fachdienste';

    protected string $usageColumn = 'specialty_id';

    protected string $usageTable = 'personnel_specialty';

    protected string $order = 'number';

    protected array $columns = ['number', 'name', 'is_disabled'];

    public function store(SpecialtyRequest $request): RedirectResponse
    {
        return $this->save($request, null);
    }

    public function update(SpecialtyRequest $request, int $id): RedirectResponse
    {
        return $this->save($request, Specialty::query()->findOrFail($id));
    }
}
