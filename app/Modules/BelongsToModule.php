<?php

namespace App\Modules;

/**
 * Implemented next to SearchSource or Widget by the hooks a module registers: the registries leave them out while
 * the module is switched off (ModuleRegistry::active()). Core sources and widgets simply do not implement it.
 */
interface BelongsToModule
{
    public function module(): string;
}
