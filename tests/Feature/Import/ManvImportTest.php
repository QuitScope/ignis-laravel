<?php

use App\Import\NotificationImporter;
use App\Models\Role;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\ManvBoard\Models\ManvIncident;
use Modules\ManvBoard\Models\ManvLogEntry;
use Modules\ManvBoard\Models\ManvPatient;
use Modules\ManvBoard\Models\ManvResource;
use Tests\Support\LegacyDatabase;
use Tests\Support\LegacySeed;

uses(RefreshDatabase::class)->group('legacy');

beforeEach(function () {
    if (! LegacySeed::boot()) {
        $this->markTestSkipped('LEGACY_TEST_DB_HOST not set');
    }
    $this->seed(RoleSeeder::class);
    config(['ignis.import.legacy_timezone' => 'America/New_York', 'ignis.import.legacy_db_timezone' => 'UTC']);
});

function mimSeed(): void
{
    LegacySeed::personnel([['id' => 1, 'fullname' => 'Anna Arzt'], ['id' => 2, 'fullname' => 'Otto Org']]);
    LegacySeed::users([['id' => 1, 'aktenid' => 1], ['id' => 2], ['id' => 3, 'aktenid' => 2]]);
    LegacySeed::pois([
        ['id' => 1, 'name' => 'Klinikum Nord', 'typ' => 'Krankenhaus'],
        ['id' => 2, 'name' => 'St. Marien', 'typ' => 'Krankenhaus'],
        ['id' => 3, 'name' => 'St. Marien', 'typ' => 'Krankenhaus'],
    ]);
    LegacySeed::vehicles([
        ['id' => 1, 'name' => 'RTW 1'],
        ['id' => 2, 'name' => 'RTW 2'],
        ['id' => 3, 'name' => 'RTW 2'],
        ['id' => 4, 'name' => 'RTW 3', 'active' => 0],
    ]);
    LegacySeed::manvIncidents([
        ['id' => 1, 'lna_mitarbeiter_id' => 1, 'lna_name' => 'Dr. Browser Name', 'orgl_mitarbeiter_id' => 2, 'orgl_name' => 'Org Leiter',
            'erstellt_von' => 1, 'geaendert_von' => 9, 'geaendert_am' => '2026-07-15 11:00:00', 'notizen' => 'Lage-Notiz', 'einsatzanlass' => 'Busunfall'],
        ['id' => 2, 'status' => 'abgeschlossen', 'lna_mitarbeiter_id' => 3, 'einsatzbeginn' => '2026-01-15 10:00:00', 'einsatzende' => '2026-01-15 12:00:00'],
        ['id' => 3, 'status' => null, 'einsatzbeginn' => null],
    ]);
    LegacySeed::manvResources([
        ['id' => 1, 'bezeichnung' => 'RTW 1', 'rufname' => 'Florian 83-1', 'fahrzeugtyp' => 'RTW', 'status' => 'im_einsatz'],
        ['id' => 2, 'bezeichnung' => 'RTW 2'],
        ['id' => 3, 'bezeichnung' => 'RTW 3', 'typ' => 'material', 'status' => 'nicht_verfuegbar'],
        ['id' => 4, 'bezeichnung' => 'rtw 1 '],
        ['id' => 5, 'bezeichnung' => 'Zelt', 'typ' => 'material', 'besatzung' => str_repeat('b', 300)],
        ['id' => 6, 'manv_lage_id' => 2, 'bezeichnung' => 'RTW 1'],
        ['id' => 7, 'manv_lage_id' => 77],
    ]);
    LegacySeed::manvPatients([
        ['id' => 1, 'sichtungskategorie' => 'tot', 'sichtungskategorie_zeit' => '2026-07-15 10:25:00', 'sichtungskategorie_geaendert_von' => 1,
            'geschlecht' => 'w', 'name' => 'Muster', 'vorname' => 'Max', 'geburtsdatum' => '1980-02-03', 'transportmittel' => 'RTW',
            'transportmittel_rufname' => 'RTW 1', 'fahrzeug_lokalisation' => 'Bereitstellung', 'transportziel' => 'Klinikum Nord',
            'verletzungen' => 'Fraktur', 'massnahmen' => 'Zugang gelegt', 'notizen' => 'Notiz', 'erstellt_von' => 1, 'geaendert_von' => 9],
        ['id' => 2, 'patienten_nummer' => 'MANV-001', 'sichtungskategorie' => 'XX', 'geschlecht' => 'XX', 'transportmittel' => 'RTW',
            'transportmittel_rufname' => 'RTW 9', 'transportziel' => 'St. Marien', 'transport_abfahrt' => '2026-07-15 11:00:00'],
        ['id' => 3, 'patienten_nummer' => 'MANV-001', 'transportmittel_rufname' => 'Weg', 'geburtsdatum' => '0000-00-00', 'transport_ankunft' => '2026-07-15 12:00:00'],
        ['id' => 4, 'manv_lage_id' => 2, 'patienten_nummer' => 'MANV-001', 'sichtungskategorie' => 'SK6', 'transportmittel_rufname' => 'RTW 1'],
    ]);
    LegacySeed::manvLog([
        ['id' => 1, 'benutzer_id' => 1, 'referenz_typ' => 'patient', 'referenz_id' => 2, 'aktion' => 'patient_erstellt', 'beschreibung' => 'Patient MANV-001 erstellt'],
        ['id' => 2, 'benutzer_id' => 9, 'benutzer_name' => 'Alt Name', 'referenz_typ' => 'ressource', 'referenz_id' => 99, 'aktion' => 'ressource_abgefahren'],
        ['id' => 3, 'timestamp' => null],
        ['id' => 4, 'manv_lage_id' => 2, 'referenz_typ' => 'ressource', 'referenz_id' => 6, 'timestamp' => '2026-01-15 10:30:00'],
    ]);
}

