<?php

use App\Import\IdMap;
use App\Import\LegacyLinkRewriter;
use App\Models\Vehicle;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Support\LegacyDatabase;
use Tests\Support\LegacySeed;

uses(RefreshDatabase::class)->group('legacy');

beforeEach(function () {
    if (! LegacySeed::boot()) {
        $this->markTestSkipped('LEGACY_TEST_DB_HOST not set');
    }
    $this->seed(RoleSeeder::class);
    config(['ignis.import.legacy_timezone' => 'Europe/Berlin', 'ignis.import.legacy_db_timezone' => 'America/New_York']);
});

function eniSeed(): void
{
    LegacySeed::personnel([['id' => 1, 'fullname' => 'Lea Leiter'], ['id' => 2, 'fullname' => 'Doppel Name'], ['id' => 3, 'fullname' => 'Doppel Name']]);
    LegacySeed::vehicles([['id' => 1, 'identifier' => 'RTW-1'], ['id' => 2, 'identifier' => 'NEF-1']]);
    LegacySeed::pois([['id' => 5]]);
    LegacySeed::enotf('edivi', [
        ['id' => 1, 'enr' => '1001', 'freigegeben' => 1, 'freigeber_name' => 'Lea Leiter', 'billing_sent' => 1, 'billing_sent_at' => '2026-03-01 07:00:00',
            'createdby' => 2, 'protokoll_status' => 2, 'bearbeiter' => 'QM Person', 'eort' => 'tot',
            'pat_vorname' => 'Max', 'pat_nachname' => 'Muster', 'patname' => 'Muster, Max', 'patgebdat' => '1980-01-02', 'patsex' => 0,
            'edatum' => '2026-03-01', 'ezeit' => '10:00', 'salarm' => '2026-03-01 10:00:00', 's1' => '01.03.2026 10:05', 's2' => 'kaputt',
            'fzg_transp' => 'RTW-1', 'fzg_transp_perso' => 'Lea Leiter (NotSan)', 'fzg_transp_perso_2' => 'Fremder Helfer',
            'fzg_na' => 'NEF-1', 'fzg_na_perso' => 'Doppel Name (NA)',
            'c_zugang' => '0', 'psych' => '[2,3]', 'diagnose_weitere' => '{kaputt', 'ebesonderheiten' => '[]', 'awfrei_1' => 77,
            'transp_adresse' => '{"strasse":"Weg","hnr":"1","ort":"Stadt","ortsteil":""}', 'medis' => '[{"wirkstoff":"ASS","zeit":"10:00"}]',
            'transportziel' => '21', 'created_at' => '2026-03-01 09:00:00', 'last_edit' => '2026-03-01 12:00:00'],
        ['id' => 2, 'enr' => '1002', 'freigegeben' => 1, 'hidden_user' => 1, 'billing_sent' => 1, 'createdby' => 1, 'sendezeit' => '2025-06-01 10:00:00',
            'protokoll_status' => 7, 'last_edit' => '2025-06-02 08:00:00', 'patname' => 'Nach, Vor'],
        ['id' => 3, 'enr' => '1003', 'createdby' => 1, 'sendezeit' => '2026-05-01 10:00:00', 'pat_synced' => 2, 'hidden' => 1, 'protokoll_status' => 4,
            'transportziel' => 'poi_5', 'last_edit' => '2026-05-01 11:00:00', 'fzg_transp' => 'RTW-2'],
        ['id' => 4, 'enr' => str_repeat('9', 65)],
        ['id' => 5, 'enr' => '1001_1', 'transportziel' => 'KH-Nord', 'pat_synced' => 1],
    ]);
    LegacySeed::enotf('categories', [['id' => 1, 'name' => 'Schnellzugriff', 'slug' => 'schnellzugriff'], ['id' => 2, 'name' => 'Verwaltung', 'slug' => 'verwaltung', 'sort_order' => 2]]);
    LegacySeed::enotf('quicklinks', [
        ['id' => 1, 'url' => '../enotf/fahrzeuginfo', 'icon' => 'fa-solid fa-ambulance', 'category_slug' => 'schnellzugriff', 'col_width' => 'col'],
        ['id' => 2, 'url' => '../', 'icon' => 'fa-solid fa-toolbox', 'category_slug' => 'gibtsnicht'],
        ['id' => 3, 'url' => '/enotf/print/index.php?enr=1001', 'icon' => 'fa-solid fa-file-lines', 'category_slug' => 'verwaltung'],
        ['id' => 4, 'url' => '/enotf/qm/actions/3', 'category_slug' => 'verwaltung', 'col_width' => 'col-md-3'],
        ['id' => 5, 'url' => 'javascript:alert(1)', 'category_slug' => 'verwaltung'],
    ]);
}

