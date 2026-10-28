<?php

namespace Modules\ManvBoard\Enums;

enum ManvIncidentStatus: string
{
    case Active = 'active';
    case Closed = 'closed';
    case Archived = 'archived';
}