function mimId(string $entity, int $legacyId): ?int
{
    $id = DB::table('legacy_id_map')->where(['entity' => $entity, 'legacy_id' => (string) $legacyId])->value('new_id');

    return $id === null ? null : (int) $id;
}

function mimIncident(int $legacyId): ManvIncident
{
    return ManvIncident::findOrFail(mimId('manv_incident', $legacyId));
}

function mimPatient(int $legacyId): ManvPatient
{
    return ManvPatient::findOrFail(mimId('manv_patient', $legacyId));
}

function mimResource(int $legacyId): ManvResource
{
    return ManvResource::findOrFail(mimId('manv_resource', $legacyId));
}

function mimLog(int $legacyId): ManvLogEntry
{
    return ManvLogEntry::findOrFail(mimId('manv_log', $legacyId));
}

/** @return array<string, mixed> */
function mimDump(): array
{
    return collect(['manv_incidents', 'manv_resources', 'manv_patients', 'manv_log', 'legacy_id_map'])
        ->mapWithKeys(fn (string $t) => [$t => DB::table($t)->get()->map(fn ($r) => (array) $r)->sortBy(fn ($r) => json_encode($r))->values()->all()])->all();
}

/** @param array<string, mixed> $json */
function mimWarning(array $json, string $needle): string
{
    return (string) collect($json['warnings'])->first(fn (string $w) => str_contains($w, $needle));
}