function eniId(string $entity, int|string $legacyId): ?int
{
    $id = DB::table('legacy_id_map')->where(['entity' => $entity, 'legacy_id' => (string) $legacyId])->value('new_id');

    return $id === null ? null : (int) $id;
}

/** @return array<string, mixed> */
function eniDump(): array
{
    return collect(['enotf_protocols', 'enotf_protocol_crew', 'enotf_categories', 'enotf_quicklinks', 'enotf_medications', 'enotf_medications_given', 'enotf_vitals', 'legacy_id_map'])
        ->mapWithKeys(fn (string $t) => [$t => DB::table($t)->get()->map(fn ($r) => (array) $r)->sortBy(fn ($r) => json_encode($r))->values()->all()])->all();
}

it('imports protocols with state, billing, origin, clocks, JSON columns, crew and the legacy column; idempotent', function () {
    eniSeed();
    DB::table('enotf_categories')->insert(['name' => 'Eigene', 'slug' => 'verwaltung', 'sort_order' => 9, 'active' => 1]);
    $ambiguous = Vehicle::factory()->create(['identifier' => 'NEF-1']);
    DB::table('legacy_id_map')->insert(['entity' => 'enotf_protocol', 'legacy_id' => '5', 'new_id' => 999999]);

    $json = LegacySeed::run(['enotf']);
    expect($json['exit'])->toBe(0)->and($json['entities']['enotf_protocol'])->toMatchArray(['read' => 5, 'written' => 4, 'skipped' => 1, 'failed' => 0]);
    $warnings = implode("\n", $json['warnings']);
    expect($warnings)->toContain('ENR longer than 64 characters (legacy ids: 4)')
        ->toContain('invalid JSON in diagnose_weitere, kept in legacy (legacy ids: 1)')
        ->toContain('unusable time in s2, kept in legacy (legacy ids: 1)')
        ->toContain('billed without billing_sent_at, billed_at set to last_edit (legacy ids: 2)')
        ->toContain('unknown protokoll_status, QM status set to 0 (new) (legacy ids: 2)')
        ->toContain('na vehicle identifier not unique, kept as label only (legacy ids: 1)')
        ->toContain('transport vehicle identifier unknown, kept as label only (legacy ids: 3)')
        ->toContain('transport destination is no catalog code, no imported POI and no POI identifier, kept in legacy (legacy ids: 5)')
        ->toContain('category slug unknown, moved to category schnellzugriff (legacy ids: 2)')
        ->toContain('link target unusable, set to /enotf and deactivated (legacy ids: 5)');

    $p1 = (object) (array) DB::table('enotf_protocols')->where('id', eniId('enotf_protocol', 1))->first();
    $data = json_decode($p1->data, true);
    expect((array) $p1)->toMatchArray(['enr' => '1001', 'status' => 'released', 'origin' => 'manual', 'qm_status' => 2, 'hidden_at' => null,
        'released_by_name' => 'Lea Leiter', 'billing_batch' => 'legacy', 'patient_first_name' => 'Max', 'patient_last_name' => 'Muster',
        'mission_date' => '2026-03-01', 'mission_time' => '10:00', 'destination_code' => '21', 'patient_sync' => 'none',
        'vehicle_transport_label' => 'RTW-1', 'vehicle_na_label' => 'NEF-1', 'vehicle_na_id' => null])
        ->and($p1->vehicle_transport_id)->toBe(eniId('vehicle', 1))
        ->and(substr($p1->created_at, 0, 19))->toBe('2026-03-01 09:00:00')->and(substr($p1->released_at, 0, 19))->toBe('2026-03-01 12:00:00')
        ->and(substr($p1->billed_at, 0, 19))->toBe('2026-03-01 12:00:00')
        ->and(substr($p1->alarmed_at, 0, 19))->toBe('2026-03-01 09:00:00')->and($data['s1'])->toBe('2026-03-01T09:05:00Z')
        ->and($data)->toMatchArray(['c_zugang' => [], 'psych' => [2, 3], 'ebesonderheiten' => [],
            'transp_adresse' => ['strasse' => 'Weg', 'hnr' => '1', 'ort' => 'Stadt', 'ortsteil' => null]])
        ->and($data)->not->toHaveKeys(['diagnose_weitere', 's2', 'awfrei_1', 'medis', 'medis_keine'])
        ->and(DB::table('enotf_medications_given')->where('enotf_protocol_id', $p1->id)->pluck('name')->all())->toBe(['ASS'])
        ->and(json_decode($p1->legacy, true))->toBe(['awfrei_1' => 77, 'bearbeiter' => 'QM Person', 'diagnose_weitere' => '{kaputt', 'eort' => 'tot', 's2' => 'kaputt']);
    // Sorted in PHP: MariaDB orders the enum slot by definition (transport, na), SQLite by text.
    $crew = DB::table('enotf_protocol_crew')->where('enotf_protocol_id', $p1->id)->get()->sortBy(fn ($c) => [$c->slot, $c->position])->values();
    expect($crew->map(fn ($c) => [$c->slot, $c->position, $c->name, $c->qualification, $c->personnel_id])->all())->toBe([
        ['na', 1, 'Doppel Name', 'NA', null],
        ['transport', 1, 'Lea Leiter', 'NotSan', eniId('personnel', 1)],
        ['transport', 2, 'Fremder Helfer', null, null],
    ]);

    $p2 = DB::table('enotf_protocols')->where('enr', '1002')->first();
    expect((array) $p2)->toMatchArray(['status' => 'withdrawn', 'origin' => 'unknown', 'qm_status' => 0, 'patient_first_name' => 'Vor', 'patient_last_name' => 'Nach'])
        ->and(substr($p2->withdrawn_at, 0, 19))->toBe('2025-06-02 08:00:00')->and(substr($p2->billed_at, 0, 19))->toBe('2025-06-02 08:00:00')
        ->and(json_decode($p2->legacy, true))->toBe(['protokoll_status' => 7]);
    $p3 = DB::table('enotf_protocols')->where('enr', '1003')->first();
    expect((array) $p3)->toMatchArray(['status' => 'open', 'origin' => 'dispatch', 'qm_status' => 4, 'patient_sync' => 'pending', 'destination_code' => null,
        'destination_poi_id' => eniId('poi', 5), 'vehicle_transport_id' => null, 'vehicle_transport_label' => 'RTW-2'])
        ->and(substr($p3->hidden_at, 0, 19))->toBe('2026-05-01 11:00:00');
    $p5 = DB::table('enotf_protocols')->where('enr', '1001_1')->first();
    expect(eniId('enotf_protocol', 5))->toBe($p5->id)->and($p5->patient_sync)->toBe('synced')
        ->and(json_decode($p5->legacy, true))->toBe(['transportziel' => 'KH-Nord']);
    expect(implode(' ', $json['notes']))->toContain('1 protocol(s) with origin unknown');

    expect(eniId('enotf_category', 2))->toBe(DB::table('enotf_categories')->where('slug', 'verwaltung')->value('id'))
        ->and(DB::table('enotf_categories')->count())->toBe(2);
    $links = DB::table('enotf_quicklinks')->get()->keyBy(fn ($l) => array_search($l->id, DB::table('legacy_id_map')->where('entity', 'enotf_quicklink')->pluck('new_id', 'legacy_id')->all()));
    $schnell = eniId('enotf_category', 1);
    expect((array) $links[1])->toMatchArray(['url' => '/enotf/fahrzeuginfo', 'icon' => 'ambulance', 'col_width' => 'col-6', 'enotf_category_id' => $schnell])
        ->and((array) $links[2])->toMatchArray(['url' => '/', 'icon' => 'link', 'enotf_category_id' => $schnell])
        ->and((array) $links[3])->toMatchArray(['url' => '/enotf/print/1001', 'icon' => 'file-text'])
        ->and((array) $links[4])->toMatchArray(['url' => '/enotf/qm/1003', 'col_width' => 'col-6'])
        ->and((array) $links[5])->toMatchArray(['url' => '/enotf', 'active' => 0]);

    $rewriter = LegacyLinkRewriter::fromIds(app(IdMap::class));
    expect($rewriter->notificationLink('/enotf/protokoll/abschluss/freigabe.php?enr=1001'))->toBe(['/enotf/p/1001/abschluss', 'rewritten'])
        ->and($rewriter->notificationLink('/enotf/p/1002/verlauf/vitals'))->toBe(['/enotf/p/1002/verlauf', 'rewritten'])
        ->and($rewriter->notificationLink('/enotf/qm/log/2'))->toBe(['/enotf/qm/1002', 'rewritten'])
        ->and($rewriter->notificationLink('/enotf/admin/qm-actions-modal?id=77'))->toBe(['/enotf/admin', 'unmapped'])
        ->and($rewriter->notificationLink('/enotf/p/9999'))->toBe(['/enotf/overview', 'unmapped']);

    $before = eniDump();
    $again = LegacySeed::run(['enotf']);
    expect(eniDump())->toBe($before)->and($again['entities']['enotf_protocol'])->toBe($json['entities']['enotf_protocol']);
    expect($ambiguous->fresh())->not->toBeNull();
});

