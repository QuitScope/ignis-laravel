<?php

namespace Modules\Forms\Support;

use Illuminate\Validation\ValidationException;
use Modules\Forms\Models\FormField;
use Modules\Forms\Models\FormType;

/**
 * The answers of one application against the fields of its type (legacy AntragFieldValidator). Only declared fields
 * come back; readonly ones never (the server fills them, AutoFill). Values are trimmed strings, checkboxes booleans.
 * Changes against legacy: a required checkbox must be ticked; dates must exist; an absence type needs
 * von_datum <= bis_datum (legacy 1.3-5).
 */
final class AnswerValidator
{
    private const MAX_LENGTH = ['textarea' => 10000, 'email' => 255, 'tel' => 50, 'number' => 50];

    private const TEXT_MAX = 1000;

    /**
     * @param  array<mixed>  $input  field key => value
     * @return array<string, string|bool> field key => clean value, readonly fields left out
     *
     * @throws ValidationException 422, one message per field
     */
    public static function validate(FormType $type, array $input): array
    {
        $errors = [];
        $values = [];
        foreach ($type->fields as $field) {
            if ($field->readonly) {
                continue;
            }
            $key = $field->key;
            $label = $field->label !== '' ? $field->label : $key;
            $raw = $input[$key] ?? null;
            $value = match (true) {
                is_bool($raw) => $raw ? '1' : '0',
                is_scalar($raw) => trim((string) $raw),
                default => '',
            };

            if ($field->type === 'checkbox') {
                $checked = self::checked($value);
                if ($value !== '' && ! $checked && ! in_array(strtolower($value), ['0', 'off', 'false'], true)) {
                    $errors[$key] = __('forms.validation.invalid', ['label' => $label]);
                } elseif ($field->required && ! $checked) {
                    $errors[$key] = __('forms.validation.required', ['label' => $label]);
                } else {
                    $values[$key] = $checked;
                }

                continue;
            }

            if ($value === '' && $field->required) {
                $errors[$key] = __('forms.validation.required', ['label' => $label]);
            } elseif ($value === '') {
                $values[$key] = '';
            } elseif (self::valid($field, $value)) {
                $values[$key] = $value;
            } else {
                $errors[$key] = __('forms.validation.invalid', ['label' => $label]);
            }
        }

        $from = $values['von_datum'] ?? '';
        $to = $values['bis_datum'] ?? '';
        if ($type->is_absence && is_string($from) && is_string($to) && $from !== '' && $to !== '' && $from > $to) {
            $errors['bis_datum'] = __('forms.validation.absence_order');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $values;
    }

    public static function checked(string $value): bool
    {
        return in_array(strtolower(trim($value)), ['1', 'on', 'true'], true);
    }

    private static function valid(FormField $field, string $value): bool
    {
        if (mb_strlen($value) > (self::MAX_LENGTH[$field->type] ?? self::TEXT_MAX)) {
            return false;
        }

        return match ($field->type) {
            'email' => filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            'date' => preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]),
            'time' => preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/D', $value) === 1,
            'number' => is_numeric($value),
            'tel' => preg_match('#^[\d\s+\-()/]+$#D', $value) === 1,
            'select' => $field->options === null || $field->options === [] || in_array($value, $field->options, true),
            default => true,
        };
    }
}
