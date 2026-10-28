<?php

namespace Modules\FireTab\Http\Requests;

use App\Http\Requests\CreateDefectRequest;
use App\Vehicles\TacticalSymbol;
use Illuminate\Foundation\Http\FormRequest;
use Modules\FireTab\Models\FireIncident;
use Modules\FireTab\Models\FireMapMarker;

/** New map marker. Symbol fields against the taktische-zeichen-core allowlist (TacticalSymbol); texts stay plain text. */
class MarkerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('incident')) === true;
    }

    protected function prepareForValidation(): void
    {
        // Trim, strip NUL and the CDATA end marker the library cannot escape.
        $this->merge(TacticalSymbol::clean($this->only(TacticalSymbol::KEYS)));
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim(str_replace(["\0", ']]>'], '', $this->input('name')))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'marker_type' => ['required', 'string', 'max:50'],
            ...self::position(),
            'description' => ['nullable', 'string', 'max:500', CreateDefectRequest::utf8()],
            'name' => ['nullable', 'string', 'max:'.TacticalSymbol::MAX_TEXT, CreateDefectRequest::utf8()],
            ...TacticalSymbol::rules(''),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return TacticalSymbol::messages('');
    }

    /** @return array<string, list<string>> */
    public static function position(): array
    {
        return ['pos_x' => ['required', 'numeric', 'between:0,100'], 'pos_y' => ['required', 'numeric', 'between:0,100']];
    }

    public function markerType(): string
    {
        $type = (string) $this->validated('marker_type');
        if (in_array($type, FireMapMarker::TYPES, true)) {
            return $type;
        }
        /** @var FireIncident $incident */
        $incident = $this->route('incident');
        if (preg_match('/^vehicle_(\d{1,10})$/', $type, $m) === 1 && $incident->vehicles()->where('vehicle_id', (int) $m[1])->exists()) {
            return $type;
        }

        return 'custom';
    }
}
