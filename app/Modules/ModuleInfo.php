<?php

namespace App\Modules;

/** What the registry knows about one module without asking its switch (declared in its ModuleProvider). */
final readonly class ModuleInfo
{
    /**
     * @param  string  $name  lower-case folder name: the switch `modules.<name>.enabled` and the gate `module:<name>`
     * @param  'einsatz'|'verwaltung'  $group
     * @param  list<string>  $depends  names of the modules it needs
     */
    public function __construct(
        public string $name,
        public string $label_key,
        public string $description_key,
        public string $group,
        public array $depends,
        public bool $core,
        public bool $default_on = true,
    ) {}
}
