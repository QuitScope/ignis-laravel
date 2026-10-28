<?php

namespace Modules\Enotf\Support;

use App\Support\Settings;

/**
 * Vital sign parameters (legacy Crew/Catalogs/VitalparameterCatalog.php): code => legacy display name and storage unit.
 * Blood sugar is stored in mg/dl and shown in the setting enotf.bz_unit (legacy BloodSugarHelper, factor 0.0555).
 * 'ng' (nicht gemessen) and 'nm' (nicht messbar) are kept as text, never as 0 (legacy stored 0).
 * The colour grading of the keypad lives in resources/js/lib/enotfVitals.ts (legacy vitals-keypad.js).
 */
final class VitalparameterCatalog
{
    /** @var array<string, array{name: string, unit: string}> */
    public const PARAMETERS = [
        'spo2' => ['name' => 'SpO₂', 'unit' => '%'],
        'atemfreq' => ['name' => 'Atemfrequenz', 'unit' => '/min'],
        'etco2' => ['name' => 'etCO₂', 'unit' => 'mmHg'],
        'herzfreq' => ['name' => 'Herzfrequenz', 'unit' => '/min'],
        'rrsys' => ['name' => 'RR systolisch', 'unit' => 'mmHg'],
        'rrdias' => ['name' => 'RR diastolisch', 'unit' => 'mmHg'],
        'bz' => ['name' => 'Blutzucker', 'unit' => 'mg/dl'],
        'temp' => ['name' => 'Temperatur', 'unit' => '°C'],
        'bemerkung' => ['name' => 'Bemerkung', 'unit' => ''],
    ];

    public const NOT_MEASURED = ['ng', 'nm'];

    public const BZ_UNITS = ['mg/dl', 'mmol/l'];

    private const MG_TO_MMOL = 0.0555;

    public static function fromLegacyName(string $name): ?string
    {
        foreach (self::PARAMETERS as $code => $p) {
            if ($p['name'] === $name) {
                return $code;
            }
        }

        return null;
    }

    /**
     * One entered value -> [value, value_text] for storage, null when it is not acceptable. Decimal comma or point;
     * blood sugar arrives in $bzUnit and is stored in mg/dl; 'ng'/'nm' and remarks become text.
     *
     * @return array{0: ?float, 1: ?string}|null
     */
    public static function parse(string $code, string $raw, string $bzUnit = 'mg/dl'): ?array
    {
        $raw = trim($raw);
        if ($code === 'bemerkung') {
            return $raw === '' || mb_strlen($raw) > 50 ? null : [null, $raw];
        }
        if (in_array(strtolower($raw), self::NOT_MEASURED, true)) {
            return [null, strtolower($raw)];
        }
        if (! preg_match('/^\d{1,4}([.,]\d{1,2})?$/D', $raw)) {
            return null;
        }
        $value = (float) str_replace(',', '.', $raw);

        return [$code === 'bz' ? self::bzToMgdl($value, $bzUnit) : $value, null];
    }

    public static function bzToMgdl(float $value, string $unit): float
    {
        return round($unit === 'mmol/l' ? $value / self::MG_TO_MMOL : $value);
    }

    public static function bzFromMgdl(float $mgdl, string $unit): float
    {
        return $unit === 'mmol/l' ? round($mgdl * self::MG_TO_MMOL, 1) : round($mgdl);
    }

    public static function format(float $value): string
    {
        return str_replace('.', ',', rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.'));
    }

    public static function bzUnit(): string
    {
        $unit = (string) app(Settings::class)->get('enotf.bz_unit', 'mg/dl');

        return in_array($unit, self::BZ_UNITS, true) ? $unit : 'mg/dl';
    }
}
