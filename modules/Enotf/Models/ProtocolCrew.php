<?php

namespace Modules\Enotf\Models;

use App\Models\Personnel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A crew member written into a protocol: slot transport|na, position 1-3, personnel record (null for legacy free
 * text), name and qualification as snapshot.
 *
 * @property int $id
 * @property int $enotf_protocol_id
 * @property string $slot
 * @property int $position
 * @property ?int $personnel_id
 * @property ?string $name
 * @property ?string $qualification
 */
class ProtocolCrew extends Model
{
    protected $table = 'enotf_protocol_crew';

    protected $guarded = ['*'];

    public $timestamps = false;

    /** @return BelongsTo<EnotfProtocol, $this> */
    public function protocol(): BelongsTo
    {
        return $this->belongsTo(EnotfProtocol::class, 'enotf_protocol_id');
    }

    /** @return BelongsTo<Personnel, $this> */
    public function personnel(): BelongsTo
    {
        return $this->belongsTo(Personnel::class);
    }
}
