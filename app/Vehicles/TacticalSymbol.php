<?php

namespace App\Vehicles;

use App\Http\Requests\CreateDefectRequest;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Server allowlist for the tactical symbol (parameters of the taktische-zeichen-core library). The option lists come
 * from the old form and live in resources/js/lib/tacticalSymbolOptions.json, which the front end reads too; a Vitest
 * test renders every value with the installed library, so list and library cannot drift apart unnoticed.
 */
class TacticalSymbol
{
    public const KEYS = ['grundzeichen', 'organisation', 'fachaufgabe', 'einheit', 'symbol', 'typ', 'text'];

    public const MAX_TEXT = 100;

    /** @var ?array<string, list<string>> */
    private static ?array $options = null;

    /**
     * The allowed values of the five select fields.
     *
     * @return array<string, list<string>>
     */
    public static function options(): array
    {
        return self::$options ??= array_map(
            fn (array $list) => array_column($list, 'value'),
            json_decode((string) file_get_contents(resource_path('js/lib/tacticalSymbolOptions.json')), true, flags: JSON_THROW_ON_ERROR),
        );
    }

    /**
     * Validation rules for a symbol array found under `$prefix` (e.g. 'tactical_symbol' or 'symbol').
     *
     * @return array<string, list<mixed>>
     */
    public static function rules(string $prefix): array
    {
        $rules = [];
        foreach (self::KEYS as $key) {
            $rules[self::path($prefix, $key)] = isset(self::options()[$key])
                ? ['nullable', 'string', Rule::in(self::options()[$key])]
                : ['nullable', 'string', 'max:'.self::MAX_TEXT, CreateDefectRequest::utf8()];
        }

        return $rules;
    }

    /**
     * One translated message per field and rule (the app has no validation lang files yet).
     *
     * @return array<string, string>
     */
    public static function messages(string $prefix): array
    {
        $messages = [];
        foreach (self::KEYS as $key) {
            $text = trans(isset(self::options()[$key]) ? 'symbols.error.option' : 'symbols.error.text', ['field' => trans("symbols.field.$key"), 'max' => self::MAX_TEXT]);
            foreach (['string', 'in', 'max'] as $rule) {
                $messages[self::path($prefix, $key).".$rule"] = $text;
            }
        }

        return $messages;
    }

    /**
     * Only the known keys, texts trimmed and NUL-free, empty values left out. Values are not checked yet.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function clean(array $input): array
    {
        $clean = [];
        foreach (self::KEYS as $key) {
            $value = $input[$key] ?? null;
            if (is_string($value)) {
                // The library wraps text in CDATA without escaping, so a CDATA end marker must not get through.
                $value = trim(str_replace(["\0", ']]>'], '', $value));
            }
            if ($value !== null && $value !== '') {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }

    /**
     * The symbol as it is stored: clean() plus every value checked against the allowlist and the length limits.
     *
     * @param  array<string, mixed>  $input
     * @param  string  $prefix  prefix of the error keys, for a symbol nested in a bigger form
     * @return array<string, string>
     *
     * @throws ValidationException
     */
    public static function validate(array $input, string $prefix = ''): array
    {
        if (self::isList($input)) {
            throw ValidationException::withMessages([$prefix === '' ? 'symbol' : $prefix => trans('symbols.error.shape')]);
        }
        $clean = self::clean($input);
        if ($prefix === '') {
            return Validator::make($clean, self::rules(''), self::messages(''))->validate();
        }

        return Validator::make([$prefix => $clean], self::rules($prefix), self::messages($prefix))->validate()[$prefix] ?? [];
    }

    public static function isList(mixed $input): bool
    {
        return is_array($input) && $input !== [] && array_is_list($input);
    }

    public static function shapeRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) {
            if (self::isList($value)) {
                $fail(trans('symbols.error.shape'));
            }
        };
    }

    private static function path(string $prefix, string $key): string
    {
        return $prefix === '' ? $key : "$prefix.$key";
    }
}