it('imports incidents, resources, patients and the log with the mapping rules', function () {
    mimSeed();
    $json = LegacySeed::run(['manv']);

    expect($json['exit'])->toBe(0)
        ->and($json['entities']['manv_incident'])->toBe(['read' => 3, 'written' => 3, 'skipped' => 0, 'failed' => 0])
        ->and($json['entities']['manv_resource'])->toBe(['read' => 7, 'written' => 6, 'skipped' => 1, 'failed' => 0])
        ->and($json['entities']['manv_patient'])->toBe(['read' => 4, 'written' => 4, 'skipped' => 0, 'failed' => 0])
        ->and($json['entities']['manv_log'])->toBe(['read' => 4, 'written' => 4, 'skipped' => 0, 'failed' => 0]);

    $user = fn (int $legacy) => mimId('user', $legacy);
    $file = fn (int $legacy) => mimId('personnel', $legacy);

    $i = mimIncident(1);
    expect($i->lna_personnel_id)->toBe($file(1))->and($i->lna_name)->toBe('Dr. Browser Name')
        ->and($i->orgl_personnel_id)->toBeNull()->and($i->orgl_name)->toBe('Org Leiter')
        ->and($i->status->value)->toBe('active')->and($i->incident_number)->toBe('E-1')->and($i->location)->toBe('Ort 1')
        ->and($i->reason)->toBe('Busunfall')->and($i->notes)->toBe('Lage-Notiz')
        ->and($i->created_by)->toBe($user(1))->and($i->updated_by)->toBeNull()->and($i->ended_at)->toBeNull()
        ->and($i->getRawOriginal('started_at'))->toBe('2026-07-15 14:00:00')
        ->and($i->getRawOriginal('created_at'))->toBe('2026-07-15 10:05:00')
        ->and($i->getRawOriginal('updated_at'))->toBe('2026-07-15 11:00:00');
    $i = mimIncident(2);
    expect($i->status->value)->toBe('closed')->and($i->lna_personnel_id)->toBe($file(2))->and($i->lna_name)->toBe('Otto Org')
        ->and($i->getRawOriginal('started_at'))->toBe('2026-01-15 15:00:00')->and($i->ended_at)->toBeNull();
    $i = mimIncident(3);
    expect($i->status->value)->toBe('active')->and($i->getRawOriginal('started_at'))->toBe('2026-07-15 10:05:00');

    $vehicle = fn (int $legacy) => mimId('vehicle', $legacy);
    expect(mimResource(1)->vehicle_id)->toBe($vehicle(1))->and(mimResource(1)->status->value)->toBe('deployed')
        ->and(mimResource(1)->callsign)->toBe('Florian 83-1')->and(mimResource(1)->vehicle_type)->toBe('RTW')->and(mimResource(1)->type->value)->toBe('vehicle')
        ->and(mimResource(2)->vehicle_id)->toBeNull()
        ->and([mimResource(3)->vehicle_id, mimResource(3)->type->value, mimResource(3)->status->value])->toBe([null, 'material', 'unavailable'])
        ->and(mimResource(4)->label)->toBe('rtw 1 (2)')->and(mimResource(4)->vehicle_id)->toBeNull()
        ->and(mb_strlen((string) mimResource(5)->crew))->toBe(255)->and(mimResource(5)->notes)->toBe('Besatzung (Altbestand): '.str_repeat('b', 300))
        ->and(mimResource(6)->manv_incident_id)->toBe(mimIncident(2)->id)->and(mimResource(6)->label)->toBe('RTW 1');

    $p = mimPatient(1);
    expect($p->number)->toBe('MANV-001')->and($p->triage->value)->toBe('SK5')->and($p->sex->value)->toBe('f')
        ->and($p->getRawOriginal('triaged_at'))->toBe('2026-07-15 14:25:00')->and($p->triaged_by)->toBe($user(1))
        ->and($p->manv_resource_id)->toBe(mimResource(1)->id)->and($p->callsign)->toBe('RTW 1')->and($p->vehicle_type)->toBe('RTW')
        ->and($p->vehicle_location)->toBe('Bereitstellung')->and($p->destination)->toBe('Klinikum Nord')->and($p->destination_poi_id)->toBe(mimId('poi', 1))
        ->and($p->notes)->toBe("Notiz\n\nMaßnahmen (Altbestand): Zugang gelegt")->and($p->injuries)->toBe('Fraktur')
        ->and($p->birth_date->format('Y-m-d'))->toBe('1980-02-03')->and([$p->last_name, $p->first_name])->toBe(['Muster', 'Max'])
        ->and($p->created_by)->toBe($user(1))->and($p->updated_by)->toBeNull()->and($p->departed_at)->toBeNull();
    $p = mimPatient(2);
    expect($p->number)->toBe('MANV-001-2')->and($p->triage)->toBeNull()->and($p->sex->value)->toBe('unknown')
        ->and($p->manv_resource_id)->toBeNull()->and($p->callsign)->toBe('RTW 9')->and($p->getRawOriginal('departed_at'))->toBe('2026-07-15 11:00:00')
        ->and($p->destination)->toBe('St. Marien')->and($p->destination_poi_id)->toBeNull();
    $p = mimPatient(3);
    expect($p->number)->toBe('MANV-001-3')->and($p->birth_date)->toBeNull()->and($p->notes)->toBe('Ankunft (Altbestand): 2026-07-15 12:00:00')
        ->and($p->manv_resource_id)->toBeNull()->and($p->callsign)->toBe('Weg');
    expect(mimPatient(4)->number)->toBe('MANV-001')->and(mimPatient(4)->manv_resource_id)->toBe(mimResource(6)->id)->and(mimPatient(4)->triage->value)->toBe('SK6');

    $l = mimLog(1);
    expect($l->subject_type)->toBe('patient')->and($l->subject_id)->toBe(mimPatient(2)->id)->and($l->user_id)->toBe($user(1))
        ->and($l->user_name)->toBe('Anna Arzt')->and($l->action)->toBe('patient_erstellt')->and($l->description)->toBe('Patient MANV-001 erstellt')
        ->and($l->getRawOriginal('occurred_at'))->toBe('2026-07-15 10:30:00');
    expect([mimLog(2)->subject_type, mimLog(2)->subject_id, mimLog(2)->user_id, mimLog(2)->user_name, mimLog(2)->description])->toBe([null, null, null, 'Alt Name', 'Eintrag 2'])
        ->and([mimLog(3)->user_id, mimLog(3)->user_name, mimLog(3)->getRawOriginal('occurred_at')])->toBe([null, null, '2026-07-15 10:05:00'])
        ->and([mimLog(4)->subject_type, mimLog(4)->subject_id, mimLog(4)->manv_incident_id])->toBe(['resource', mimResource(6)->id, mimIncident(2)->id]);

    foreach ([
        'manv_incident(s): OrgL account without an imported personnel file' => '(legacy ids: 1)',
        'manv_incident(s): editing account not imported' => '(legacy ids: 1)',
        'manv_incident(s): einsatzende set' => '(legacy ids: 2)',
        "manv_incident(s): unknown status ''" => '(legacy ids: 3)',
        'manv_resource(s): label matches several active vehicles' => '(legacy ids: 2)',
        'manv_resource(s): label empty or used twice' => '(legacy ids: 4)',
        'manv_resource(s): crew over 255' => '(legacy ids: 5)',
        "manv_patient(s): triage 'tot' merged into SK5" => '(legacy ids: 1)',
        'manv_patient(s): measures (massnahmen' => '(legacy ids: 1)',
        'manv_patient(s): number empty or used twice' => '(legacy ids: 2, 3)',
        "manv_patient(s): unknown triage ''" => '(legacy ids: 2)',
        "manv_patient(s): unknown sex ''" => '(legacy ids: 2)',
        'manv_patient(s): departed, its resource no longer exists' => '(legacy ids: 2)',
        'manv_patient(s): assigned resource not found' => '(legacy ids: 3)',
        'manv_patient(s): unusable birth date' => '(legacy ids: 3)',
        'manv_patient(s): arrival time' => '(legacy ids: 3)',
        'manv_patient(s): editing account not imported' => '(legacy ids: 1)',
        'manv_log(s): account not imported' => '(legacy ids: 2)',
        'manv_log(s): reference to a patient or resource that was not imported' => '(legacy ids: 2)',
        'manv_log(s): no usable time' => '(legacy ids: 3)',
    ] as $needle => $ids) {
        expect(mimWarning($json, $needle))->toContain($ids);
    }
});

