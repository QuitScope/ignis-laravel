<?php

namespace App\Http\Controllers\Settings;

use App\Http\Requests\Settings\RankRequest;
use App\Models\Rank;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class RankController extends LookupController
{
    protected string $model = Rank::class;

    protected string $page = 'Settings/Personnel/Ranks';

    protected string $label = 'Dienstgrad';

    protected string $module = 'Dienstgrade';

    protected string $usageColumn = 'rank_id';

    protected ?string $flag = 'is_archive';

    protected array $columns = ['priority', 'name', 'name_male', 'name_female', 'badge', 'is_archive'];

    protected function guardDelete(Model $row): void
    {
        if ($row->getAttribute('is_archive') && ! Rank::where('is_archive', true)->whereKeyNot($row->getKey())->exists()) {
            throw ValidationException::withMessages(['delete' => trans('lookup.error.last_archive')]);
        }
    }

    public function store(RankRequest $request): RedirectResponse
    {
        return $this->save($request, null);
    }

    public function update(RankRequest $request, int $id): RedirectResponse
    {
        return $this->save($request, Rank::query()->findOrFail($id));
    }
}
