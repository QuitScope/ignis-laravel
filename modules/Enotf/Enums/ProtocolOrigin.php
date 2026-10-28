<?php

namespace Modules\Enotf\Enums;

/** Legacy createdby: 1 dispatch (EMD), 2 manual; unknown for imported rows the legacy default made ambiguous. */
enum ProtocolOrigin: string
{
    case Dispatch = 'dispatch';
    case Manual = 'manual';
    case Unknown = 'unknown';
}
