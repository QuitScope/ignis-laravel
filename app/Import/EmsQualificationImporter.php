<?php

namespace App\Import;

/** intra_mitarbeiter_rdquali => ems_qualifications. */
final class EmsQualificationImporter extends LookupImporter
{
    public function entity(): string
    {
        return 'ems_qualification';
    }

    protected function legacyTable(): string
    {
        return 'intra_mitarbeiter_rdquali';
    }

    protected function table(): string
    {
        return 'ems_qualifications';
    }

    protected function values(object $row): array
    {
        return [
            'priority' => (int) $row->priority, 'name' => (string) $row->name, 'name_male' => (string) $row->name_m,
            'name_female' => (string) $row->name_w, 'abbreviation' => $row->abkuerzung, 'is_none' => (bool) $row->none,
            'is_trainable' => (bool) $row->trainable,
        ];
    }
}
