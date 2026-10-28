<?php

namespace Modules\ManvBoard\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\ManvBoard\Enums\Triage;

/** Edit a patient. A missing triage means "not touched" (an imported patient may still be untriaged). */
class UpdatePatientRequest extends StorePatientRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['triage' => ['nullable', Rule::enum(Triage::class)]] + parent::rules();
    }
}
