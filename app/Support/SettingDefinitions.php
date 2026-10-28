<?php

namespace App\Support;

use App\Dashboard\LinkTarget;
use App\Modules\ModuleRegistry;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Registry of the system settings: group, type, default, rules and secret flag per key. Settings stays
 * the storage; Settings::get() falls back to the default registered here. Core keys are registered in
 * AppServiceProvider, modules register theirs in their provider. Labels and hints are the translation keys
 * `settings.<key>.label|hint`.
 *
 * validate() is the only way a form writes settings: keys outside the group, unknown or not editable keys are
 * refused (no mass assignment on `settings`), every value is checked and converted by its type, then by its rules.
 * Error messages never repeat the input, so a mistyped secret does not end up in a session or log.
 */
final class SettingDefinitions
{
    public const TYPES = ['string', 'bool', 'int', 'enum', 'color', 'url', 'list'];

    private const BOOLS = ['1' => true, 'true' => true, 'on' => true, 'yes' => true, '0' => false, 'false' => false, 'off' => false, 'no' => false];

    private const STRING_MAX = 255;

    private const LIST_ITEMS = 100;

    private const LIST_ITEM_MAX = 100;

    /** @var array<string, SettingDefinition> */
    private array $definitions = [];

    /**
     * @param  list<mixed>  $rules
     * @param  list<string>  $options
     */
    public function register(string $key, string $group, string $type, mixed $default, array $rules = [], bool $secret = false, array $options = [], bool $editable = true, bool $deletableOnly = false, bool $alwaysStore = false, ?string $module = null): void
    {
        if (! in_array($type, self::TYPES, true) || ($type === 'enum' && $options === [])) {
            throw new LogicException("Setting {$key}: unknown type {$type} or enum without options");
        }
        if (isset($this->definitions[$key])) {
            Log::warning("Settings: duplicate definition {$key} ignored");

            return;
        }
        $this->definitions[$key] = new SettingDefinition($key, $group, $type, $default, $rules, $secret, $options, $editable, $deletableOnly, $alwaysStore, $module);
    }

    /** @return array<string, SettingDefinition> */
    public function all(): array
    {
        return $this->definitions;
    }

    public function definition(string $key): ?SettingDefinition
    {
        return $this->definitions[$key] ?? null;
    }

    /** @return list<SettingDefinition> in registration order, without the keys of switched-off modules */
    public function group(string $group): array
    {
        return array_values(array_filter($this->active(), fn (SettingDefinition $d) => $d->group === $group));
    }

    /** @return list<string> in the order of their first registration (the tab order); switched-off modules' keys left out */
    public function groups(): array
    {
        return array_values(array_unique(array_map(fn (SettingDefinition $d) => $d->group, $this->active())));
    }

    /** @return array<string, SettingDefinition> the form's keys: all() and definition() keep the rest (defaults, secrets) */
    private function active(): array
    {
        return array_filter($this->definitions, fn (SettingDefinition $d) => ModuleRegistry::active($d->module));
    }

    /**
     * The typed values of $input, one per sent key. A deletable-only key yields null (= delete the row).
     *
     * @param  array<array-key, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws ValidationException with every failing key at once
     */
    public function validate(array $input, string $group): array
    {
        $values = [];
        $errors = [];
        foreach ($input as $key => $raw) {
            $key = (string) $key;
            $def = $this->definitions[$key] ?? null;
            if ($def === null || $def->group !== $group || ! $def->editable || ! ModuleRegistry::active($def->module)) {
                $errors[$key] = [__('settings.unknown')];

                continue;
            }

            $value = $this->coerce($def, $raw);
            if ($value === null) {
                $errors[$key] = [__('settings.invalid')];

                continue;
            }

            if ($def->rules !== [] && $value[0] !== null) {
                $rules = $def->type === 'int' ? ['integer', ...$def->rules] : $def->rules;
                $check = Validator::make(['value' => $value[0]], ['value' => $rules], [], ['value' => __($def->label())]);
                if ($check->fails()) {
                    $errors[$key] = $check->errors()->get('value');

                    continue;
                }
            }
            $values[$key] = $value[0];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $values;
    }

    /** @return array{0: mixed}|null the converted value, null when the input does not fit the type */
    private function coerce(SettingDefinition $def, mixed $raw): ?array
    {
        if ($def->deletableOnly) {
            return $raw === null || (is_string($raw) && trim($raw, ' ') === '') ? [null] : null;
        }

        return match ($def->type) {
            'string' => $raw === null ? [''] : self::text($raw, self::STRING_MAX),
            'bool' => match (true) {
                is_bool($raw) => [$raw],
                $raw === 0, $raw === 1 => [$raw === 1],
                is_string($raw) && isset(self::BOOLS[strtolower(trim($raw, ' '))]) => [self::BOOLS[strtolower(trim($raw, ' '))]],
                default => null,
            },
            'int' => match (true) {
                is_int($raw) => [$raw],
                is_string($raw) && ($int = filter_var($raw, FILTER_VALIDATE_INT)) !== false => [$int],
                default => null,
            },
            'enum' => is_string($raw) && in_array($raw, $def->options, true) ? [$raw] : null,
            'color' => is_string($raw) && preg_match('/^#[0-9a-fA-F]{6}$/D', $raw) === 1 ? [strtolower($raw)] : null,
            'url' => self::url($raw),
            'list' => self::list($raw),
        };
    }

    /** @return array{0: string}|null one line, trimmed of spaces; control characters or invalid UTF-8 refused */
    private static function text(mixed $raw, int $max): ?array
    {
        if (! is_string($raw)) {
            return null;
        }
        $text = trim($raw, ' ');

        return mb_strlen($text) <= $max && preg_match('/\p{Cc}/u', $text) === 0 ? [$text] : null;
    }

    /** @return array{0: string}|null empty = unset; else https:// with a real host, no credentials (LinkTarget) */
    private static function url(mixed $raw): ?array
    {
        if ($raw === null) {
            return [''];
        }
        if (! is_string($raw)) {
            return null;
        }
        $url = trim($raw, ' ');

        return $url === '' || (str_starts_with($url, 'https://') && LinkTarget::validate($url)) ? [$url] : null;
    }

    /** @return array{0: list<string>}|null blank items dropped */
    private static function list(mixed $raw): ?array
    {
        if (! is_array($raw) || ! array_is_list($raw) || count($raw) > self::LIST_ITEMS) {
            return null;
        }
        $items = [];
        foreach ($raw as $item) {
            if ($item === null) {
                continue;
            }
            $text = self::text($item, self::LIST_ITEM_MAX);
            if ($text === null) {
                return null;
            }
            if ($text[0] !== '') {
                $items[] = $text[0];
            }
        }

        return [$items];
    }
}
