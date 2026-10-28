<?php

namespace App\Http\Requests;

use App\Documents\DocumentValidator;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Save of a draft (button and autosave). `content` is the document as a JSON string, so its size is
 * checked in bytes before anything decodes it; the controller decodes with a bounded depth.
 */
class SaveDocumentRequest extends FormRequest
{
    public const MAX_BODY = 2 * DocumentValidator::MAX_BYTES + 64 * 1024;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('document.manage');
    }

    protected function prepareForValidation(): void
    {
        abort_if(strlen((string) $this->getContent()) > self::MAX_BODY, 413);
    }

    /** @return list<mixed> `content` as a JSON string, at most 1 MB in bytes; shared with the template request. */
    public static function contentRules(): array
    {
        return ['required', 'string', function (string $attribute, mixed $value, Closure $fail) {
            if (is_string($value) && strlen($value) > DocumentValidator::MAX_BYTES) {
                $fail(trans('documents.error.too_large'));
            }
        }];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'content' => self::contentRules(),
            'title' => ['nullable', 'string', 'max:200'],
            'lock_version' => ['required', 'integer', 'min:0'],
            'autosave' => ['sometimes', 'boolean'],
        ];
    }
}
