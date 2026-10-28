<?php

namespace Modules\Enotf\Enums;

/** Replaces freigegeben + hidden_user. Transitions: open -> released | withdrawn, released -> open (QM, 9d). */
enum ProtocolStatus: string
{
    case Open = 'open';
    case Released = 'released';
    case Withdrawn = 'withdrawn';
}
