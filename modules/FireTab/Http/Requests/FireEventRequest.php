<?php

namespace Modules\FireTab\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\FireTab\Enums\AsuEventType;

/** One ASU event. Trupp and member are checked under the Trupp lock in AsuRecorder; no order is enforced. */
class FireEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('incident')) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'trupp_id' => ['required', 'integer', 'min:1'],
            'type' => ['required', Rule::enum(AsuEventType::class)],
            'member_id' => ['nullable', 'integer', 'min:1'],
            'bar' => ['nullable', 'integer', 'between:0,400'],
            'note' => ['nullable', 'string', 'max:255', $this->maydayNote(...)],
            'client_uuid' => ['required', 'string', 'uuid'],
            'occurred_at' => ['nullable', 'integer', 'min:0', 'max:4102444800000'],
        ];
    }

    private function maydayNote(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->input('type') !== AsuEventType::Mayday->value || ! is_string($value)) {
            return;
        }
        $note = json_decode($value, true);
        $ok = is_array($note) && ! array_is_list($note)
            && array_diff(array_keys($note), ['member', 'standort', 'lage']) === []
            && array_filter($note, fn ($v) => $v !== null && ! is_string($v)) === [];
        if (! $ok) {
            $fail(__('firetab.asu.mayday_note'));
        }
    }
}
