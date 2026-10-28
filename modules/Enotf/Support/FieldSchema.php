<?php

namespace Modules\Enotf\Support;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Every protocol field the crew writes: key = legacy intra_edivi column, so plausibility,
 * print and the importer port 1:1. The single allowlist of the autosave (no mass assignment) and the source of typing.
 *
 * A definition: [type, section 1-7 (1.3b: rettdaten, anamnese, erstbefund, diagnose, massnahmen, verlauf, abschluss),
 * options]. Options: `column` = header column of enotf_protocols (core field; all others are keys of the `data` JSON),
 * `max` (text length / number; numbers start at 0), `catalog` (EnotfCatalogs, for enum and int lists), `shape` (json: ints|objects|object)
 * with `keys` (allowed object keys) and `key_catalogs` (object key => catalog), `specials` (decimal: text codes taken as they are).
 * Not here: lifecycle/meta columns (enr, freigegeben, protokoll_status, …, set by the server), the crew text columns
 * fzg_*_perso* (table enotf_protocol_crew), medis (9b table; its "none given" confirmation is the field
 * medis_keine), and the 20 dead columns (DEAD, -> `legacy`).
 */
final class FieldSchema
{
    public const TYPES = ['text', 'int', 'decimal', 'bool', 'date', 'time', 'datetime', 'enum', 'json'];

    public const DEAD = [
        'c_zugang_art_1', 'c_zugang_gr_1', 'c_zugang_ort_1', 'c_zugang_art_2', 'c_zugang_gr_2', 'c_zugang_ort_2',
        'c_zugang_art_3', 'c_zugang_gr_3', 'c_zugang_ort_3', 'v_muster_ar', 'v_muster_ar1', 'v_muster_br', 'v_muster_br1',
        'sz_toleranz_2', 'naname', 'awfrei_2', 'awfrei_3', 'zyanose_2', 'qmkommentar', 'eort',
    ];

    private const JSON_MAX_BYTES = 8000;

    private const ADDRESS = ['shape' => 'object', 'keys' => ['strasse', 'hnr', 'ort', 'ortsteil']];

    /** Body map (v_muster_*): severity per region, injury type in the `1` twin. */
    private const SEVERITY = ['enum', 3, ['catalog' => 'v_muster_schwere']];

    private const INJURY = ['enum', 3, ['catalog' => 'v_muster_art']];

    private const SPECIALS = ['ng', 'nm'];

    public const SECTIONS = [1 => 'rettdaten', 2 => 'anamnese', 3 => 'erstbefund', 4 => 'diagnose', 5 => 'massnahmen', 6 => 'verlauf', 7 => 'abschluss'];

