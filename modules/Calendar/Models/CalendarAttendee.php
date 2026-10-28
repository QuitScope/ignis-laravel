<?php

namespace Modules\Calendar\Models;

use App\Models\Personnel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A personnel record invited to an event (or, for role events with attendance tracking, one that answered).
 *
 * @property int $id
 * @property int $event_id
 * @property int $personnel_id
 * @property string $response pending|accepted|declined|tentative
 * @property ?Carbon $responded_at
 * @property bool $is_organizer
 */
class CalendarAttendee extends Model
{
    public const RESPONSES = ['pending', 'accepted', 'declined', 'tentative'];

    protected $table = 'calendar_attendees';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['responded_at' => 'datetime', 'is_organizer' => 'boolean'];
    }

    /** @return BelongsTo<CalendarEvent, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(CalendarEvent::class, 'event_id');
    }

    /** @return BelongsTo<Personnel, $this> */
    public function personnel(): BelongsTo
    {
        return $this->belongsTo(Personnel::class);
    }
}
