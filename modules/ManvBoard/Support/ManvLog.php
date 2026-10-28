<?php

namespace Modules\ManvBoard\Support;

use App\Models\User;
use App\Realtime\Realtime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Modules\ManvBoard\Models\ManvIncident;
use Modules\ManvBoard\Models\ManvLogEntry;
use Modules\ManvBoard\Models\ManvPatient;
use Modules\ManvBoard\Models\ManvResource;

/** Action log writer: account FK and name snapshot come from the signed-in account, never from input. */
class ManvLog
{
    public static function record(ManvIncident $incident, string $action, string $description, ?Model $subject = null): ManvLogEntry
    {
        $user = Auth::user();
        $entry = new ManvLogEntry;
        $entry->forceFill([
            'manv_incident_id' => $incident->id,
            'occurred_at' => now(),
            'action' => $action,
            'description' => $description,
            'user_id' => $user?->id,
            'user_name' => $user instanceof User ? ($user->personnel->full_name ?? $user->name) : null,
            'subject_type' => match (true) {
                $subject instanceof ManvPatient => 'patient',
                $subject instanceof ManvResource => 'resource',
                default => null,
            },
            'subject_id' => $subject?->getKey(),
        ])->save();
        Realtime::pulse('manv.'.$incident->id, 'board');

        return $entry;
    }
}
