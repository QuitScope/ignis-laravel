<?php

namespace Modules\FireTab\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Modules\FireTab\Events\FireIncidentFinalized;
use Modules\FireTab\Models\FireIncident;
use Modules\FireTab\Support\FireLog;

/** POST /firetab/incidents/{incident}/finalize: crew completes the protocol for QM review. Idempotent under the row lock. */
class FinalizeController extends Controller
{
    public function __invoke(Request $request, FireIncident $incident): RedirectResponse
    {
        DB::transaction(function () use ($request, $incident) {
            $locked = FireIncident::query()->whereKey($incident->id)->lockForUpdate()->firstOrFail();
            if ($locked->finalized_at !== null || $locked->archived_at !== null) {
                Gate::authorize('view', $locked);
                abort(409);
            }
            Gate::authorize('finalize', $locked);

            $missing = array_keys(array_filter(
                ['location' => $locked->location, 'keyword' => $locked->keyword, 'started_at' => $locked->started_at, 'leader' => $locked->leader_personnel_id ?? $locked->leader_external_name],
                fn ($v) => blank($v),
            ));
            if ($missing !== []) {
                throw ValidationException::withMessages(array_combine($missing, array_map(
                    fn ($f) => __('validation.required', ['attribute' => __("firetab.finish.field.$f")]), $missing,
                )));
            }

            $locked->forceFill(['finalized_at' => now(), 'finalized_by' => $request->user()->id, 'updated_by' => $request->user()->id])->save();
            FireLog::record($locked, 'finalized');
            DB::afterCommit(fn () => FireIncidentFinalized::dispatch($locked->id));
        });

        return redirect("/firetab/incidents/{$incident->id}?tab=abschluss");
    }
}