it('leaves no trace in a dry run', function () {
    eniSeed();
    $json = LegacySeed::run(['enotf'], dry: true);

    expect($json['exit'])->toBe(0)->and($json['entities']['enotf_protocol']['written'])->toBe(4)
        ->and(DB::table('enotf_protocols')->count())->toBe(0)->and(DB::table('enotf_protocol_crew')->count())->toBe(0)
        ->and(DB::table('enotf_categories')->count())->toBe(0)->and(DB::table('enotf_quicklinks')->count())->toBe(0)
        ->and(DB::table('legacy_id_map')->where('entity', 'like', 'enotf_%')->count())->toBe(0);
});

it('keeps protocols edited since the import and never clears a billed_at on a re-run', function () {
    eniSeed();
    LegacySeed::run(['enotf']);
    $edited = eniId('enotf_protocol', 1);
    DB::table('enotf_protocols')->where('id', $edited)->update(['revision' => 2, 'patient_last_name' => 'Neu']);
    $billedHere = eniId('enotf_protocol', 5);
    DB::table('enotf_protocols')->where('id', $billedHere)->update(['billed_at' => '2026-10-01 10:00:00']);

    $json = LegacySeed::run(['enotf']);

    expect(DB::table('enotf_protocols')->where('id', $edited)->value('patient_last_name'))->toBe('Neu')
        ->and(implode("\n", $json['warnings']))->toContain('edited in the new instance since the import (revision > 1), kept unchanged (legacy ids: 1)')
        ->and(substr((string) DB::table('enotf_protocols')->where('id', $billedHere)->value('billed_at'), 0, 19))->toBe('2026-10-01 10:00:00');
});

