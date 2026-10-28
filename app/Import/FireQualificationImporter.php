<?php

namespace App\Import;

/** intra_mitarbeiter_fwquali => fire_qualifications. */
final class FireQualificationImporter extends LookupImporter
{
    public function entity(): string
    {
        return 'fire_qualification';
    }

    protected function legacyTable(): string
    {
        return 'intra_mitarbeiter_fwquali';
    }

    protected function table(): string
    {
        return 'fire_qualifications';
    }

    protected function values(object $row): array
    {
        return [
            'priority' => (int) $row->priority, 'short_name' => (string) $row->shortname, 'name' => (string) $row->name,
            'name_male' => (string) $row->name_m, 'name_female' => (string) $row->name_w, 'is_none' => (bool) $row->none,
        ];
    }
}
