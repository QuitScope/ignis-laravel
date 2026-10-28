<?php

use Modules\Enotf\Support\ConditionsService;
use Modules\Enotf\Support\FieldSchema;

// Same fixture as tests/js/enotfConditions.test.ts (21 legacy cases + edge cases).
$fixture = json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/enotf-conditions.json'), true);

it('evaluates like the TS port', function (array $c) use ($fixture) {
    $values = isset($c['extends']) ? array_diff_key(($c['set'] ?? []) + $fixture[$c['extends']], array_flip($c['unset'] ?? [])) : $c['values'];
    $violations = ConditionsService::evaluate($values);
    $tz = isset($values['transportziel']) ? (int) $values['transportziel'] : null;

    if (isset($c['violations'])) {
        $got = array_map(fn ($v) => "{$v['rule']}:{$v['field']}", $violations);
        sort($got);
        $want = $c['violations'];
        sort($want);
        expect($got)->toBe($want);
    }
    if (isset($c['violation_count'])) {
        expect($violations)->toHaveCount($c['violation_count']);
    }
    foreach ($c['counts'] ?? [] as $section => $n) {
        expect(count(array_filter($violations, fn ($v) => $v['section'] === $section)))->toBe($n, $section);
    }
    if (isset($c['active_count'])) {
        expect(ConditionsService::activeRules($tz))->toHaveCount($c['active_count']);
    }
    if (isset($c['active'])) {
        expect(array_keys(ConditionsService::activeRules($tz)))->toEqualCanonicalizing($c['active']);
    }
    $status = ConditionsService::sectionStatus($values);
    foreach ($c['sections'] ?? [] as $section => $want) {
        expect(is_string($want) ? $status[$section]['status'] : $status[$section])->toBe($want, $section);
    }
    if (isset($c['section_order'])) {
        expect(array_keys($status))->toBe($c['section_order']);
    }
})->with(array_combine(array_column($fixture['cases'], 'name'), array_map(fn (array $c) => [$c], $fixture['cases'])));

it('puts every rule in the page section of its field', function () {
    foreach ([null, 2] as $tz) {
        foreach (ConditionsService::activeRules($tz) as $rule => [$section, $field]) {
            $f = FieldSchema::field($field);
            expect($f === null ? 'massnahmen' : FieldSchema::SECTIONS[$f['section']])->toBe($section, $rule);
        }
    }
});