function eniSeed9b(): void
{
    LegacySeed::pois([['id' => 5], ['id' => 6, 'legacy_identifier' => 'amb', 'typ' => 'Krankenhaus']]);
    LegacySeed::enotf('medications', [
        ['id' => 1, 'wirkstoff' => 'Heparin', 'herstellername' => 'Liquemin', 'dosierungen' => ' 5000 IE , 10000 IE,', 'priority' => 3, 'active' => 0],
        ['id' => 2, 'wirkstoff' => 'Eigenmittel', 'dosierungen' => null, 'priority' => 99],
    ]);
    LegacySeed::enotf('edivi', [
        ['id' => 1, 'enr' => '2001', 'edatum' => '2026-03-01', 'transportziel' => 'AMB', 'created_at' => '2026-03-01 09:00:00', 'medis' => json_encode([
            ['wirkstoff' => 'heparin', 'zeit' => '10:15', 'applikation' => 'i.v.', 'dosierung' => '5000', 'einheit' => 'IE', 'timestamp' => 1772356500000],
            ['wirkstoff' => 'Zaubertrank', 'zeit' => '25:99', 'dosierung' => '1', 'einheit' => 'ml', 'timestamp' => 1772360000000],
            ['wirkstoff' => 'Liquemin', 'zeit' => '10:20'],
        ])],
        ['id' => 2, 'enr' => '2002', 'medis' => '0', 'transportziel' => 'poi_5'],
        ['id' => 3, 'enr' => '2003', 'medis' => '[]', 'transportziel' => 'poi_77'],
        ['id' => 4, 'enr' => '2004', 'medis' => '[{kaputt'],
    ]);
    LegacyDatabase::connection()->statement("SET time_zone = '-05:00'");
    LegacySeed::enotf('vitals', [
        ['id' => 1, 'enr' => '2001', 'parameter_name' => 'Blutzucker', 'parameter_wert' => 'ng', 'parameter_einheit' => 'mg/dl'],
        ['id' => 2, 'enr' => '2001', 'parameter_name' => 'SpO₂', 'parameter_wert' => '97', 'parameter_einheit' => '%', 'zeitpunkt' => '2026-03-01 10:30:00',
            'geloescht' => 1, 'geloescht_am' => '2026-03-01 11:00:00', 'geloescht_von' => 'Lea Leiter'],
        ['id' => 3, 'enr' => '2001', 'parameter_name' => 'Pupillen', 'parameter_wert' => 'eng', 'parameter_einheit' => ''],
        ['id' => 4, 'enr' => '2001', 'parameter_name' => 'Temperatur', 'parameter_wert' => '37,5', 'parameter_einheit' => '°C'],
        ['id' => 5, 'enr' => '20'],
        ['id' => 6, 'enr' => 'gibtsnicht'],
    ]);
    LegacyDatabase::connection()->statement("SET time_zone = '+00:00'");
}