it('gives the same result on a second run and brings back a deleted row', function () {
    mimSeed();
    $first = LegacySeed::run(['manv']);
    $dump = mimDump();
    $second = LegacySeed::run(['manv']);

    expect(mimDump())->toBe($dump)
        ->and($second['entities'])->toMatchArray(array_intersect_key($first['entities'], array_flip(['manv_incident', 'manv_resource', 'manv_patient', 'manv_log'])))
        ->and(array_values(preg_grep('/manv/', $second['warnings'])))->toBe(array_values(preg_grep('/manv/', $first['warnings'])));

    $old = mimPatient(1)->id;
    mimPatient(1)->delete();
    LegacySeed::run(['manv']);
    expect(mimPatient(1)->id)->not->toBe($old)->and(mimPatient(1)->number)->toBe('MANV-001');
});

it('leaves no traces on a dry run', function () {
    mimSeed();
    $before = mimDump();
    $json = LegacySeed::run(['manv'], dry: true);

    expect($json['exit'])->toBe(0)->and($json['dry_run'])->toBeTrue()->and($json['entities']['manv_patient']['written'])->toBe(4)
        ->and(mimDump())->toBe($before);
});

it('isolates a failing row', function () {
    mimSeed();
    DB::table('legacy_id_map')->insert(['entity' => 'manv_incident', 'legacy_id' => '77', 'new_id' => 999999]);

    $json = LegacySeed::run(['manv']);

    expect($json['exit'])->toBe(1)
        ->and($json['entities']['manv_resource'])->toBe(['read' => 7, 'written' => 6, 'skipped' => 0, 'failed' => 1])
        ->and(mimId('manv_resource', 7))->toBeNull()->and(mimResource(4)->label)->toBe('rtw 1 (2)')
        ->and($json['entities']['manv_patient']['written'])->toBe(4)->and($json['entities']['manv_log']['written'])->toBe(4);
});

