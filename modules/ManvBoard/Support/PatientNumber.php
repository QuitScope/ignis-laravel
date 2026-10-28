<?php

namespace Modules\ManvBoard\Support;

use Modules\ManvBoard\Models\ManvIncident;
use Modules\ManvBoard\Models\ManvPatient;

/** Next patient number of a Lage ("MANV-001"): highest number + 1, never count + 1 (legacy bug 1.4-5). */
class PatientNumber
{
    /**
     * Call inside the transaction that inserts the patient: the row lock on the Lage serialises concurrent creations
     * until commit; the unique index (incident, number) catches anything that slips past.
     */
    public static function next(ManvIncident $incident): string
    {
        ManvIncident::query()->whereKey($incident->id)->lockForUpdate()->value('id');

        // ponytail: scans the numbers of one Lage in PHP (imported numbers may be free text); fine for thousands.
        $max = ManvPatient::query()->where('manv_incident_id', $incident->id)->pluck('number')
            ->map(fn (string $n) => preg_match('/^MANV-(\d+)$/', $n, $m) === 1 ? (int) $m[1] : 0)
            ->max() ?? 0;

        return sprintf('MANV-%03d', $max + 1);
    }
}
