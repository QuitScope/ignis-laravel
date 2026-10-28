<?php

use Modules\Enotf\Support\ConditionsService;
use Modules\Enotf\Support\EnotfCatalogs;
use Modules\Enotf\Support\FieldSchema;

it('places every schema field exactly once and only known keys', function () {
    $layout = json_decode((string) file_get_contents(resource_path('js/lib/enotfLayout.json')), true, flags: JSON_THROW_ON_ERROR);
    $placed = array_merge(...array_map(fn (array $p) => $p['keys'], $layout['pages']));
    $unplaced = array_keys($layout['unplaced']);

    expect(array_column($layout['pages'], 'slug'))->toBe(['rettdaten', 'erstbefund', 'anamnese', 'diagnose', 'verlauf', 'massnahmen', 'abschluss'])
        ->and(array_diff_assoc($placed, array_unique($placed)))->toBe([])
        ->and(array_values(array_diff(FieldSchema::keys(), $placed, $unplaced)))->toBe([])
        ->and(array_values(array_diff($placed, FieldSchema::keys(), $layout['pseudo'])))->toBe([])
        ->and(array_intersect($placed, $unplaced))->toBe([])
        ->and(array_values(array_diff($unplaced, FieldSchema::keys())))->toBe([])
        ->and(array_values(array_diff($layout['pseudo'], array_keys(ConditionsService::activeRules(null)))))->toBe([]);
});

it('renders or writes every placed field on its page (the generic field fallback is gone)', function () {
    $layout = json_decode((string) file_get_contents(resource_path('js/lib/enotfLayout.json')), true, flags: JSON_THROW_ON_ERROR);
    $dynamic = ['v_muster_k1', 'v_muster_t1', 'v_muster_a1', 'v_muster_al1', 'v_muster_bl1', 'v_muster_w1', 'medis'];
    $base = resource_path('js/components/enotf');
    $sources = function (string $file, array $seen = []) use (&$sources, $base): array {
        $seen[$file] = true;
        preg_match_all("#from '@/components/enotf/([\w/]+\.vue)'#", (string) file_get_contents($file), $m);
        foreach ($m[1] as $rel) {
            if (! isset($seen["{$base}/{$rel}"])) {
                $seen = $sources("{$base}/{$rel}", $seen);
            }
        }

        return $seen;
    };

    foreach ($layout['pages'] as $page) {
        $code = implode("\n", array_map('file_get_contents', array_keys($sources("{$base}/pages/".ucfirst($page['slug']).'.vue'))));
        $missing = array_filter($page['keys'], fn (string $k) => ! in_array($k, $dynamic, true) && preg_match("#['\"`]{$k}['\"`]#", $code) !== 1);
        expect(array_values($missing))->toBe([], "page {$page['slug']}");
    }
});

it('has a diagnosis tree covering exactly the diagnosis codes, each once (legacy DiagnoseCatalog::CATEGORIES)', function () {
    $layout = json_decode((string) file_get_contents(resource_path('js/lib/enotfLayout.json')), true, flags: JSON_THROW_ON_ERROR);
    $codes = [];
    foreach ($layout['diagnose_tree'] as $cat) {
        foreach ($cat['subs'] ?? [] as $sub) {
            array_push($codes, ...$sub['codes']);
        }
        foreach ($cat['cols'] ?? [] as $col) {
            array_push($codes, ...$col);
        }
    }
    $sorted = $codes;
    sort($sorted);

    expect(count($layout['diagnose_tree']))->toBe(8)
        ->and(count($layout['diagnose_tree'][7]['subs']))->toBe(11)
        ->and($codes)->toHaveCount(count(array_unique($codes)))
        ->and($sorted)->toBe(array_keys(EnotfCatalogs::get('diagnose')));
});

it('takes the codes the legacy form offers (patsex 9 "unbekannt"; ng/nm for the first-finding vitals)', function () {
    $vitals = ['spo2' => 'ng', 'atemfreq' => 'NM', 'etco2' => 'nm', 'rrsys' => 'ng', 'rrdias' => '80', 'herzfreq' => 'ng', 'bz' => 'nm', 'temp' => '36,5'];
    $ok = FieldSchema::validate(['patsex' => '9'] + $vitals);
    $messwerte = fn (array $v) => in_array('messwerte', array_column(ConditionsService::evaluate($v), 'rule'), true);

    expect($ok['errors'])->toBe([])
        ->and($ok['values'])->toMatchArray(['patsex' => 9, 'spo2' => 'ng', 'atemfreq' => 'nm', 'rrdias' => 80, 'temp' => 36.5])
        ->and(FieldSchema::validate(['patsex' => 7, 'spo2' => 'xx', 'pfname' => 'ng'])['errors'])->toHaveKeys(['patsex', 'spo2'])
        ->and(FieldSchema::validate(['edatum' => 'ng'])['errors'])->toHaveKey('edatum')
        ->and($messwerte(['transportziel' => 2]))->toBeTrue()
        ->and($messwerte(['transportziel' => 2] + $ok['values']))->toBeFalse();
});
