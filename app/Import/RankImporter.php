<?php

namespace App\Import;

/** intra_mitarbeiter_dienstgrade => ranks. */
final class RankImporter extends LookupImporter
{
    public function entity(): string
    {
        return 'rank';
    }

    protected function legacyTable(): string
    {
        return 'intra_mitarbeiter_dienstgrade';
    }

    protected function table(): string
    {
        return 'ranks';
    }

    protected function values(object $row): array
    {
        return [
            'priority' => (int) $row->priority, 'name' => (string) $row->name, 'name_male' => (string) $row->name_m,
            'name_female' => (string) $row->name_w, 'badge' => $row->badge, 'is_archive' => (bool) $row->archive,
        ];
    }
}
