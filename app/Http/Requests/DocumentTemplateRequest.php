<?php

namespace App\Http\Requests;

use App\Documents\DocumentValidator;
use App\Documents\InvalidDocument;
use App\Http\Controllers\DocumentController;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Create/update of a template. `content` is the template as a JSON string (like a document save): size is
 * checked in bytes before decoding, then the DocumentValidator in template mode decides; its reasons become
 * the 422 message of `content`. {@see document()} returns the cleaned template.
 */
class DocumentTemplateRequest extends FormRequest
{
    /** @var array<string, mixed> */
    private array $clean = [];

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('document.manage');
    }

    protected function prepareForValidation(): void
    {
        abort_if(strlen((string) $this->getContent()) > SaveDocumentRequest::MAX_BODY, 413);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'category' => ['nullable', 'string', 'max:100'],
            'content' => ['bail', ...SaveDocumentRequest::contentRules(), function (string $attribute, mixed $value, Closure $fail) {
                $doc = is_string($value) ? json_decode($value, true, DocumentController::JSON_DEPTH) : null;
                if (! is_array($doc)) {
                    $fail(trans('documents.error.invalid'));

                    return;
                }
                try {
                    $this->clean = DocumentValidator::validate($doc, true);
                } catch (InvalidDocument $e) {
                    $fail(implode("\n", $e->reasons));
                }
            }],
        ];
    }

    /** @return array<string, mixed> the validated template document */
    public function document(): array
    {
        return $this->clean;
    }
}
