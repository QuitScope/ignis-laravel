<?php

namespace Modules\Mail\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use Modules\Mail\Support\SendLimits;

/**
 * The fields of a compose post (legacy readComposeFields): every value that should be text must be text (an array is
 * a 422, not a warning), the subject at most 255 valid UTF-8 characters, the body a string (its size is checked before
 * decoding, MailBodyGuard), at most 100 raw recipients counted before any other check. Who may write which draft is
 * the controller's business (mailbox, route binding).
 */
class SaveDraftRequest extends FormRequest
{
    public const HEADER = ['to', 'cc', 'bcc'];

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // First count, then check: 100 000 posted entries cost one count() and never reach the wildcard rules.
        $total = 0;
        foreach (self::HEADER as $key) {
            $total += is_array($value = $this->input($key)) ? count($value) : 0;
        }
        if ($total > SendLimits::RAW_RECIPIENTS_MAX) {
            throw ValidationException::withMessages(['to' => __('mail.send.too_many_raw', ['max' => SendLimits::RAW_RECIPIENTS_MAX])]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $utf8 = fn (string $attribute, mixed $value, Closure $fail) => is_string($value) && ! mb_check_encoding($value, 'UTF-8') ? $fail(__('mail.subject.invalid')) : null;
        $rules = [
            'mailbox_id' => ['nullable', 'integer', 'min:1'],
            'subject' => ['nullable', 'string', $utf8, 'max:'.SendLimits::SUBJECT_MAX],
            'body_json' => ['nullable', 'string'],
            'in_reply_to' => ['nullable', 'integer', 'min:1'],
            'forward_from' => ['nullable', 'integer', 'min:1'],
        ];
        foreach (self::HEADER as $key) {
            $rules[$key] = ['nullable', 'array'];
            $rules["$key.*"] = ['nullable', 'string'];
        }

        return $rules;
    }

    public function hasRecipients(): bool
    {
        return $this->hasAny(self::HEADER);
    }

    /**
     * The posted recipients, trimmed; empty and over-long entries dropped (legacy).
     *
     * @return array{to: list<string>, cc: list<string>, bcc: list<string>}
     */
    public function recipients(): array
    {
        $header = [];
        foreach (self::HEADER as $key) {
            $header[$key] = array_values(array_filter(
                array_map(fn (?string $a) => trim((string) $a), (array) $this->validated($key, [])),
                fn (string $a) => $a !== '' && strlen($a) <= SendLimits::ADDRESS_MAX,
            ));
        }

        return $header;
    }
}
