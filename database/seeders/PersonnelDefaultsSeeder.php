<?php

namespace Database\Seeders;

use App\Models\EmsQualification;
use App\Models\FireQualification;
use App\Models\PersonnelTitle;
use App\Models\Rank;
use App\Models\Specialty;
use Illuminate\Database\Seeder;

/**
 * Default lists of the legacy system, copied verbatim (including its typos), idempotent by name.
 * Legacy sources: migrations 20250607000014 (ranks), 16 (fire), 19 + 113 (EMS), 24 (specialties),
 * 20261006100001 (titles).
 */
class PersonnelDefaultsSeeder extends Seeder
{
    private const RANKS = [
        ['Angestellte/-r', 1, 'Angestellter', 'Angestellte', null, false],
        ['Brandmeisteranwärter/-in', 2, 'Brandmeisteranwärter', 'Brandmeisteranwärterin', '/assets/img/dienstgrade/bf/1.png', false],
        ['Brandmeister/-in', 3, 'Brandmeister', 'Brandmeisterin', '/assets/img/dienstgrade/bf/2.png', false],
        ['Oberbrandmeister/-in', 4, 'Oberbrandmeister', 'Oberbrandmeisterin', '/assets/img/dienstgrade/bf/3.png', false],
        ['Hauptbrandmeister/-in', 5, 'Hauptbrandmeister', 'Hauptbrandmeisterin', '/assets/img/dienstgrade/bf/4.png', false],
        ['Hauptbrandmeister/-in mit AZ', 6, 'Hauptbrandmeister mit AZ', 'Hauptbrandmeisterin mit AZ', '/assets/img/dienstgrade/bf/5.png', false],
        ['Brandinspektor/-in', 8, 'Brandinspektor', 'Brandinspektorin', '/assets/img/dienstgrade/bf/6.png', false],
        ['Oberbrandinspektor/-in', 9, 'Oberbrandinspektor', 'Oberbrandinspektorin', '/assets/img/dienstgrade/bf/7.png', false],
        ['Brandamtmann/frau', 10, 'Brandamtmann', 'Brandamtfrau', '/assets/img/dienstgrade/bf/8.png', false],
        ['Brandamtsrat/rätin', 11, 'Brandamtsrat', 'Brandamtsrätin', '/assets/img/dienstgrade/bf/9.png', false],
        ['Brandoberamtsrat/rätin', 12, 'Brandoberamtsrat', 'Brandoberamtsrätin', '/assets/img/dienstgrade/bf/10.png', false],
        ['Brandreferendar/-in', 13, 'Brandreferendar', 'Brandreferendarin', '/assets/img/dienstgrade/bf/15.png', false],
        ['Brandrat/rätin', 14, 'Brandrat', 'Brandrätin', '/assets/img/dienstgrade/bf/11.png', false],
        ['Oberbrandrat/rätin', 15, 'Oberbrandrat', 'Oberbrandrätin', '/assets/img/dienstgrade/bf/12.png', false],
        ['Brandinspektoranwärter/-in', 7, 'Brandinspektoranwärter', 'Brandinspektoranwärterin', '/assets/img/dienstgrade/bf/17_2.png', false],
        ['Ehrenamtliche/-r', 0, 'Ehrenamtlicher', 'Ehrenamtliche', null, false],
        ['Branddirektor/-in', 16, 'Branddirektor', 'Branddirektorin', '/assets/img/dienstgrade/bf/13.png', false],
        ['Leitende/-r Branddirektor/-in', 17, 'Leitender Branddirektor', 'Leitende Branddirektorin', '/assets/img/dienstgrade/bf/14.png', false],
        ['Entlassen/Archiv', 0, 'Entlassen/Archiv', 'Entlassen/Archiv', null, true],
    ];

    private const FIRE = [
        ['-', 'Keine', 0, 'Keine', 'Keine', true],
        ['B1', 'Grundausbildung', 1, 'Grundausbildung', 'Grundausbildung', false],
        ['B2', 'Maschinist/-in', 2, 'Maschinist', 'Maschinistin', false],
        ['B3', 'Gruppenführer/-in', 3, 'Gruppenführer', 'Gruppenführerin', false],
        ['B4', 'Zugführer/-in', 4, 'Zugführer', 'Zugführerin', false],
        ['B5', 'B-Dienst', 5, 'B-Dienst', 'B-Dienst', false],
        ['B6', 'A-Dienst', 6, 'A-Dienst', 'A-Dienst', false],
    ];

    private const EMS = [
        ['Rettungssanitäter/-in i. A.', 1, 'Rettungssanitäter i. A.', 'Rettungssanitäterin i. A.', 'RettSan i.A.', false, false],
        ['Keine', 0, 'Keine', 'Keine', null, true, false],
        ['Rettungssanitäter/-in', 2, 'Rettungssanitäter', 'Rettungssanitäterin', 'RettSan', false, true],
        ['Notfallsanitäter/-in i. A.', 3, 'Notfallsanitäter i. A.', 'Notfallsanitäterin i. A.', 'NotSan i.A.', false, false],
        ['Notfallsanitäter/-in', 4, 'Notfallsanitäter', 'Notfallsanitäterin', 'NotSan', false, true],
        ['Notarzt/ärztin', 5, 'Notarzt', 'Notärztin', 'Notarzt', false, false],
        ['Ärztliche/-r Leiter/-in RD', 6, 'Ärztlicher Leiter RD', 'Ärztliche Leiterin RD', null, false, false],
    ];

    private const SPECIALTIES = [
        211 => 'Integrierte Leitstelle', 212 => 'Einsatzleitdienst', 213 => 'Presseabteilung', 221 => 'FW Schule',
        222 => 'Personaleinsatz FW', 223 => 'Lager und Logistik', 231 => 'Spezialrettung', 232 => 'CBRN-SChutz',
        233 => 'Krisenintervention', 411 => 'RD Schule', 412 => 'Einsatzleitung RD', 413 => 'Luftrettung',
        414 => 'Qualitätsmanagement RD',
    ];

    private const TITLES = ['Dr.' => 10, 'Dr. med.' => 20, 'Prof.' => 30, 'Prof. Dr.' => 40];

    public function run(): void
    {
        foreach (self::RANKS as [$name, $priority, $male, $female, $badge, $archive]) {
            Rank::firstOrCreate(['name' => $name], [
                'priority' => $priority, 'name_male' => $male, 'name_female' => $female, 'badge' => $badge, 'is_archive' => $archive,
            ]);
        }

        foreach (self::FIRE as [$short, $name, $priority, $male, $female, $none]) {
            FireQualification::firstOrCreate(['name' => $name], [
                'priority' => $priority, 'short_name' => $short, 'name_male' => $male, 'name_female' => $female, 'is_none' => $none,
            ]);
        }

        foreach (self::EMS as [$name, $priority, $male, $female, $abbreviation, $none, $trainable]) {
            EmsQualification::firstOrCreate(['name' => $name], [
                'priority' => $priority, 'name_male' => $male, 'name_female' => $female,
                'abbreviation' => $abbreviation, 'is_none' => $none, 'is_trainable' => $trainable,
            ]);
        }

        foreach (self::SPECIALTIES as $number => $name) {
            Specialty::firstOrCreate(['name' => $name], ['number' => $number, 'is_disabled' => false]);
        }

        foreach (self::TITLES as $name => $priority) {
            PersonnelTitle::firstOrCreate(['name' => $name], ['priority' => $priority]);
        }
    }
}
