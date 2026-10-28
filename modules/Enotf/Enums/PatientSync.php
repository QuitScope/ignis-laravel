<?php

namespace Modules\Enotf\Enums;

/** Legacy pat_synced 0/1/2: patient data matched with the dispatch system. */
enum PatientSync: string
{
    case None = 'none';
    case Pending = 'pending';
    case Synced = 'synced';
}