it('imports medication catalog, medis rows, vital signs and POI identifiers; dry run without traces; idempotent; edited protocols kept', function () {
    eniSeed9b();
    $heparin = DB::table('enotf_medications')->where('substance', 'Heparin')->first();

    $dry = LegacySeed::run(['enotf'], dry: true);
    expect($dry['exit'])->toBe(0)->and($dry['entities']['enotf_vital']['written'])->toBe(4)
        ->and(DB::table('enotf_vitals')->count() + DB::table('enotf_medications_given')->count())->toBe(0)
        ->and(DB::table('enotf_medications')->count())->toBe(47)->and((array) DB::table('enotf_medications')->find($heparin->id))->toBe((array) $heparin);

    $json = LegacySeed::run(['enotf']);
    expect($json['exit'])->toBe(0)->and($json['entities']['enotf_vital'])->toMatchArray(['read' => 6, 'written' => 4, 'skipped' => 2, 'failed' => 0])
        ->and($json['entities']['enotf_medication_given'])->toMatchArray(['read' => 3, 'written' => 3]);
    $warnings = implode("\n", $json['warnings']);
    expect($warnings)->toContain('enotf_vital(s) skipped: 2 orphan(s) without an imported protocol of this ENR')
        ->toContain('first ENRs: 20, gibtsnicht (legacy ids: 5, 6)')
        ->toContain('unknown vital parameter, kept as other:<name> (legacy ids: 3)')
        ->toContain('invalid JSON in medis, kept in legacy (legacy ids: 4)')
        ->toContain('medication substance not in the catalog, kept as free text (legacy ids: 1)')
        ->toContain('no POI identifier, kept in legacy (legacy ids: 3)');

    expect(DB::table('enotf_medications')->count())->toBe(48)->and(DB::table('enotf_medications')->where('substance', 'Heparin')->count())->toBe(1)
        ->and(eniId('enotf_medication', 1))->toBe($heparin->id)
        ->and((array) DB::table('enotf_medications')->find($heparin->id))->toMatchArray(['brand' => 'Liquemin', 'doses' => '["5000 IE","10000 IE"]', 'sort' => 3, 'is_active' => 0])
        ->and(DB::table('enotf_medications')->where('substance', 'Adenosin')->value('is_active'))->toBe(1);

    $p = fn (string $enr) => DB::table('enotf_protocols')->where('enr', $enr)->first();
    $given = DB::table('enotf_medications_given')->where('enotf_protocol_id', $p('2001')->id)->orderBy('id')->get();
    expect($given->map(fn ($g) => [$g->medication_id, $g->name, $g->dose, $g->unit, $g->route, substr($g->given_at, 0, 19)])->all())->toBe([
        [$heparin->id, 'heparin', '5000', 'IE', 'i.v.', '2026-03-01 09:15:00'],
        [null, 'Zaubertrank', '1', 'ml', null, '2026-03-01 10:13:20'],
        [$heparin->id, 'Liquemin', null, null, null, '2026-03-01 09:20:00'],
    ])->and(json_decode($given[1]->legacy, true))->toMatchArray(['wirkstoff' => 'Zaubertrank', 'zeit' => '25:99']);
    expect(json_decode((string) $p('2002')->data, true))->toMatchArray(['medis_keine' => true])
        ->and(json_decode((string) $p('2003')->data, true))->not->toHaveKey('medis_keine')->and(json_decode($p('2003')->legacy, true))->toBe(['transportziel' => 'poi_77'])
        ->and(json_decode($p('2004')->legacy, true))->toBe(['medis' => '[{kaputt'])
        ->and($p('2001')->destination_poi_id)->toBe(eniId('poi', 6))->and($p('2002')->destination_poi_id)->toBe(eniId('poi', 5));

    $vitals = DB::table('enotf_vitals')->orderBy('id')->get();
    expect($vitals->map(fn ($v) => [$v->parameter, $v->value === null ? null : (float) $v->value, $v->value_text, $v->unit,
        substr($v->measured_at, 0, 19), $v->deleted_at === null ? null : substr($v->deleted_at, 0, 19)])->all())->toBe([
            ['bz', null, 'ng', 'mg/dl', '2026-03-01 09:00:00', null],
            ['spo2', 97.0, null, '%', '2026-03-01 09:30:00', '2026-03-01 10:00:00'],
            ['other:Pupillen', null, 'eng', null, '2026-03-01 09:00:00', null],
            ['temp', 37.5, null, '°C', '2026-03-01 09:00:00', null],
        ])->and(json_decode($vitals[1]->legacy, true))->toBe(['erstellt_von' => 'Lea Leiter', 'geloescht_von' => 'Lea Leiter']);

    $before = eniDump();
    LegacySeed::run(['enotf']);
    expect(eniDump())->toBe($before);

    DB::table('enotf_vitals')->where('id', $vitals[3]->id)->update(['deleted_at' => now()]);
    DB::table('enotf_medications_given')->where('id', $given[0]->id)->update(['deleted_at' => now()]);
    LegacySeed::run(['enotf']);
    expect(DB::table('enotf_vitals')->where('id', $vitals[3]->id)->value('deleted_at'))->not->toBeNull()
        ->and(DB::table('enotf_medications_given')->where('id', $given[0]->id)->value('deleted_at'))->not->toBeNull()
        ->and(DB::table('enotf_vitals')->where('id', $vitals[1]->id)->value('deleted_at'))->not->toBeNull();

    DB::table('enotf_protocols')->where('enr', '2001')->update(['revision' => 2]);
    DB::table('enotf_vitals')->where('id', $vitals[0]->id)->update(['value_text' => 'nm']);
    $again = LegacySeed::run(['enotf']);
    expect(implode("\n", $again['warnings']))->toContain('enotf_vital(s) skipped: protocol edited in the new instance since the import (revision > 1), kept unchanged (legacy ids: 1, 2, 3, 4)')
        ->and(DB::table('enotf_vitals')->where('id', $vitals[0]->id)->value('value_text'))->toBe('nm');
});

