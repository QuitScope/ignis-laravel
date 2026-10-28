<?php

namespace App\Import;

/** intra_mitarbeiter_titel => personnel_titles. */
final class PersonnelTitleImporter extends LookupImporter
{
    public function entity(): string
    {
        return 'personnel_title';
    }

    protected function legacyTable(): string
    {
        return 'intra_mitarbeiter_titel';
    }

    protected function table(): string
    {
        return 'personnel_titles';
    }

    protected function values(object $row): array
    {
        return [
            'name' => (string) $row->name, 'priority' => (int) $row->priority,
        ];
    }
}
