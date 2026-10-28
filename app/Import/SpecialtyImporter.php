<?php

namespace App\Import;

use Illuminate\Support\Facades\DB;

/**
 * intra_mitarbeiter_fdquali => specialties. Adopts by number first (a renamed default keeps
 * its number, and a second row with that number could not be edited), then by name.
 */
final class SpecialtyImporter extends LookupImporter
{
    public function entity(): string
    {
        return 'specialty';
    }

    protected function legacyTable(): string
    {
        return 'intra_mitarbeiter_fdquali';
    }

    protected function table(): string
    {
        return 'specialties';
    }

    protected function adoptable(array $values, array $taken): ?object
    {
        return DB::table('specialties')->where('number', $values['number'])->orderBy('id')->get(['id', 'name'])
            ->first(fn ($r) => ! isset($taken[$r->id])) ?? parent::adoptable($values, $taken);
    }

    protected function values(object $row): array
    {
        return [
            'number' => (int) $row->sgnr, 'name' => (string) $row->sgname, 'is_disabled' => (bool) $row->disabled,
        ];
    }
}
