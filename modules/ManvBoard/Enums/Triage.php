<?php

namespace Modules\ManvBoard\Enums;

/** Triage categories SK1..SK6 (legacy `tot` is SK5). */
enum Triage: string
{
    case SK1 = 'SK1';
    case SK2 = 'SK2';
    case SK3 = 'SK3';
    case SK4 = 'SK4';
    case SK5 = 'SK5';
    case SK6 = 'SK6';

    public function transportable(): bool
    {
        return in_array($this, [self::SK1, self::SK2, self::SK3], true);
    }
}