    /** @var array<string, array{0: string, 1: int, 2?: array<string, mixed>}> */
    private const FIELDS = [
        'edatum' => ['date', 1, ['column' => 'mission_date']],
        'ezeit' => ['time', 1, ['column' => 'mission_time']],
        'eart' => ['enum', 1, ['catalog' => 'eart']],
        'prot_by' => ['int', 1, ['max' => 1]],
        'transportziel' => ['enum', 1, ['column' => 'destination_code', 'catalog' => 'transportziel']],
        'salarm' => ['datetime', 1, ['column' => 'alarmed_at']],
        's1' => ['datetime', 1], 's2' => ['datetime', 1], 's3' => ['datetime', 1], 's4' => ['datetime', 1],
        'spat' => ['datetime', 1], 's7' => ['datetime', 1], 's8' => ['datetime', 1],
        'sende' => ['datetime', 1, ['column' => 'ended_at']],
        'sonderrechte_anfahrt' => ['enum', 1, ['catalog' => 'sonderrechte']],
        'sonderrechte_transport' => ['enum', 1, ['catalog' => 'sonderrechte']],
        'transp_poi' => ['text', 1], 'ziel_poi' => ['text', 1],
        'transp_adresse' => ['json', 1, self::ADDRESS], 'ziel_adresse' => ['json', 1, self::ADDRESS],
        'fzg_transp' => ['text', 1, ['column' => 'vehicle_transport_label']],
        'fzg_na' => ['text', 1, ['column' => 'vehicle_na_label']],
        'fzg_sonst' => ['text', 1],
        'pat_vorname' => ['text', 2, ['column' => 'patient_first_name']],
        'pat_nachname' => ['text', 2, ['column' => 'patient_last_name']],
        'patgebdat' => ['date', 2, ['column' => 'patient_birthdate']],
        'patsex' => ['enum', 2, ['column' => 'patient_sex', 'catalog' => 'patsex']],
        'anmerkungen' => ['text', 2, ['max' => 10000]],
        'symptombeginn_datum' => ['date', 2], 'symptombeginn_zeit' => ['time', 2],
        'symptombeginn_geschaetzt' => ['bool', 2], 'symptombeginn_nf' => ['bool', 2],
        'elokation' => ['enum', 2, ['catalog' => 'elokation']],
        'az_vor_ereignis' => ['enum', 2, ['catalog' => 'az_vor_ereignis']],
        'awfrei_1' => ['enum', 3, ['catalog' => 'awfrei']], 'zyanose_1' => ['enum', 3, ['catalog' => 'zyanose']],
        'b_symptome' => ['enum', 3, ['catalog' => 'b_symptome']], 'b_auskult' => ['enum', 3, ['catalog' => 'b_auskult']],
        'c_kreislauf' => ['enum', 3, ['catalog' => 'c_kreislauf']], 'c_ekg' => ['enum', 3, ['catalog' => 'c_ekg']],
        'c_puls_reg' => ['enum', 3, ['catalog' => 'c_puls_reg']], 'c_puls_rad' => ['enum', 3, ['catalog' => 'c_puls_rad']],
        'c_rekap' => ['enum', 3, ['catalog' => 'c_rekap']], 'c_blutung' => ['enum', 3, ['catalog' => 'c_blutung']],
        'd_bewusstsein' => ['enum', 3, ['catalog' => 'd_bewusstsein']],
        'd_pupillenw_1' => ['enum', 3, ['catalog' => 'd_pupillenweite']], 'd_pupillenw_2' => ['enum', 3, ['catalog' => 'd_pupillenweite']],
        'd_lichtreakt_1' => ['enum', 3, ['catalog' => 'd_lichtreaktion']], 'd_lichtreakt_2' => ['enum', 3, ['catalog' => 'd_lichtreaktion']],
        'd_gcs_1' => ['enum', 3, ['catalog' => 'd_gcs_augen']], 'd_gcs_2' => ['enum', 3, ['catalog' => 'd_gcs_verbal']],
        'd_gcs_3' => ['enum', 3, ['catalog' => 'd_gcs_motorik']],
        'd_ex_1' => ['enum', 3, ['catalog' => 'd_extremitaeten']],
        'v_muster_k' => self::SEVERITY, 'v_muster_k1' => self::INJURY, 'v_muster_t' => self::SEVERITY, 'v_muster_t1' => self::INJURY,
        'v_muster_a' => self::SEVERITY, 'v_muster_a1' => self::INJURY, 'v_muster_al' => self::SEVERITY, 'v_muster_al1' => self::INJURY,
        'v_muster_bl' => self::SEVERITY, 'v_muster_bl1' => self::INJURY, 'v_muster_w' => self::SEVERITY, 'v_muster_w1' => self::INJURY,
        'sz_nrs' => ['enum', 3, ['catalog' => 'sz_nrs']], 'sz_toleranz_1' => ['enum', 3, ['catalog' => 'sz_toleranz']],
        'psych' => ['json', 3, ['shape' => 'ints', 'catalog' => 'psych']],
        'spo2' => ['decimal', 3, ['max' => 100, 'specials' => self::SPECIALS]], 'atemfreq' => ['decimal', 3, ['max' => 200, 'specials' => self::SPECIALS]],
        'etco2' => ['decimal', 3, ['max' => 200, 'specials' => self::SPECIALS]], 'rrsys' => ['decimal', 3, ['max' => 400, 'specials' => self::SPECIALS]],
        'rrdias' => ['decimal', 3, ['max' => 400, 'specials' => self::SPECIALS]], 'herzfreq' => ['decimal', 3, ['max' => 400, 'specials' => self::SPECIALS]],
        'bz' => ['decimal', 3, ['max' => 2000, 'specials' => self::SPECIALS]], 'temp' => ['decimal', 3, ['max' => 50, 'specials' => self::SPECIALS]],
        'diagnose_haupt' => ['enum', 4, ['catalog' => 'diagnose']],
        'diagnose_weitere' => ['json', 4, ['shape' => 'ints', 'catalog' => 'diagnose']],
        'diagnose' => ['text', 4, ['max' => 10000]],
        'naca_initial' => ['enum', 4, ['catalog' => 'naca']],
        'awsicherung_1' => ['bool', 5], 'awsicherung_2' => ['bool', 5], 'awsicherung_neu' => ['enum', 5, ['catalog' => 'awsicherung_neu']],
        'entlastungspunktion' => ['bool', 5], 'hws_immo' => ['bool', 5],
        'o2gabe' => ['enum', 5, ['catalog' => 'o2gabe']], 'b_beatmung' => ['enum', 5, ['catalog' => 'b_beatmung']],
        'c_zugang' => ['json', 5, ['shape' => 'objects', 'keys' => ['art', 'groesse', 'ort', 'seite'],
            'key_catalogs' => ['art' => 'zugang_art', 'groesse' => 'zugang_groesse', 'ort' => 'zugang_ort', 'seite' => 'zugang_seite']]],
        'lagerung' => ['enum', 5, ['catalog' => 'lagerung']], 'rettungstechnik' => ['json', 5, ['shape' => 'ints', 'catalog' => 'rettungstechnik']],
        'waerme_passiv' => ['bool', 5], 'waerme_aktiv' => ['bool', 5],
        'e_reposition' => ['bool', 5], 'e_verband' => ['bool', 5], 'e_krintervention' => ['bool', 5],
        'e_kuehlung' => ['bool', 5], 'e_narkose' => ['bool', 5], 'e_tourniquet' => ['bool', 5], 'e_cpr' => ['bool', 5],
        'rea_status' => ['enum', 5, ['catalog' => 'rea_status']], 'rea_ursache' => ['enum', 5, ['catalog' => 'rea_ursache']],
        'rea_sport' => ['enum', 5, ['catalog' => 'rea_ja_nein']], 'rea_fr_eintreffen' => ['time', 5],
        'rea_kollaps' => ['enum', 5, ['catalog' => 'rea_kollaps']], 'rea_hdm_durch' => ['enum', 5, ['catalog' => 'rea_hdm_durch']],
        'rea_hdm_zeit' => ['time', 5], 'rea_defi' => ['enum', 5, ['catalog' => 'rea_defi']], 'rea_rosc' => ['enum', 5, ['catalog' => 'rea_rosc']],
        'rea_kh_aufnahme' => ['enum', 5, ['catalog' => 'rea_kh_aufnahme']], 'rea_tod_zeit' => ['time', 5], 'rea_erfolglos' => ['int', 5, ['max' => 1]],
        'medis_keine' => ['bool', 5],
        'uebergabe_ort' => ['enum', 7, ['catalog' => 'uebergabe_ort']],
        'uebergabe_an' => ['enum', 7, ['catalog' => 'uebergabe_an']],
        'naca_uebergabe' => ['enum', 7, ['catalog' => 'naca']],
        'na_nachf' => ['bool', 7],
        'ebesonderheiten' => ['json', 7, ['shape' => 'ints', 'catalog' => 'ebesonderheiten']],
        'pfname' => ['text', 7],
    ];

