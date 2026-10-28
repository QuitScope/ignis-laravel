<?php

namespace Modules\Enotf\Support;

use Closure;

/**
 * Plausibility: 1:1 port of the legacy rule set (core assets/functions/enotf/conditions.php and the
 * plugin's Crew/Support/ConditionsService.php, identical rules): 35 base rules, `transportziel = 4` (Fehleinsatz) drops the
 * patient rules, codes 2/21/22 add `ziel_adresse`/`s7`/`s8`, the reanimation tree, ZERO_IS_VALID for the fill states.
 * Twin of resources/js/lib/enotfConditions.ts; both run tests/Fixtures/enotf-conditions.json.
 *
 * Input: flat legacy key => value map (ProtocolValues::from, or the page's form state). Values are first brought into the
 * legacy conventions the checks expect (legacy()): '' -> null, bool -> 0/1, a list -> its JSON text (an empty list counts
 * as set, like legacy '[]'/'0'; except `medis`: no rows count only with medis_keine, legacy '0'), an object without any
 * value -> null. Sections are the page slugs where the field lives.
 */
final class ConditionsService
{
    public const ZERO_IS_VALID = [
        'patsex', 'awsicherung_neu', 'b_symptome', 'b_auskult', 'b_beatmung', 'c_kreislauf', 'c_ekg', 'c_zugang',
        'd_bewusstsein', 'd_ex_1', 'd_pupillenw_1', 'd_pupillenw_2', 'd_lichtreakt_1', 'd_lichtreakt_2', 'd_gcs_1', 'd_gcs_2', 'd_gcs_3',
        'v_muster_k', 'v_muster_t', 'v_muster_a', 'v_muster_al', 'v_muster_bl', 'v_muster_w', 'transportziel', 'medis',
        'naca_initial', 'naca_uebergabe',
    ];

    public const REA_DETAILS = ['rea_ursache', 'rea_kollaps', 'rea_hdm_durch', 'rea_hdm_zeit', 'rea_rosc', 'rea_kh_aufnahme'];

    private const OVERRIDES = [4 => [
        'patsex', 'spat', 'atemwege', 'zyanose', 'b_symptome', 'b_auskult', 'c_kreislauf', 'c_ekg', 'c_puls',
        'd_bewusstsein', 'd_extremitaeten', 'd_pupillen', 'd_gcs', 'psych', 'messwerte', 'naca_initial', 'diagnose_haupt',
        'awsicherung_neu', 'b_beatmung', 'c_zugang', 'medis', 'na_nachf', 'rea_status', 'rea_details',
    ]];

