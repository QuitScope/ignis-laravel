<?php

namespace Modules\Enotf\Support;

use Illuminate\Support\Facades\DB;

/**
 * The 47 catalog seeds of the legacy migration 20250607000061 (verbatim; doses CSV -> trimmed JSON list).
 * Idempotent by substance: existing entries stay untouched.
 */
final class MedicationSeeds
{
    public const SEEDS = [
        ['Acetylsalicylsäure', 'ASS', '100 mg,250 mg,500 mg', 1],
        ['Adenosin', null, '6 mg,12 mg', 2],
        ['Alteplase', 'Actilyse', '10 mg,50 mg', 3],
        ['Amiodaron', 'Cordarex', '150 mg,300 mg', 4],
        ['Atropinsulfat', 'Atropin', '0.5 mg,1 mg', 5],
        ['Butylscopolamin', 'Buscopan', '20 mg', 6],
        ['Calciumgluconat', null, '1 g,2 g', 7],
        ['Ceftriaxon', 'Rocephin', '1 g,2 g', 8],
        ['Dimenhydrinat', 'Vomex', '62 mg,150 mg', 9],
        ['Dimetinden', 'Fenistil', '4 mg', 10],
        ['Epinephrin', 'Adrenalin', '0.1 mg,0.3 mg,0.5 mg,1 mg', 11],
        ['Esketamin', 'Ketanest S', '0.125 mg,0.25 mg,0.5 mg', 12],
        ['Fentanyl', null, '0.05 mg,0.1 mg,0.2 mg', 13],
        ['Flumazenil', 'Anexate', '0.2 mg,0.5 mg', 14],
        ['Furosemid', 'Lasix', '20 mg,40 mg,80 mg', 15],
        ['Gelatinepolysuccinat', 'Gelafundin', '500 ml,1000 ml', 16],
        ['Glukose', null, '10 g,20 g,40 g', 17],
        ['Heparin', null, '5000 IE,10000 IE', 18],
        ['Humanblut', null, null, 19],
        ['Hydroxycobolamin', 'Cyanokit', '5 g', 20],
        ['Ipratropiumbromid', 'Atrovent', '0.25 mg,0.5 mg', 21],
        ['Lidocain', 'Xylocain', '50 mg,100 mg', 22],
        ['Lorazepam', 'Tavor', '1 mg,2 mg,4 mg', 23],
        ['Metamizol', 'Novalgin', '1 g,2.5 g', 24],
        ['Metoprolol', 'Beloc', '5 mg', 25],
        ['Midazolam', 'Dormicum', '1 mg,2.5 mg,5 mg', 26],
        ['Morphin', null, '2 mg,5 mg,10 mg', 27],
        ['Naloxon', 'Narcanti', '0.4 mg,0.8 mg', 28],
        ['Natriumhydrogencarbonat', null, '50 ml,100 ml', 29],
        ['Natriumthiosulfat', null, '12.5 g', 30],
        ['Nitroglycerin', 'Nitrolingual', '0.4 mg,0.8 mg', 31],
        ['Norepinephrin', 'Arterenol', '0.05 mg,0.1 mg,1 mg', 32],
        ['Ondansetron', 'Zofran', '4 mg,8 mg', 33],
        ['Paracetamol', 'Perfalgan', '500 mg,1000 mg', 34],
        ['Piritramid', 'Dipidolor', '3.75 mg,7.5 mg,15 mg', 35],
        ['Prednisolon', 'Solu-Decortin', '25 mg,50 mg,100 mg,250 mg', 36],
        ['Propofol', 'Disoprivan', '20 mg,50 mg,100 mg,200 mg', 37],
        ['Reproterol', 'Bronchospasmin', '0.09 mg', 38],
        ['Rocuronium', 'Esmeron', '50 mg,100 mg', 39],
        ['Salbutamol', 'Sultanol', '0.1 mg,0.2 mg,2.5 mg,5 mg', 40],
        ['Succinylcholin', 'Lysthenon', '100 mg', 41],
        ['Sufentanil', null, '5 mcg,10 mcg,25 mcg', 42],
        ['Theodrenalin-Cafedrin', 'Akrinor', '0.5 ml,1 ml,2 ml', 43],
        ['Thiopental', 'Trapanal', '250 mg,500 mg', 44],
        ['Tranexamsäure', 'Cyklokapron', '1 g', 45],
        ['Urapidil', 'Ebrantil', '12.5 mg,25 mg,50 mg', 46],
        ['Vollelektrolytlösung', null, '250 ml,500 ml,1000 ml', 47],
    ];

    /**
     * Legacy dosierungen (comma list in TEXT) -> JSON list, trimmed, empties dropped.
     *
     * @return list<string>
     */
    public static function doses(?string $csv): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $csv)), fn (string $d) => $d !== ''));
    }

    public static function seed(): void
    {
        $now = now();
        DB::table('enotf_medications')->insertOrIgnore(array_map(fn (array $s) => [
            'substance' => $s[0], 'brand' => $s[1], 'doses' => json_encode(self::doses($s[2]), JSON_UNESCAPED_UNICODE),
            'sort' => $s[3], 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ], self::SEEDS));
    }
}
