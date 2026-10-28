<?php

namespace Modules\KnowledgeBase\Support;

/** Competency levels with colour and label (legacy KBHelper::getCompetencyInfo; the colours are data, not theme). */
final class KbCompetency
{
    private const LEVELS = [
        'basis' => ['label' => 'Basis', 'color' => '#767171', 'text' => '#ffffff', 'desc' => 'Basismaßnahmen; durch jedes Rettungsdienstpersonal ausführbar'],
        'rettsan' => ['label' => 'RettSan', 'color' => '#00b0f0', 'text' => '#ffffff', 'desc' => 'Durchführung durch RettSan bei Hinzuziehung/Nachsicht eines Arztes'],
        'notsan_2c' => ['label' => 'NFS 2c', 'color' => '#00b050', 'text' => '#ffffff', 'desc' => 'Eigenständige Durchführung durch NotSan im Rahmen § 4 Abs. 2c NotSanG'],
        'notsan_2a' => ['label' => 'NFS 2a', 'color' => '#ffc000', 'text' => '#000000', 'desc' => 'Eigenverantwortliche Durchführung durch NotSan im Rahmen § 2a NotSanG'],
        'notarzt' => ['label' => 'Notarzt', 'color' => '#c00000', 'text' => '#ffffff', 'desc' => 'Durchführung nur durch Notärzte vorgesehen'],
    ];

    /** @return array{label: string, color: string, text: string, desc: string}|null null for no or an unknown level */
    public static function info(?string $level): ?array
    {
        return $level === null ? null : (self::LEVELS[$level] ?? null);
    }
}
