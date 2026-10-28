<?php

use App\Auth\Permissions;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Enotf\Support\EnotfCatalogs;
use Modules\Enotf\Support\FieldSchema;

uses(RefreshDatabase::class);

it('answers 404 with the module off and lists rights and settings only while on', function () {
    $user = User::factory()->create();
    $settings = app(Settings::class);
    expect(Permissions::groups()['rettung'] ?? null)->toBe(['edivi.view', 'edivi.edit', 'enotf.view', 'enotf.settings'])
        ->and(Permissions::moduleOf('edivi.view'))->toBe('enotf')
        ->and($settings->get('enotf.pin_timeout'))->toBe(5)
        ->and($settings->get('enotf.bz_unit'))->toBe('mg/dl')
        ->and($settings->get('enotf.personal_data_months'))->toBe(12);
    $this->actingAs($user)->get('/enotf')->assertRedirect('/enotf/login')->assertHeader('Cache-Control', 'no-store, private');

    $settings->set('modules.enotf.enabled', false);
    $this->actingAs($user)->get('/enotf')->assertNotFound();
    expect(Permissions::groups())->not->toHaveKey('rettung')
        ->and(Permissions::hidden('edivi.edit'))->toBeTrue();
});

it('types one field per type, clears empty values and rejects unknown fields', function () {
    config(['ignis.timezone' => 'Europe/Berlin']);
    $r = FieldSchema::validate([
        'pat_vorname' => '  Max ', 'd_gcs_1' => '3', 'temp' => '36,8', 'na_nachf' => '1', 'patgebdat' => '01.02.1990',
        'ezeit' => '12:30', 'salarm' => '2026-10-23 14:05', 'transportziel' => '21', 'psych' => [2, '3'],
        'ziel_adresse' => ['strasse' => ' Hauptstr ', 'ort' => 'LS'], 'diagnose' => '', 'patid' => 'x',
    ]);

    expect($r['errors'])->toBe(['patid' => __('enotf.field.unknown')])
        ->and($r['values'])->toMatchArray(['pat_vorname' => 'Max', 'd_gcs_1' => 3, 'temp' => 36.8, 'na_nachf' => true,
            'patgebdat' => '1990-02-01', 'ezeit' => '12:30', 'transportziel' => 21, 'psych' => [2, 3],
            'ziel_adresse' => ['strasse' => 'Hauptstr', 'ort' => 'LS'], 'diagnose' => null])
        ->and($r['values']['salarm'])->toEndWith('Z');

    $bad = FieldSchema::validate(['d_gcs_1' => '100', 'temp' => 'abc', 'na_nachf' => 'yes', 'patgebdat' => '31.02.1990',
        'ezeit' => '25:00', 'salarm' => 'gestern', 'transportziel' => '7', 'psych' => 'x', 'ziel_adresse' => ['plz' => '1'],
        'pfname' => str_repeat('a', 256), 'ebesonderheiten' => [1, 50]]);
    expect(array_keys($bad['errors']))->toBe(['d_gcs_1', 'temp', 'na_nachf', 'patgebdat', 'ezeit', 'salarm', 'transportziel', 'psych', 'ziel_adresse', 'pfname', 'ebesonderheiten'])
        ->and($bad['values'])->toBe([]);
});

it('keeps the schema consistent and reads the catalogs from the shared JSON', function () {
    $json = json_decode((string) file_get_contents(base_path(EnotfCatalogs::PATH)), true);

    expect(EnotfCatalogs::all())->toBe(array_map(fn ($pairs) => array_column($pairs, 1, 0), $json))
        ->and(array_keys(EnotfCatalogs::get('transportziel')))->toBe([1, 2, 21, 22, 3, 4, 5, 6, 99])
        ->and(EnotfCatalogs::label('uebergabe_ort', 10))->toBe('Herzkatheterlabor')
        ->and(array_intersect(FieldSchema::keys(), FieldSchema::DEAD))->toBe([])
        ->and(count(FieldSchema::DEAD))->toBe(20)
        ->and(array_merge(...array_values(FieldSchema::sections())))->toEqualCanonicalizing(FieldSchema::keys())
        ->and(FieldSchema::headerKeys()['pat_nachname'])->toBe('patient_last_name')
        ->and(FieldSchema::dataKeys())->not->toContain('pat_nachname');
});
