<?php

namespace App\Support;

/**
 * One registered setting (see SettingDefinitions). `editable: false` keeps it out of the generic form (own UI,
 * e.g. the logo upload or the API key), `deletableOnly` lets the form only clear it (install.admin_discord_id).
 * `alwaysStore` writes a sent value even when it equals the default (privacy opt-ins: a later default change must not switch them on).
 * `module` names the module that registered it: while that module is off, the key leaves the form (its value stays).
 */
final readonly class SettingDefinition
{
    /**
     * @param  'string'|'bool'|'int'|'enum'|'color'|'url'|'list'  $type
     * @param  list<mixed>  $rules  Laravel rules, applied after the type check
     * @param  list<string>  $options  the allowed values of an `enum`
     */
    public function __construct(
        public string $key,
        public string $group,
        public string $type,
        public mixed $default,
        public array $rules = [],
        public bool $secret = false,
        public array $options = [],
        public bool $editable = true,
        public bool $deletableOnly = false,
        public bool $alwaysStore = false,
        public ?string $module = null,
    ) {}

    public function label(): string
    {
        return "settings.{$this->key}.label";
    }

    public function hint(): string
    {
        return "settings.{$this->key}.hint";
    }
}