it('treats a stale account map entry as an account that was not imported', function () {
    mimSeed();
    DB::table('legacy_id_map')->insert(['entity' => 'user', 'legacy_id' => '50', 'new_id' => 999999]);
    $legacy = LegacyDatabase::connection();
    $legacy->table('intra_manv_lagen')->where('id', 3)->update(['erstellt_von' => 50, 'lna_mitarbeiter_id' => 50, 'lna_name' => 'Alt LNA']);
    $legacy->table('intra_manv_patienten')->where('id', 3)->update(['erstellt_von' => 50]);
    $legacy->table('intra_manv_log')->where('id', 3)->update(['benutzer_id' => 50, 'benutzer_name' => '']);

    $json = LegacySeed::run(['manv']);

    expect($json['exit'])->toBe(0)
        ->and($json['entities']['manv_incident'])->toBe(['read' => 3, 'written' => 3, 'skipped' => 0, 'failed' => 0])
        ->and($json['entities']['manv_patient'])->toBe(['read' => 4, 'written' => 4, 'skipped' => 0, 'failed' => 0])
        ->and($json['entities']['manv_log'])->toBe(['read' => 4, 'written' => 4, 'skipped' => 0, 'failed' => 0])
        ->and([mimIncident(3)->created_by, mimIncident(3)->lna_personnel_id, mimIncident(3)->lna_name])->toBe([null, null, 'Alt LNA'])
        ->and(mimPatient(3)->created_by)->toBeNull()
        ->and([mimLog(3)->user_id, mimLog(3)->user_name])->toBe([null, null])
        ->and(mimWarning($json, 'manv_incident(s): creating account not imported'))->toContain('(legacy ids: 3)')
        ->and(mimWarning($json, 'manv_patient(s): creating account not imported'))->toContain('(legacy ids: 3)')
        ->and(mimWarning($json, 'manv_log(s): account not imported'))->toContain('3');
});

it('rewrites notification links to incidents and patients, also the old /manv/ paths', function () {
    mimSeed();
    LegacySeed::notifications([
        ['id' => 1, 'link' => '/mci/board?id=1'],
        ['id' => 2, 'link' => '/manv/patient-view.php?id=4'],
        ['id' => 3, 'link' => '/mci/board?id=77'],
        ['id' => 4, 'link' => '/manv/resources?lage_id=2'],
        ['id' => 5, 'link' => '/mci/index?status=archiviert'],
    ]);
    LegacySeed::run(['manv', 'notification']);

    $link = fn (int $id) => json_decode((string) DB::table('notifications')->where('id', NotificationImporter::uuid($id))->value('data'), true)['link'];
    $i1 = mimIncident(1)->id;
    $i2 = mimIncident(2)->id;
    expect($link(1))->toBe("/mci/{$i1}")->and($link(2))->toBe("/mci/{$i2}/patients/".mimPatient(4)->id)->and($link(3))->toBe('/mci')
        ->and($link(4))->toBe("/mci/{$i2}/resources")->and($link(5))->toBe('/mci?status=archived');
});

it('maps the legacy manv.manage permission to mci.manage and grants mci.view to nobody', function () {
    LegacySeed::roles([
        ['id' => 4, 'name' => 'MANV alt', 'permissions' => '["manv.manage"]'],
        ['id' => 5, 'name' => 'MANV neu', 'permissions' => '["mci.manage","manv.manage"]'],
    ]);
    $json = LegacySeed::run(['role']);

    expect($json['exit'])->toBe(0)
        ->and(Role::findByName('MANV alt')->permissions->pluck('name')->all())->toBe(['mci.manage'])
        ->and(Role::findByName('MANV neu')->permissions->pluck('name')->all())->toBe(['mci.manage'])
        ->and(DB::table('permissions')->where('name', 'manv.manage')->exists())->toBeFalse()
        ->and(DB::table('role_has_permissions')->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
            ->where('permissions.name', 'mci.view')->exists())->toBeFalse();
});
