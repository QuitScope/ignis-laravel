<?php

namespace Modules\FireTab\Enums;

/** The server enforces no order: AsuState takes the first valid event of each type. */
enum AsuEventType: string
{
    case Angeschlossen = 'angeschlossen';
    case Druck = 'druck';
    case Ziel = 'ziel';
    case Hinweis13 = 'hinweis_13';
    case Hinweis23 = 'hinweis_23';
    case Rueckzug = 'rueckzug';
    case Draussen = 'draussen';
    case Mayday = 'mayday';
    case MaydayEnde = 'mayday_ende';

    public function logged(): bool
    {
        return in_array($this, [self::Angeschlossen, self::Draussen, self::Mayday, self::MaydayEnde], true);
    }
}