    /**
     * SHARE_FIELDS: what a share request copies. Allowlist of the clinical sections anamnese
     * (patient header included), erstbefund, diagnose, massnahmen plus the scene. Never the vehicle's own workflow (mission
     * date/time, status times, special rights, vehicle labels, protocol type, destination, handover, protocol writer), never
     * lifecycle/QM/billing (not FieldSchema fields at all), never medis_keine (the given medication rows are not copied).
     */
    public const SHARE_SECTIONS = [2, 3, 4, 5];

    public const SHARE_EXTRA = ['eart', 'transp_poi', 'transp_adresse'];

    public const SHARE_EXCLUDED = ['medis_keine'];

    /** @return list<string> */
    public static function shareKeys(): array
    {
        $keys = self::SHARE_EXTRA;
        foreach (self::SHARE_SECTIONS as $section) {
            array_push($keys, ...self::sections()[$section]);
        }

        return array_values(array_diff($keys, self::SHARE_EXCLUDED));
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::FIELDS);
    }

    /** @return array{type: string, section: int, column: ?string, max: ?int, catalog: ?string, shape: ?string, keys: list<string>, key_catalogs: array<string, string>, specials: list<string>}|null */
    public static function field(string $key): ?array
    {
        if (! isset(self::FIELDS[$key])) {
            return null;
        }
        $f = self::FIELDS[$key];
        $o = $f[2] ?? [];

        return ['type' => $f[0], 'section' => $f[1], 'column' => $o['column'] ?? null, 'max' => $o['max'] ?? ($f[0] === 'text' ? 255 : null),
            'catalog' => $o['catalog'] ?? null, 'shape' => $o['shape'] ?? null, 'keys' => $o['keys'] ?? [], 'key_catalogs' => $o['key_catalogs'] ?? [],
            'specials' => $o['specials'] ?? []];
    }

    /**
     * What the protocol page renders: per section slug its fields in form order (the client reads the catalogs from the same JSON).
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public static function client(): array
    {
        $out = array_fill_keys(self::SECTIONS, []);
        foreach (self::keys() as $key) {
            $f = (array) self::field($key);
            $out[self::SECTIONS[$f['section']]][] = ['key' => $key] + array_intersect_key($f, array_flip(['type', 'max', 'catalog', 'shape', 'keys', 'key_catalogs', 'specials']));
        }

        return $out;
    }

    /** @return array<int, list<string>> section => keys */
    public static function sections(): array
    {
        $out = [];
        foreach (self::FIELDS as $key => $f) {
            $out[$f[1]][] = $key;
        }
        ksort($out);

        return $out;
    }

    /** @return array<string, string> legacy key => header column of enotf_protocols */
    public static function headerKeys(): array
    {
        return array_filter(array_map(fn (array $f) => $f[2]['column'] ?? null, self::FIELDS));
    }

    /** @return list<string> keys stored in the `data` JSON */
    public static function dataKeys(): array
    {
        return array_values(array_diff(self::keys(), array_keys(self::headerKeys())));
    }

    /**
     * Allowlist, typing and trimming. '' and null clear a field (value null). Messages never repeat the input.
     *
     * @param  array<array-key, mixed>  $fields
     * @return array{values: array<string, mixed>, errors: array<string, string>}
     */
    public static function validate(array $fields): array
    {
        $values = $errors = [];
        foreach ($fields as $key => $raw) {
            $f = self::field((string) $key);
            if ($f === null) {
                $errors[(string) $key] = __('enotf.field.unknown');

                continue;
            }
            if (is_string($raw)) {
                $raw = trim($raw);
            }
            if ($raw === null || $raw === '' || ($raw === [] && $f['type'] !== 'json')) {
                $values[$key] = null;

                continue;
            }
            if ($f['type'] === 'text' && is_string($raw) && mb_strlen($raw) > $f['max']) {
                $errors[$key] = __('enotf.field.too_long', ['max' => $f['max']]);

                continue;
            }
            $value = self::cast($f, $raw);
            if ($value === null) {
                $errors[$key] = __('enotf.field.invalid');

                continue;
            }
            $values[$key] = $value;
        }

        return ['values' => $values, 'errors' => $errors];
    }

    /**
     * The typed value, null when invalid.
     *
     * @param  array{type: string, max: ?int, catalog: ?string, shape: ?string, keys: list<string>, key_catalogs: array<string, string>, specials: list<string>}  $f
     */
    private static function cast(array $f, mixed $v): mixed
    {
        if (is_string($v) && in_array(strtolower($v), $f['specials'], true)) {
            return strtolower($v);
        }

        return match ($f['type']) {
            'text' => is_string($v) || is_int($v) || is_float($v) ? (string) $v : null,
            'int' => self::int($v, 0, $f['max'] ?? PHP_INT_MAX),
            'decimal' => self::decimal($v, 0, $f['max'] ?? PHP_INT_MAX),
            'bool' => match (true) {
                is_bool($v) => $v,
                in_array($v, [0, 1, '0', '1'], true) => (bool) (int) $v,
                default => null,
            },
            'date' => self::date($v),
            'time' => is_string($v) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v) ? $v : null,
            'datetime' => self::datetime($v),
            'enum' => self::code($v, $f['catalog'] ?? ''),
            'json' => self::json($v, $f),
            default => null,
        };
    }

    private static function int(mixed $v, int $min, int $max): ?int
    {
        if (is_string($v) && preg_match('/^-?\d{1,9}$/', $v)) {
            $v = (int) $v;
        }

        return is_int($v) && $v >= $min && $v <= $max ? $v : null;
    }

    private static function decimal(mixed $v, int $min, int $max): int|float|null
    {
        if (is_string($v) && preg_match('/^-?\d{1,6}([.,]\d{1,3})?$/', $v)) {
            $v = str_contains($v, ',') || str_contains($v, '.') ? (float) str_replace(',', '.', $v) : (int) $v;
        }

        return (is_int($v) || is_float($v)) && is_finite((float) $v) && $v >= $min && $v <= $max ? $v : null;
    }

    private static function date(mixed $v): ?string
    {
        if (! is_string($v)) {
            return null;
        }
        if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $v, $m)) {
            [$y, $mo, $d] = [(int) $m[3], (int) $m[2], (int) $m[1]];
        } elseif (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m)) {
            [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } else {
            return null;
        }

        return $y >= 1900 && $y <= 2100 && checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
    }

    private static function datetime(mixed $v): ?string
    {
        if (! is_string($v) || ! preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2})?(\.\d+)?(Z|[+-]\d{2}:?\d{2})?$/', $v)) {
            return null;
        }
        try {
            $at = CarbonImmutable::parse($v, (string) config('ignis.timezone'));
        } catch (Throwable) {
            return null;
        }

        return $at->year >= 2000 && $at->year <= 2100 ? $at->utc()->format('Y-m-d\TH:i:s\Z') : null;
    }

    private static function code(mixed $v, string $catalog): int|string|null
    {
        foreach (array_keys(EnotfCatalogs::get($catalog)) as $code) {
            if ((is_int($v) || is_string($v)) && (string) $code === (string) $v) {
                return $code;
            }
        }

        return null;
    }

    /**
     * @param  array{shape: ?string, catalog: ?string, keys: list<string>, key_catalogs: array<string, string>}  $f
     * @return array<array-key, mixed>|null
     */
    private static function json(mixed $v, array $f): ?array
    {
        if (! is_array($v) || strlen((string) json_encode($v)) > self::JSON_MAX_BYTES) {
            return null;
        }
        $text = fn (mixed $s) => is_string($s) && mb_strlen($s) <= 255 ? trim($s) : (is_int($s) ? (string) $s : null);

        switch ($f['shape']) {
            case 'ints':
                $out = [];
                foreach ($v as $item) {
                    $item = $f['catalog'] !== null ? self::code($item, $f['catalog']) : self::int($item, 0, 9999);
                    if ($item === null) {
                        return null;
                    }
                    $out[] = $item;
                }

                return array_values(array_unique($out));
            case 'object':
                return self::object($v, $f['keys'], $text, $f['key_catalogs']);
            case 'objects':
                $out = [];
                foreach ($v as $item) {
                    $item = is_array($item) ? self::object($item, $f['keys'], $text, $f['key_catalogs']) : null;
                    if ($item === null) {
                        return null;
                    }
                    $out[] = $item;
                }

                return $out;
        }

        return null;
    }

    /**
     * @param  array<array-key, mixed>  $v
     * @param  list<string>  $keys
     * @param  array<string, string>  $catalogs  object key => catalog its value must come from
     * @return array<string, ?string>|null
     */
    private static function object(array $v, array $keys, callable $text, array $catalogs = []): ?array
    {
        if (array_is_list($v) && $v !== [] || array_diff(array_keys($v), $keys) !== []) {
            return null;
        }
        $out = [];
        foreach ($v as $k => $s) {
            $s = $s === null ? '' : $text($s);
            if ($s === null || ($s !== '' && isset($catalogs[$k]) && self::code($s, $catalogs[$k]) === null)) {
                return null;
            }
            $out[(string) $k] = $s === '' ? null : $s;
        }

        return $out;
    }
}
