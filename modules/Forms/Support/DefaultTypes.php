<?php

namespace Modules\Forms\Support;

use Illuminate\Support\Facades\DB;

/** The two types of the legacy install (migration 20250607000041), seeded only into an empty form_types table. */
final class DefaultTypes
{
    public const NAMES = ['Beförderungsantrag', 'Urlaubsantrag'];

    public static function seed(): void
    {
        if (DB::table('form_types')->exists()) {
            return;
        }
        $now = now();
        $f = fn (string $key, string $label, string $type, bool $required, string $width, ?string $placeholder = null, bool $readonly = false, ?string $autoFill = null) => compact('key', 'label', 'type', 'required', 'width', 'placeholder', 'readonly') + ['auto_fill' => $autoFill];

        $types = [
            [self::NAMES[0], 'Antrag auf Beförderung in den nächsten Rank', 'chevrons-up', false, [
                $f('name_dn', 'Name und Dienstnummer', 'text', true, 'half', null, true, 'fullname_dienstnr'),
                $f('dienstgrad', 'Aktueller Rank', 'text', true, 'half', null, true, 'dienstgrad'),
                $f('freitext', 'Schriftlicher Antrag', 'textarea', true, 'full'),
            ]],
            [self::NAMES[1], 'Beantragung von Urlaub oder Dienstfreistellung', 'umbrella', true, [
                $f('name_dn', 'Name und Dienstnummer', 'text', true, 'half', null, false, 'fullname_dienstnr'),
                $f('dienstgrad', 'Rank', 'text', true, 'half', null, false, 'dienstgrad'),
                $f('von_datum', 'Urlaub von', 'date', true, 'half', 'TT.MM.JJJJ'),
                $f('bis_datum', 'Urlaub bis', 'date', true, 'half', 'TT.MM.JJJJ'),
                $f('grund', 'Grund / Anmerkungen', 'textarea', false, 'full', 'Optional: Begründung für den Urlaubsantrag'),
            ]],
        ];

        foreach ($types as $i => [$name, $description, $icon, $absence, $fields]) {
            $id = DB::table('form_types')->insertGetId([
                'name' => $name, 'description' => $description, 'icon' => $icon, 'is_active' => true, 'is_absence' => $absence,
                'sort_order' => $i + 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
            foreach ($fields as $n => $field) {
                DB::table('form_fields')->insert($field + ['form_type_id' => $id, 'sort_order' => $n + 1, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
    }
}
