<?php

use App\Documents\VariableCatalog;
use App\Models\Document;
use App\Models\EmsQualification;
use App\Models\FireQualification;
use App\Models\Personnel;
use App\Models\PersonnelTitle;
use App\Models\Rank;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function catalogPerson(int $gender, array $attrs = []): Personnel
{
    $rank = Rank::create(['priority' => 1, 'name' => 'Brandmeister/-in', 'name_male' => 'Brandmeister', 'name_female' => 'Brandmeisterin', 'is_archive' => false]);
    $fire = FireQualification::create(['priority' => 1, 'short_name' => 'TM', 'name' => 'Truppmitglied', 'name_male' => 'Truppmann', 'name_female' => 'Truppfrau', 'is_none' => false]);
    $ems = EmsQualification::create(['priority' => 1, 'name' => 'Rettungssanitäter/-in', 'name_male' => 'Rettungssanitäter', 'name_female' => 'Rettungssanitäterin', 'is_none' => false]);

    return Personnel::factory()->create($attrs + [
        'full_name' => 'Testperson', 'gender' => $gender, 'birth_date' => '1990-01-02', 'hired_at' => '2020-03-04',
        'rank_id' => $rank->id, 'fire_qualification_id' => $fire->id, 'ems_qualification_id' => $ems->id,
    ]);
}

function catalogOrganisation(): void
{
    $settings = app(Settings::class);
    foreach (['name' => 'Wache 1', 'type' => 'Berufsfeuerwehr', 'city' => 'Musterstadt', 'street' => 'Hauptstraße 1', 'zip' => '12345'] as $k => $v) {
        $settings->set('organisation.'.$k, $v);
    }
}

beforeEach(fn () => Carbon::setTestNow('2026-10-18 12:00:00'));

it('resolves every catalogue key from document, personnel, issuer and settings', function () {
    catalogOrganisation();
    $title = PersonnelTitle::create(['name' => 'Dr.', 'priority' => 1]);
    $employee = catalogPerson(0, ['title_id' => $title->id, 'service_number' => 'T-1']);
    $issuer = catalogPerson(1, ['full_name' => 'Ausstellerin', 'service_number' => 'T-2', 'extra_qualification' => 'Wachleitung']);
    $this->actingAs(User::factory()->create(['personnel_id' => $issuer->id]));
    $document = Document::factory()->create(['personnel_id' => $employee->id, 'public_id' => '0012345']);

    expect(VariableCatalog::resolve($document))->toBe([
        'mitarbeiter.name' => 'Dr. Testperson',
        'mitarbeiter.titel' => 'Dr.',
        'mitarbeiter.dienstgrad' => 'Brandmeister',
        'mitarbeiter.qualifikation_fw' => 'Truppmann',
        'mitarbeiter.qualifikation_rd' => 'Rettungssanitäter',
        'mitarbeiter.dienstnummer' => 'T-1',
        'mitarbeiter.geburtsdatum' => '02.01.1990',
        'mitarbeiter.eintritt' => '04.03.2020',
        'mitarbeiter.anrede' => 'Herr',
        'mitarbeiter.briefanrede' => 'Sehr geehrter Herr',
        'mitarbeiter.ihm_ihr' => 'ihm',
        'mitarbeiter.seine_ihre' => 'seine',
        'mitarbeiter.zum_zur' => 'zum',
        'aussteller.name' => 'Ausstellerin',
        'aussteller.dienstgrad' => 'Brandmeisterin',
        'aussteller.zusatz' => 'Wachleitung',
        'organisation.name' => 'Wache 1',
        'organisation.art' => 'Berufsfeuerwehr',
        'organisation.stadt' => 'Musterstadt',
        'organisation.strasse' => 'Hauptstraße 1',
        'organisation.plz' => '12345',
        'dokument.kennung' => '0012345',
        'datum' => '18.10.2026',
    ])
        ->and(VariableCatalog::missingReasons($document))->toBe(['aussteller.titel' => 'In deinem Mitarbeiterdatensatz ist kein Titel eingetragen.']);
});

it('uses female forms for gender 1 and the neutral forms for gender 2', function () {
    expect(array_intersect_key(VariableCatalog::resolve(['personnel' => catalogPerson(1)]), array_flip([
        'mitarbeiter.anrede', 'mitarbeiter.briefanrede', 'mitarbeiter.ihm_ihr', 'mitarbeiter.seine_ihre', 'mitarbeiter.zum_zur', 'mitarbeiter.dienstgrad',
    ])))->toBe([
        'mitarbeiter.dienstgrad' => 'Brandmeisterin',
        'mitarbeiter.anrede' => 'Frau',
        'mitarbeiter.briefanrede' => 'Sehr geehrte Frau',
        'mitarbeiter.ihm_ihr' => 'ihr',
        'mitarbeiter.seine_ihre' => 'ihre',
        'mitarbeiter.zum_zur' => 'zur',
    ]);

    $neutral = VariableCatalog::resolve(['personnel' => catalogPerson(2)]);
    expect([
        $neutral['mitarbeiter.dienstgrad'], $neutral['mitarbeiter.qualifikation_fw'], $neutral['mitarbeiter.anrede'], $neutral['mitarbeiter.briefanrede'],
        $neutral['mitarbeiter.ihm_ihr'], $neutral['mitarbeiter.seine_ihre'], $neutral['mitarbeiter.zum_zur'],
    ])->toBe(['Brandmeister/-in', 'Truppmitglied', '', 'Guten Tag', 'Testperson', 'die', 'als']);
});

it('gives a reason for every key it cannot resolve', function () {
    $this->actingAs(User::factory()->create(['personnel_id' => null]));

    $reasons = VariableCatalog::missingReasons([]);

    expect(array_keys($reasons))->toBe(array_values(array_diff(array_keys(VariableCatalog::catalog()), ['datum'])))
        ->and($reasons['mitarbeiter.name'])->toBe('Das Dokument hängt an keinem Mitarbeiter.')
        ->and($reasons['aussteller.name'])->toBe('Dein Benutzerkonto ist mit keinem Mitarbeiter verknüpft. Das erledigt die Personalverwaltung.')
        ->and($reasons['organisation.plz'])->toBe('In den Einstellungen der Organisation ist dazu nichts eingetragen.')
        ->and($reasons['dokument.kennung'])->toBe('Das Dokument hat noch keine Kennung.')
        ->and(VariableCatalog::resolve([]))->toBe(['datum' => '18.10.2026']);
});

it('names the missing field of the employee', function () {
    $reasons = VariableCatalog::missingReasons(['personnel' => catalogPerson(0)]);

    expect($reasons)->toHaveKey('mitarbeiter.titel', 'Beim Mitarbeiter ist kein Titel eingetragen.')
        ->not->toHaveKey('mitarbeiter.name');
});
