<?php

namespace Modules\Enotf\Support;

use Illuminate\Support\Facades\Schema;
use Modules\Enotf\Models\EnotfProtocol;
use Modules\Enotf\Models\MedicationGiven;

/** A protocol flattened into the legacy key space (header columns + data JSON), the input of ConditionsService. */
final class ProtocolValues
{
    public const MEDICATION_TABLE = 'enotf_medications_given';

    /** @return array<string, mixed> */
    public static function from(EnotfProtocol $protocol): array
    {
        return ProtocolMerger::values($protocol) + ['destination_poi_id' => $protocol->destination_poi_id, 'medis' => self::medis($protocol)];
    }

    /**
     * `medis` = the protocol's given medication rows (deleted ones excluded). No rows satisfy the plausibility rule only
     * with the explicit confirmation `medis_keine` (legacy '0', ConditionsService::legacy()). Without the table it is
     * the same empty list.
     *
     * @return list<array<string, mixed>>
     */
    private static function medis(EnotfProtocol $protocol): array
    {
        return self::medicationRowsAvailable() && $protocol->exists ? MedicationGiven::listFor($protocol) : [];
    }

    public static function medicationRowsAvailable(): bool
    {
        return Schema::hasTable(self::MEDICATION_TABLE);
    }
}
