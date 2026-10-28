<?php

namespace Modules\Forms\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Modules\Forms\Models\FormSubmission;

/** A decision really changed status or remark (the calendar mirrors accepted absences on it). */
class FormDecided
{
    use Dispatchable;

    public function __construct(public readonly FormSubmission $submission, public readonly string $oldStatus) {}
}
