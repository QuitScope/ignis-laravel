<?php

namespace Modules\FireTab\Enums;

enum FireIncidentStatus: string
{
    case New = 'new';
    case Reviewing = 'reviewing';
    case Approved = 'approved';
    case Insufficient = 'insufficient';
    case Hidden = 'hidden';
}
