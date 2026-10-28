<?php

namespace Modules\FireTab\Http\Requests;

use App\Http\Requests\CreateDefectRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\FireTab\Models\FireMapZone;

/** New zone: polygon of 3..100 points {x, y} in map percent, colour from the fixed list. */
class ZoneRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('incident')) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', CreateDefectRequest::utf8()],
            'description' => ['nullable', 'string', 'max:500', CreateDefectRequest::utf8()],
            'color' => ['required', 'string', Rule::in(FireMapZone::COLORS)],
            'points' => ['required', 'list', 'min:3', 'max:100'],
            'points.*' => ['required', 'array:x,y'],
            'points.*.x' => ['required', 'numeric', 'between:0,100'],
            'points.*.y' => ['required', 'numeric', 'between:0,100'],
        ];
    }

    /** @return list<array{x: float, y: float}> */
    public function points(): array
    {
        return array_map(fn (array $p) => ['x' => round((float) $p['x'], 2), 'y' => round((float) $p['y'], 2)], array_values($this->validated('points')));
    }
}
