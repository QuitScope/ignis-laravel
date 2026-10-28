<?php

namespace Modules\Enotf\Enums;

/** Legacy protokoll_status 0-4; independent of ProtocolStatus. */
enum QmStatus: int
{
    case New = 0;
    case Reviewing = 1;
    case Approved = 2;
    case Insufficient = 3;
    case Hidden = 4;
}
