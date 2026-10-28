<?php

namespace Modules\Enotf\Http\Controllers;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Enotf\Models\EnotfProtocol;
use Modules\Enotf\Models\MedicationGiven;
use Modules\Enotf\Models\ProtocolCrew;
use Modules\Enotf\Support\FieldSchema;
use Modules\Enotf\Support\ProtocolValues;
use Modules\Enotf\Support\VitalparameterCatalog;

/**
 * GET /enotf/print/{enr}: the one print view (legacy had two, one unchecked). Policy `print`
 * (= view): crew of the vehicle or named in the protocol, QM/admin/super-admin read; nobody else. No global PIN.
 */
class PrintController extends Controller
{
    public function __invoke(EnotfProtocol $protocol): Response
    {
        $this->authorize('print', $protocol);

        return Inertia::render('Enotf/Print', self::props($protocol));
    }

    /**
     * The print view's data; also the clinic's read-only view of the one protocol of its clinic code (ClinicCodeController).
     *
     * @return array<string, mixed>
     */
    public static function props(EnotfProtocol $protocol): array
    {
        $tz = (string) config('ignis.timezone');

        return [
            'protocol' => [
                'enr' => $protocol->enr,
                'status' => $protocol->status->value,
                'vehicles' => array_values(array_filter([$protocol->vehicle_transport_label, $protocol->vehicle_na_label])),
                'released_at' => $protocol->released_at?->setTimezone($tz)->format('d.m.Y H:i'),
            ],
            'crew' => $protocol->crew()->orderBy('slot')->orderBy('position')->get()
                ->map(fn (ProtocolCrew $c) => ['slot' => $c->slot, 'position' => $c->position, 'name' => $c->name, 'qualification' => $c->qualification])->all(),
            'values' => ProtocolValues::from($protocol),
            'schema' => FieldSchema::client(),
            'vitals' => VitalController::course($protocol),
            'bz_unit' => VitalparameterCatalog::bzUnit(),
            'medications' => MedicationGiven::listFor($protocol),
            'today' => now()->setTimezone($tz)->format('Y-m-d'),
        ];
    }
}
