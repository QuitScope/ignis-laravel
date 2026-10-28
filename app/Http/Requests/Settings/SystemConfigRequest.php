<?php

namespace App\Http\Requests\Settings;

use App\Support\SettingDefinitions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

/**
 * One group of the system configuration; admin only (403 before 422). The rules live in the registry, whose
 * validate() refuses unknown keys, other groups' keys and not editable keys, so there are no per-field rules here
 * (setting keys contain dots, which Laravel's rule syntax reads as nesting).
 */
class SystemConfigRequest extends FormRequest
{
    private const MAX_KEYS = 100;

    /** @var array<string, mixed> */
    private array $typed = [];

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('system.admin');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }

    /**
     * 404 for an unknown group (after the 403), then the registry's validation. Whatever fails, secrets (of every
     * group, also those registered later by a module) are stripped from the request before the handler flashes it
     * as old input; a body with more keys than any group has is refused without flashing it at all.
     */
    protected function passedValidation(): void
    {
        $defs = app(SettingDefinitions::class);
        $group = (string) $this->route('group');
        abort_unless(in_array($group, $defs->groups(), true), 404);

        $input = $this->except(['_token', '_method']);
        try {
            if (count($input) > self::MAX_KEYS) {
                throw ValidationException::withMessages(['settings' => [__('settings.too_many')]]);
            }
            $this->typed = $defs->validate($input, $group);
        } catch (ValidationException $e) {
            $kept = count($input) > self::MAX_KEYS ? [] : Arr::except($this->all(), array_keys(array_filter($defs->all(), fn ($d) => $d->secret)));
            $this->replace($kept);
            app('request')->replace($kept);

            throw $e;
        }
    }

    /**
     * The typed values of the sent keys; blank secrets are still in here (the controller keeps the old value).
     *
     * @return array<string, mixed>
     */
    public function values(): array
    {
        return $this->typed;
    }
}
