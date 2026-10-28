<?php

namespace Modules\ManvBoard\Enums;

enum Sex: string
{
    case Male = 'm';
    case Female = 'f';
    case Diverse = 'd';
    case Unknown = 'unknown';
}
