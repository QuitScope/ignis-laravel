<?php

namespace App\Import;

use RuntimeException;

/**
 * An importer that only understands one state of the legacy schema. `ignis:import-legacy` calls the check
 * after the table check and before anything is written; a RuntimeException aborts the whole run.
 */
interface ChecksLegacyState
{
    /** @throws RuntimeException with a message for the operator */
    public function checkLegacyState(LegacyReader $legacy): void;
}
