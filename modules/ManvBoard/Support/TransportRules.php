<?php

namespace Modules\ManvBoard\Support;

use App\Models\Vehicle;
use Modules\ManvBoard\Models\ManvPatient;

/** When a patient may leave the scene ("Abfahrt"). */
class TransportRules
{
    /** Legacy destination meaning "stays on site"; stored as is so imported rows keep their meaning. */
    public const NO_TRANSPORT = 'Kein Transport';

    /** Ambulance services only: 1 = EMS with physician, 2 = EMS (legacy rd_type >= 1 also let fire engines go, bug 1.4-6). */
    private const SERVICES = [Vehicle::SERVICE_EMS_PHYSICIAN, Vehicle::SERVICE_EMS];

    public static function canDepart(ManvPatient $patient): bool
    {
        return $patient->departed_at === null
            && $patient->triage?->transportable() === true
            && in_array($patient->resource?->vehicle?->service, self::SERVICES, true)
            && ! in_array($patient->destination, [null, '', self::NO_TRANSPORT], true);
    }
}