    /**
     * rule => [section slug, field, fill-state columns (legacy `db`), check (true = missing)].
     *
     * @return array<string, array{0: string, 1: string, 2: list<string>, 3: Closure(array<string, mixed>): bool}>
     */
    private static function base(): array
    {
        $null = fn (string $k) => fn (array $d) => ($d[$k] ?? null) === null;
        $empty = fn (string $k) => fn (array $d) => empty($d[$k]);
        $anyNull = fn (string ...$k) => fn (array $d) => in_array(null, array_map(fn ($c) => $d[$c] ?? null, $k), true);

        return [
            'patsex' => ['anamnese', 'patsex', ['patsex'], $null('patsex')],
            'edatum' => ['rettdaten', 'edatum', ['edatum'], $empty('edatum')],
            'ezeit' => ['rettdaten', 'ezeit', ['ezeit'], $empty('ezeit')],
            'transportziel' => ['rettdaten', 'transportziel', ['transportziel'], $null('transportziel')],
            'salarm' => ['rettdaten', 'salarm', ['salarm'], $empty('salarm')],
            'spat' => ['rettdaten', 'spat', ['spat'], $empty('spat')],
            'sende' => ['rettdaten', 'sende', ['sende'], $empty('sende')],
            'eart' => ['rettdaten', 'eart', ['eart'], $null('eart')],
            'transp_adresse' => ['rettdaten', 'transp_adresse', ['transp_adresse'], $empty('transp_adresse')],
            // awfrei_2/3 and zyanose_2 are dead columns (always empty), kept as in the legacy check
            'atemwege' => ['erstbefund', 'awfrei_1', ['awfrei_1'], fn ($d) => empty($d['awfrei_1']) && empty($d['awfrei_2']) && empty($d['awfrei_3'])],
            'zyanose' => ['erstbefund', 'zyanose_1', ['zyanose_1'], fn ($d) => empty($d['zyanose_1']) && empty($d['zyanose_2'])],
            'b_symptome' => ['erstbefund', 'b_symptome', ['b_symptome'], $null('b_symptome')],
            'b_auskult' => ['erstbefund', 'b_auskult', ['b_auskult'], $null('b_auskult')],
            'c_kreislauf' => ['erstbefund', 'c_kreislauf', ['c_kreislauf'], $null('c_kreislauf')],
            'c_ekg' => ['erstbefund', 'c_ekg', ['c_ekg'], $null('c_ekg')],
            'c_puls' => ['erstbefund', 'c_puls_reg', ['c_puls_rad', 'c_puls_reg'], $anyNull('c_puls_reg', 'c_puls_rad')],
            'd_bewusstsein' => ['erstbefund', 'd_bewusstsein', ['d_bewusstsein'], $null('d_bewusstsein')],
            'd_extremitaeten' => ['erstbefund', 'd_ex_1', ['d_ex_1'], $null('d_ex_1')],
            'd_pupillen' => ['erstbefund', 'd_pupillenw_1', ['d_pupillenw_1', 'd_pupillenw_2', 'd_lichtreakt_1', 'd_lichtreakt_2'],
                $anyNull('d_pupillenw_1', 'd_lichtreakt_1', 'd_pupillenw_2', 'd_lichtreakt_2')],
            'd_gcs' => ['erstbefund', 'd_gcs_1', ['d_gcs_1', 'd_gcs_2', 'd_gcs_3'], $anyNull('d_gcs_1', 'd_gcs_2', 'd_gcs_3')],
            'psych' => ['erstbefund', 'psych', ['psych'], $null('psych')],
            'messwerte' => ['erstbefund', 'spo2', ['spo2', 'atemfreq', 'rrsys', 'herzfreq', 'bz'],
                fn ($d) => empty($d['spo2']) || empty($d['atemfreq']) || empty($d['rrsys']) || empty($d['herzfreq']) || empty($d['bz'])],
            'naca_initial' => ['diagnose', 'naca_initial', ['naca_initial'], $null('naca_initial')],
            'elokation' => ['anamnese', 'elokation', ['elokation'], $null('elokation')],
            'diagnose_haupt' => ['diagnose', 'diagnose_haupt', ['diagnose_haupt'], $null('diagnose_haupt')],
            'awsicherung_neu' => ['massnahmen', 'awsicherung_neu', ['awsicherung_neu'], $null('awsicherung_neu')],
            'b_beatmung' => ['massnahmen', 'b_beatmung', ['b_beatmung'], $null('b_beatmung')],
            'c_zugang' => ['massnahmen', 'c_zugang', ['c_zugang'], $null('c_zugang')],
            'medis' => ['massnahmen', 'medis', ['medis'], $null('medis')],
            'ebesonderheiten' => ['abschluss', 'ebesonderheiten', ['ebesonderheiten'], $empty('ebesonderheiten')],
            'na_nachf' => ['abschluss', 'na_nachf', ['na_nachf'], fn ($d) => ($d['prot_by'] ?? null) != 1 && ($d['na_nachf'] ?? null) === null],
            'rea_status' => ['massnahmen', 'rea_status', ['rea_status'], $null('rea_status')],
            'rea_details' => ['massnahmen', 'rea_ursache', [], fn ($d) => (int) ($d['rea_status'] ?? 0) === 2
                && in_array(null, array_map(fn ($c) => $d[$c] ?? null, self::REA_DETAILS), true)],
            'pfname' => ['abschluss', 'pfname', ['pfname'], $empty('pfname')],
            'prot_by' => ['rettdaten', 'prot_by', ['prot_by'], $null('prot_by')],
        ];
    }

