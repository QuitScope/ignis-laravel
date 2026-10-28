<?php

namespace Modules\ManvBoard\Enums;

enum ResourceStatus: string
{
    case Available = 'available';
    case Deployed = 'deployed';
    case Unavailable = 'unavailable';
}