it('counts legacy sessions and share requests in one warning each and writes nothing', function () {
    eniSeed();
    $db = LegacyDatabase::connection();
    $db->table('intra_enotf_sessions')->insert([['vehicle_identifier' => 'RTW-1', 'fahrername' => 'Lea Leiter', 'active' => 1], ['vehicle_identifier' => 'NEF-1', 'fahrername' => null, 'active' => 0]]);
    $db->table('intra_enotf_session_members')->insert(['session_id' => $db->table('intra_enotf_sessions')->min('id'), 'session_token' => 'tok', 'position' => 'fahrer']);
    $db->table('intra_edivi_share_requests')->insert([
        ['source_enr' => '1001', 'source_protocol_id' => 1, 'source_vehicle' => 'RTW-1', 'target_vehicle' => 'NEF-1', 'status' => 'pending'],
        ['source_enr' => '1001', 'source_protocol_id' => 1, 'source_vehicle' => 'RTW-1', 'target_vehicle' => 'NEF-1', 'status' => 'accepted'],
    ]);
    $tables = DB::getSchemaBuilder()->getTableListing();

    $json = LegacySeed::run(['enotf']);
    $warnings = implode('
', $json['warnings']);
    expect($json['exit'])->toBe(0)
        ->and($warnings)->toContain('2 legacy vehicle session(s) (1 active, 1 member token(s)) not imported')
        ->toContain('1 open legacy share request(s) not imported (they must be sent again), 2 in total')
        ->and(DB::table('enotf_crews')->count())->toBe(0)->and(DB::table('enotf_share_requests')->count())->toBe(0)
        ->and(DB::getSchemaBuilder()->getTableListing())->toBe($tables);
});

function eniSeed9d(): void
{
    LegacySeed::users([['id' => 1, 'username' => 'quinn', 'fullname' => 'Quinn QM'], ['id' => 2, 'username' => 'd1', 'fullname' => 'Doppel Prüfer'],
        ['id' => 3, 'username' => 'd2', 'fullname' => 'Doppel Prüfer']]);
    LegacySeed::pois([['id' => 5, 'typ' => 'Krankenhaus'], ['id' => 6, 'typ' => 'Klinik']]);
    LegacySeed::enotf('edivi', [['id' => 1, 'enr' => '3001'], ['id' => 2, 'enr' => '3002']]);
    LegacySeed::enotf('qmlog', [
        ['id' => 1, 'protokoll_id' => 1, 'log_aktion' => 1, 'kommentar' => '<span class="ignis-chip ignis-chip--warn">in Prüfung</span>', 'bearbeiter' => 'Quinn QM'],
        ['id' => 2, 'protokoll_id' => 1, 'kommentar' => '<b>Bitte</b> Vitalwerte <3 ergänzen', 'bearbeiter' => 'Doppel Prüfer', 'timestamp' => '2026-03-01 10:05:00'],
        ['id' => 3, 'protokoll_id' => 1, 'log_aktion' => 1, 'bearbeiter' => 'Weg Gegangen', 'timestamp' => '2026-03-01 10:10:00',
            'kommentar' => '<span class="badge text-bg-success" style="line-height: var(--bs-body-line-height); border-radius: 0;">Freigegeben</span>'],
        ['id' => 4, 'protokoll_id' => 99, 'kommentar' => 'Waise'],
        ['id' => 5, 'protokoll_id' => 2, 'log_aktion' => 1, 'kommentar' => '<span class="ignis-chip">Unbekannt</span>', 'bearbeiter' => null],
    ]);
    LegacySeed::enotf('departments', [
        ['id' => 1, 'poi_id' => 5, 'name' => 'ZNA', 'sort_order' => 1], ['id' => 2, 'poi_id' => 5, 'name' => 'Schockraum'],
        ['id' => 3, 'poi_id' => 77, 'name' => 'Ohne Klinik'], ['id' => 4, 'poi_id' => 6, 'name' => 'ZNA'],
    ]);
    LegacySeed::enotf('availability', [['id' => 1, 'department_id' => 1, 'status' => 'available', 'updated_by' => 'Dr. Dienst', 'updated_at' => '2026-03-02 08:00:00']]);
    LegacySeed::enotf('access_codes', [['id' => 1, 'poi_id' => 5, 'code' => ' geheim-123 '], ['id' => 2, 'poi_id' => 6, 'code' => 'geheim-123'], ['id' => 3, 'poi_id' => 77, 'code' => 'waise-999']]);
    LegacySeed::enotf('klinikcodes', [['id' => 1], ['id' => 2, 'expires_at' => '2099-01-01 00:00:00']]);
    LegacySeed::enotf('prereg', [['id' => 1, 'active' => 1], ['id' => 2, 'active' => 0]]);
}

/** @return array<string, mixed> */
function eniDump9d(): array
{
    return collect(['enotf_review_log', 'enotf_hospital_departments', 'enotf_hospital_access_codes', 'legacy_id_map'])
        ->mapWithKeys(fn (string $t) => [$t => DB::table($t)->get()->map(fn ($r) => (array) $r)->sortBy(fn ($r) => json_encode($r))->values()->all()])->all();
}

it('imports the QM log, departments with availability and hashed access codes; clinic codes and preregistrations not; dry run; idempotent', function () {
    eniSeed9d();

    $dry = LegacySeed::run(['enotf'], dry: true);
    expect($dry['exit'])->toBe(0)->and($dry['entities']['enotf_review_log']['written'])->toBe(4)
        ->and(DB::table('enotf_review_log')->count() + DB::table('enotf_hospital_departments')->count() + DB::table('enotf_hospital_access_codes')->count())->toBe(0)
        ->and(DB::table('legacy_id_map')->where('entity', 'like', 'enotf_%')->count())->toBe(0);

    $json = LegacySeed::run(['enotf']);
    expect($json['exit'])->toBe(0)
        ->and($json['entities']['enotf_review_log'])->toMatchArray(['read' => 5, 'written' => 4, 'skipped' => 1, 'failed' => 0])
        ->and($json['entities']['enotf_hospital_department'])->toMatchArray(['read' => 4, 'written' => 3, 'skipped' => 1])
        ->and($json['entities']['enotf_hospital_access_code'])->toMatchArray(['read' => 3, 'written' => 2, 'skipped' => 1]);
    $warnings = implode("\n", $json['warnings']);
    expect($warnings)->toContain('enotf_review_log(s) skipped: protocol not imported (legacy ids: 4)')
        ->toContain('status entry without a known status chip, kept as comment (legacy ids: 5)')
        ->toContain('reviewer name without exactly one matching account, kept as name only (legacy ids: 2, 3)')
        ->toContain('enotf_hospital_department(s) skipped: hospital POI not imported (legacy ids: 3)')
        ->toContain('3 legacy hospital access code(s) stored only as hash; they stay valid, rotation recommended')
        ->toContain('2 of them shared by more than one hospital (legacy ids: 1, 2)')
        ->toContain('2 legacy clinic code(s) (1 still valid) and 2 preregistration(s) (1 active) not imported')
        ->and(json_encode($json))->not->toContain('geheim')->not->toContain('waise-999');

    $p1 = eniId('enotf_protocol', 1);
    $log = DB::table('enotf_review_log')->orderBy('id')->get();
    expect($log->map(fn ($l) => [$l->enotf_protocol_id, $l->kind, $l->from_status, $l->to_status, $l->text, $l->actor_user_id, $l->actor_name, substr($l->created_at, 0, 19)])->all())->toBe([
        [$p1, 'status', null, 1, null, eniId('user', 1), 'Quinn QM', '2026-03-01 10:00:00'],
        [$p1, 'comment', null, null, '<b>Bitte</b> Vitalwerte <3 ergänzen', null, 'Doppel Prüfer', '2026-03-01 10:05:00'],
        [$p1, 'status', 1, 2, null, null, 'Weg Gegangen', '2026-03-01 10:10:00'],
        [eniId('enotf_protocol', 2), 'comment', null, null, 'Unbekannt', null, '?', '2026-03-01 10:00:00'],
    ]);

    $deps = DB::table('enotf_hospital_departments')->orderBy('id')->get();
    expect($deps->map(fn ($d) => [$d->poi_id, $d->name, $d->sort_order, $d->status, $d->status_updated_by, $d->status_updated_at === null ? null : substr($d->status_updated_at, 0, 19)])->all())->toBe([
        [eniId('poi', 5), 'ZNA', 1, 'available', 'Dr. Dienst', '2026-03-02 08:00:00'],
        [eniId('poi', 5), 'Schockraum', 999, 'not_staffed', null, null],
        [eniId('poi', 6), 'ZNA', 999, 'not_staffed', null, null],
    ]);

    $codes = DB::table('enotf_hospital_access_codes')->orderBy('poi_id')->get();
    expect($codes)->toHaveCount(2)->and($codes->every(fn ($c) => Hash::check('geheim-123', $c->code_hash) && ! str_contains($c->code_hash, 'geheim')))->toBeTrue()
        ->and(DB::table('enotf_clinic_codes')->count() + DB::table('enotf_preregistrations')->count())->toBe(0);

    $before = eniDump9d();
    LegacySeed::run(['enotf']);
    expect(eniDump9d())->toBe($before);

    DB::table('enotf_hospital_access_codes')->where('poi_id', eniId('poi', 5))->update(['code_hash' => Hash::make('neu')]);
    $again = LegacySeed::run(['enotf']);
    expect(implode("\n", $again['warnings']))->toContain('access code rotated in the new instance since the import, kept (legacy ids: 1)')
        ->and(Hash::check('neu', (string) DB::table('enotf_hospital_access_codes')->where('poi_id', eniId('poi', 5))->value('code_hash')))->toBeTrue();
});
