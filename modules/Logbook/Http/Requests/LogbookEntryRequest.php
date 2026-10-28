<?php

namespace Modules\Logbook\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Logbook\LogbookService;
use Modules\Logbook\Models\LogbookEntry;

/** Trip form. Only these fields are read: source, created_by and the vehicle texts never come from the body. */
class LogbookEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $entry = $this->route('entry');

        return (bool) ($entry instanceof LogbookEntry ? $this->user()?->can('update', $entry) : $this->user()?->can('create', LogbookEntry::class));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $entry = $this->route('entry');
        $vehicleless = $entry instanceof LogbookEntry && $entry->vehicle_id === null;

        return LogbookService::rules() + [
            'vehicle_id' => [$vehicleless ? 'nullable' : 'required', 'integer', 'exists:vehicles,id'],
            'driver_name' => LogbookService::DRIVER_RULES,
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('driver_name'))) {
            $this->merge(['driver_name' => trim($this->input('driver_name'))]);
        }
    }
}
