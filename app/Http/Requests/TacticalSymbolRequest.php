<?php

namespace App\Http\Requests;

use App\Models\TacticalSymbolTemplate;
use App\Vehicles\TacticalSymbol;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Tactical symbol template (legacy tz-templates `save`): a name up to 100 characters, unique regardless of case, and
 * a symbol with at least a Grundzeichen. The ability is checked here so 403 comes before 422.
 */
class TacticalSymbolRequest extends FormRequest
{
    public const MAX_NAME = 100;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('vehicle.manage');
    }

    protected function prepareForValidation(): void
    {
        $merge = CreateDefectRequest::cleanTexts($this, ['name']);
        if (is_array($this->input('symbol')) && ! TacticalSymbol::isList($this->input('symbol'))) {
            $merge['symbol'] = TacticalSymbol::clean($this->input('symbol'));
        }
        $this->merge($merge);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:'.self::MAX_NAME, CreateDefectRequest::utf8(), $this->uniqueName()],
            'symbol' => ['required', 'array', TacticalSymbol::shapeRule()],
            ...TacticalSymbol::rules('symbol'),
            'symbol.grundzeichen' => ['required', 'string', ...TacticalSymbol::rules('symbol')['symbol.grundzeichen']],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        $messages = TacticalSymbol::messages('symbol') + ['symbol.grundzeichen.required' => trans('symbols.error.grundzeichen')];
        foreach (['required', 'array'] as $rule) {
            $messages["symbol.$rule"] = trans('symbols.error.shape');
        }
        foreach (['required', 'string', 'max'] as $rule) {
            $messages["name.$rule"] = trans('symbols.error.name', ['max' => self::MAX_NAME]);
        }

        return $messages;
    }

    private function uniqueName(): Closure
    {
        $own = $this->route('template');
        $ownId = $own instanceof TacticalSymbolTemplate ? $own->id : null;

        return function (string $attribute, mixed $value, Closure $fail) use ($ownId) {
            if (! is_string($value)) {
                return;
            }
            $lower = mb_strtolower($value);
            $taken = TacticalSymbolTemplate::query()->when($ownId !== null, fn ($q) => $q->where('id', '!=', $ownId))
                ->pluck('name')->contains(fn (string $name) => mb_strtolower($name) === $lower);
            if ($taken) {
                $fail(trans('symbols.error.name_taken'));
            }
        };
    }

    /** @return array{name: string, symbol: array<string, string>} */
    public function templateData(): array
    {
        return ['name' => $this->validated('name'), 'symbol' => TacticalSymbol::validate($this->validated('symbol'))];
    }
}
