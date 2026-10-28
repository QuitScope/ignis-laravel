<?php

namespace Modules\Forms\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** A submission was deleted (model event; no route deletes submissions, only code, tests and importers). */
class FormDeleted
{
    use Dispatchable;

    public function __construct(public readonly int $submissionId, public readonly int $formTypeId) {}
}