    /**
     * Base − overrides + additions for the given transportziel.
     *
     * @return array<string, array{0: string, 1: string, 2: list<string>, 3: Closure(array<string, mixed>): bool}>
     */
    public static function activeRules(?int $transportziel): array
    {
        $rules = array_diff_key(self::base(), array_flip(self::OVERRIDES[$transportziel] ?? []));
        if (in_array($transportziel, [2, 21, 22], true)) {
            $rules += [
                'ziel_adresse' => ['rettdaten', 'ziel_adresse', ['ziel_adresse'], fn ($d) => empty($d['ziel_adresse'])],
                's7' => ['rettdaten', 's7', ['s7'], fn ($d) => empty($d['s7'])],
                's8' => ['rettdaten', 's8', ['s8'], fn ($d) => empty($d['s8'])],
            ];
        }

        return $rules;
    }

    /**
     * Violated rules.
     *
     * @param  array<string, mixed>  $values
     * @return list<array{field: string, rule: string, message_key: string, section: string}>
     */
    public static function evaluate(array $values): array
    {
        $d = self::legacy($values);
        $out = [];
        foreach (self::activeRules(self::transportziel($d)) as $rule => [$section, $field, , $check]) {
            if ($check($d)) {
                $out[] = ['field' => $field, 'rule' => $rule, 'message_key' => "enotf.plausibility.{$rule}", 'section' => $section];
            }
        }

        return $out;
    }

    /**
     * Fill state per page section (legacy notify.php updateNavFillStates): every active column is one group.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, array{status: string, filled: int, total: int}>
     */
    public static function sectionStatus(array $values): array
    {
        $d = self::legacy($values);
        $cols = [];
        foreach (self::activeRules(self::transportziel($d)) as [$section, , $db]) {
            $cols[$section] = array_values(array_unique([...$cols[$section] ?? [], ...$db]));
        }
        $out = [];
        foreach (FieldSchema::SECTIONS as $section) {
            $total = count($cols[$section] ?? []);
            $filled = count(array_filter($cols[$section] ?? [], fn ($c) => self::filled($c, $d[$c] ?? null)));
            $status = match (true) {
                $total === 0 => 'nocheck',
                $filled === $total => 'filled',
                $filled > 0 => 'partfilled',
                default => 'unfilled',
            };
            $out[$section] = ['status' => $status, 'filled' => $filled, 'total' => $total];
        }

        return $out;
    }

    private static function filled(string $field, mixed $v): bool
    {
        $s = $v === null ? '' : (string) $v;

        return $s !== '' && ($s !== '0' || in_array($field, self::ZERO_IS_VALID, true));
    }

    /** @param array<string, mixed> $d */
    private static function transportziel(array $d): ?int
    {
        return isset($d['transportziel']) ? (int) $d['transportziel'] : null;
    }

    /**
     * Values in the legacy conventions (a missing key reads as null).
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private static function legacy(array $values): array
    {
        $d = [];
        foreach ($values as $k => $v) {
            $d[$k] = match (true) {
                is_string($v) => trim($v) === '' ? null : $v,
                is_bool($v) => (int) $v,
                is_array($v) && ! array_is_list($v) => array_filter($v, fn ($x) => $x !== null && $x !== '') === [] ? null : json_encode($v),
                is_array($v) => json_encode($v),
                default => $v,
            };
        }
        if (($d['transportziel'] ?? null) === null && ($d['destination_poi_id'] ?? null) !== null) {
            $d['transportziel'] = 0;
        }
        if (($d['medis'] ?? null) === null || $d['medis'] === '[]') {
            $d['medis'] = in_array($d['medis_keine'] ?? null, [null, 0, '0'], true) ? null : '0';
        }

        return $d;
    }
}
