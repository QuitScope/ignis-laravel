<?php

use App\Import\NotificationImporter;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Forms\Models\FormField;
use Modules\Forms\Models\FormSubmission;
use Modules\Forms\Models\FormType;
use Tests\Support\LegacyDatabase;
use Tests\Support\LegacySeed;

uses(RefreshDatabase::class)->group('legacy');

beforeEach(function () {
    if (! LegacySeed::boot()) {
        $this->markTestSkipped('LEGACY_TEST_DB_HOST not set');
    }
    $this->seed(RoleSeeder::class);
    config(['ignis.import.legacy_timezone' => 'Asia/Tokyo', 'ignis.import.legacy_db_timezone' => 'America/New_York']);
});

const FMI_D1 = '111111111111111111';
const FMI_D2 = '222222222222222222';

function formsImportSeed(): void
{
    LegacySeed::users([['id' => 1], ['id' => 2]]);
    LegacySeed::personnel([
        ['id' => 1, 'discordtag' => FMI_D1], ['id' => 2, 'discordtag' => FMI_D2], ['id' => 3, 'discordtag' => FMI_D2], ['id' => 4],
    ]);
    LegacySeed::formTypes([
        ['id' => 1, 'name' => 'Beförderungsantrag', 'icon' => 'fa-solid fa-angles-up', 'erstellt_von' => 1, 'sortierung' => 1],
        ['id' => 2, 'name' => 'urlaubsantrag ', 'icon' => 'fa-solid fa-umbrella-beach', 'erstellt_von' => 55, 'sortierung' => 2],
        ['id' => 3, 'name' => 'Freistellung', 'icon' => 'fa-solid fa-rocket', 'aktiv' => 0, 'sortierung' => 3],
        ['id' => 4, 'name' => 'Sonstiges', 'icon' => 'fa-solid fa-file-alt', 'sortierung' => -2, 'erstellt_am' => '2024-01-15 12:00:00'],
    ]);
    LegacySeed::formFields([
        ['id' => 1, 'antragstyp_id' => 1, 'feldname' => 'name_dn', 'label' => 'Name und Dienstnummer', 'readonly' => 1, 'auto_fill' => 'fullname_dienstnr', 'breite' => 'half', 'sortierung' => 1],
        ['id' => 2, 'antragstyp_id' => 1, 'feldname' => 'dienstgrad', 'label' => 'Aktueller Rank', 'readonly' => 1, 'auto_fill' => 'dienstgrad', 'breite' => 'half', 'sortierung' => 2],
        ['id' => 3, 'antragstyp_id' => 1, 'feldname' => 'freitext', 'label' => 'Schriftlicher Antrag', 'feldtyp' => 'textarea', 'pflichtfeld' => 1, 'sortierung' => 3],
        ['id' => 4, 'antragstyp_id' => 2, 'feldname' => 'von_datum', 'label' => 'Urlaub von', 'feldtyp' => 'date', 'sortierung' => 1],
        ['id' => 5, 'antragstyp_id' => 2, 'feldname' => 'bis_datum', 'label' => 'Urlaub bis', 'feldtyp' => 'date', 'sortierung' => 2],
        ['id' => 6, 'antragstyp_id' => 2, 'feldname' => 'grund', 'label' => 'Grund', 'feldtyp' => 'textarea', 'sortierung' => 3],
        ['id' => 7, 'antragstyp_id' => 2, 'feldname' => 'Extra Feld!', 'label' => '', 'sortierung' => 4],
        ['id' => 8, 'antragstyp_id' => 3, 'feldname' => 'von_datum', 'feldtyp' => 'text'],
        ['id' => 9, 'antragstyp_id' => 3, 'feldname' => 'bis_datum', 'feldtyp' => 'date'],
        ['id' => 10, 'antragstyp_id' => 4, 'feldname' => 'farbe', 'label' => 'Farbe', 'feldtyp' => 'select', 'optionen' => "rot\r\ngrün\n\n blau \nrot", 'sortierung' => 2],
        ['id' => 11, 'antragstyp_id' => 4, 'feldname' => 'Notiz', 'label' => 'Notiz', 'sortierung' => 1, 'auto_fill' => 'quatsch'],
        ['id' => 12, 'antragstyp_id' => 4, 'feldname' => '1zahl', 'label' => 'Zahl', 'feldtyp' => 'number', 'sortierung' => 3],
        ['id' => 13, 'antragstyp_id' => 4, 'feldname' => 'farbe', 'label' => 'Farbe zwei', 'sortierung' => 4],
        ['id' => 14, 'antragstyp_id' => 4, 'feldname' => 'ok', 'label' => 'Bestätigt', 'feldtyp' => 'checkbox', 'sortierung' => 5],
    ]);
    LegacySeed::forms([
        ['id' => 1, 'antragstyp_id' => 1, 'uniqueid' => '123456', 'mitarbeiter_id' => 1, 'discordid' => FMI_D1, 'cirs_status' => 3,
            'cirs_manager' => 'Chef Person', 'cirs_text' => 'Gut so', 'time_added' => '2024-01-15 12:00:00', 'cirs_time' => '2024-07-15 12:00:00'],
        ['id' => 2, 'antragstyp_id' => 2, 'uniqueid' => '234567', 'discordid' => FMI_D1, 'cirs_status' => 0, 'cirs_manager' => 'Chef Person',
            'cirs_time' => '2024-07-16 12:00:00', 'dienstgrad' => ''],
        ['id' => 3, 'antragstyp_id' => 4, 'uniqueid' => 'ABC', 'discordid' => FMI_D2, 'cirs_status' => 7],
        ['id' => 4, 'antragstyp_id' => 4, 'uniqueid' => '654321', 'mitarbeiter_id' => 4, 'cirs_status' => 1, 'cirs_time' => '2024-07-17 08:00:00'],
        ['id' => 5, 'antragstyp_id' => 4, 'uniqueid' => '345678', 'mitarbeiter_id' => 4, 'cirs_status' => 2, 'cirs_time' => '2024-07-18 08:00:00'],
        ['id' => 6, 'antragstyp_id' => 4, 'uniqueid' => '456789', 'mitarbeiter_id' => 4, 'cirs_status' => 0],
    ]);
    LegacySeed::formData([
        [1, 'name_dn', 'Person 1 (1)'], [1, 'dienstgrad', 'Brandmeister'], [1, 'freitext', "  Bitte\nbefördern  "],
        [2, 'von_datum', '2024-08-01'], [2, 'bis_datum', '01.08.2024'], [2, 'grund', 'erst'], [2, 'alt_feld', 'weg'], [2, 'grund', 'zuletzt'],
        [3, 'ok', 'on'], [3, 'farbe', 'rot'], [3, 'Notiz', str_repeat('x', 1500)], [3, '1zahl', '5'],
        [4, 'ok', 'ja'], [5, 'ok', '0'], [5, 'farbe', null],
    ]);
    $own = DB::table('form_types')->insertGetId(['name' => 'Handarbeit', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('form_submissions')->insert(['public_id' => '654321', 'form_type_id' => $own,
        'applicant_name' => 'Neu', 'status' => 'new', 'answers' => '[]', 'submitted_at' => now()]);
}

function formsImportId(string $entity, int|string $legacyId): ?int
{
    $id = DB::table('legacy_id_map')->where(['entity' => $entity, 'legacy_id' => (string) $legacyId])->value('new_id');

    return $id === null ? null : (int) $id;
}

function fmiSubmission(int $legacyId): FormSubmission
{
    return FormSubmission::findOrFail(formsImportId('form_submission', $legacyId));
}

/** @return array<string, int> */
function fmiCounts(): array
{
    return collect(['form_types', 'form_fields', 'form_submissions', 'legacy_id_map'])->mapWithKeys(fn (string $t) => [$t => DB::table($t)->count()])->all();
}

/** @return array<string, mixed> key => value of a snapshot */
function fmiValues(FormSubmission $s): array
{
    return collect($s->answers)->mapWithKeys(fn (array $a) => [$a['key'] => $a['value']])->all();
}

it('imports types, fields and submissions with mapped owners, statuses, clocks and snapshots', function () {
    formsImportSeed();
    $seeded = FormType::query()->orderBy('id')->pluck('id', 'name')->all();

    $json = LegacySeed::run(['form']);

    expect($json['exit'])->toBe(0)
        ->and($json['entities']['form_type'])->toBe(['read' => 4, 'written' => 4, 'skipped' => 0, 'failed' => 0])
        ->and($json['entities']['form_field'])->toBe(['read' => 14, 'written' => 14, 'skipped' => 0, 'failed' => 0])
        ->and($json['entities']['form_submission'])->toBe(['read' => 6, 'written' => 6, 'skipped' => 0, 'failed' => 0])
        ->and(FormType::count())->toBe(5)
        ->and(formsImportId('form_type', 1))->toBe($seeded['Beförderungsantrag'])->and(formsImportId('form_type', 2))->toBe($seeded['Urlaubsantrag'])
        ->and(FormField::where('form_type_id', $seeded['Beförderungsantrag'])->count())->toBe(3)
        ->and(FormField::where('form_type_id', $seeded['Urlaubsantrag'])->pluck('key')->sort()->values()->all())
        ->toBe(['bis_datum', 'dienstgrad', 'extra_feld_', 'grund', 'name_dn', 'von_datum']);

    $promo = FormType::findOrFail($seeded['Beförderungsantrag']);
    $leave = FormType::findOrFail($seeded['Urlaubsantrag']);
    $free = FormType::findOrFail(formsImportId('form_type', 3));
    $other = FormType::findOrFail(formsImportId('form_type', 4));
    expect($promo->icon)->toBe('chevrons-up')->and($promo->created_by)->toBe(formsImportId('user', 1))->and($promo->is_absence)->toBeFalse()
        ->and($leave->name)->toBe('urlaubsantrag')->and($leave->icon)->toBe('umbrella')->and($leave->is_absence)->toBeTrue()->and($leave->created_by)->toBeNull()
        ->and($free->icon)->toBeNull()->and($free->is_active)->toBeFalse()->and($free->is_absence)->toBeFalse()
        ->and($other->icon)->toBe('file-text')->and($other->sort_order)->toBe(0)->and($other->is_absence)->toBeFalse()
        ->and($other->created_at->utc()->format('Y-m-d H:i'))->toBe('2024-01-15 12:00');

    $fields = FormField::where('form_type_id', $other->id)->orderBy('sort_order')->get();
    expect($fields->pluck('key')->all())->toBe(['notiz', 'farbe', 'f_1zahl', 'farbe_2', 'ok'])
        ->and($fields[1]->options)->toBe(['rot', 'grün', 'blau'])->and($fields[1]->type)->toBe('select')
        ->and($fields[3]->options)->toBeNull()->and($fields[3]->label)->toBe('Farbe zwei')
        ->and($fields[0]->auto_fill)->toBeNull()
        ->and(FormField::where('form_type_id', $leave->id)->where('key', 'extra_feld_')->value('label'))->toBe('Extra Feld!')
        ->and(FormField::where('form_type_id', $promo->id)->where('key', 'name_dn')->first()->only(['readonly', 'auto_fill', 'width']))
        ->toBe(['readonly' => true, 'auto_fill' => 'fullname_dienstnr', 'width' => 'half']);

    $one = fmiSubmission(1);
    $two = fmiSubmission(2);
    $three = fmiSubmission(3);
    $four = fmiSubmission(4);
    $five = fmiSubmission(5);
    $six = fmiSubmission(6);
    expect($one->public_id)->toBe('123456')->and($one->personnel_id)->toBe(formsImportId('personnel', 1))->and($one->status)->toBe('accepted')
        ->and($one->applicant_name)->toBe('Person 1 (1)')->and($one->applicant_rank)->toBe('Brandmeister')->and($one->legacy_discord_id)->toBe(FMI_D1)
        ->and($one->decided_by)->toBeNull()->and($one->decided_by_name)->toBe('Chef Person')->and($one->decision_note)->toBe('Gut so')
        ->and($one->submitted_at->utc()->format('Y-m-d H:i'))->toBe('2024-01-15 17:00')
        ->and($one->decided_at->utc()->format('Y-m-d H:i'))->toBe('2024-07-15 03:00')
        ->and($one->answers)->toBe([
            ['key' => 'name_dn', 'label' => 'Name und Dienstnummer', 'type' => 'text', 'width' => 'half', 'value' => 'Person 1 (1)'],
            ['key' => 'dienstgrad', 'label' => 'Aktueller Rank', 'type' => 'text', 'width' => 'half', 'value' => 'Brandmeister'],
            ['key' => 'freitext', 'label' => 'Schriftlicher Antrag', 'type' => 'textarea', 'width' => 'full', 'value' => "Bitte\nbefördern"],
        ])
        ->and($two->personnel_id)->toBe(formsImportId('personnel', 1))->and($two->status)->toBe('in_progress')
        ->and($two->decided_at->utc()->format('Y-m-d H:i'))->toBe('2024-07-16 03:00')
        ->and($two->decided_by_name)->toBe('Chef Person')->and($two->applicant_rank)->toBeNull()
        ->and($two->submitted_at->utc()->format('Y-m-d H:i'))->toBe('2024-07-15 16:00')
        ->and($two->answers)->toBe([
            ['key' => 'von_datum', 'label' => 'Urlaub von', 'type' => 'date', 'width' => 'full', 'value' => '2024-08-01'],
            ['key' => 'bis_datum', 'label' => 'Urlaub bis', 'type' => 'date', 'width' => 'full', 'value' => '01.08.2024'],
            ['key' => 'grund', 'label' => 'Grund', 'type' => 'textarea', 'width' => 'full', 'value' => 'zuletzt'],
            ['key' => 'extra_feld_', 'label' => 'Extra Feld!', 'type' => 'text', 'width' => 'full', 'value' => ''],
            ['key' => 'alt_feld', 'label' => 'alt_feld', 'type' => 'text', 'width' => 'full', 'value' => 'weg'],
        ])
        ->and($three->personnel_id)->toBeNull()->and($three->legacy_discord_id)->toBe(FMI_D2)->and($three->status)->toBe('in_progress')
        ->and($three->public_id)->toMatch('/^[0-9]{6}$/')
        ->and(fmiValues($three))->toBe(['notiz' => str_repeat('x', 1000), 'farbe' => 'rot', 'f_1zahl' => '5', 'farbe_2' => 'rot', 'ok' => true])
        ->and($four->public_id)->not->toBe('654321')->and($four->public_id)->toMatch('/^[0-9]{6}$/')->and($four->status)->toBe('rejected')
        ->and($four->decided_at->utc()->format('Y-m-d H:i'))->toBe('2024-07-16 23:00')
        ->and(fmiValues($four)['ok'])->toBeTrue()
        ->and($five->status)->toBe('deferred')->and($six->status)->toBe('new')->and($six->decided_at)->toBeNull()->and($six->decided_by_name)->toBeNull()
        ->and(fmiValues($five)['ok'])->toBeFalse()->and(fmiValues($five)['farbe'])->toBe('')
        ->and($five->personnel_id)->toBe(formsImportId('personnel', 4))
        ->and(formsImportId('form_uniqueid', 'ABC'))->toBe($three->id)->and(formsImportId('form_uniqueid', '654321'))->toBe($four->id);

    $warnings = implode("\n", $json['warnings']);
    expect($warnings)
        ->toContain('1 form type(s): creating account not imported, left empty (legacy ids: 2)')
        ->toContain('1 form type(s) named like an absence type without date fields von_datum and bis_datum, not marked as absence (legacy ids: 3)')
        ->toContain('1 form type(s): icon has no counterpart among the form icons, imported without icon (shown as a file) (legacy ids: 3)')
        ->toContain('3 form field(s): field name is no identifier (a-z, 0-9, _ starting with a letter), normalised (legacy ids: 7, 11, 12)')
        ->toContain('1 form field(s): field name used twice in one type, suffixed _2, _3 ... (legacy ids: 13)')
        ->toContain("1 form field(s) with an unknown auto_fill 'quatsch', imported without (legacy ids: 11)")
        ->toContain('1 form submission(s) without an imported personnel file, matched to the one personnel file with their Discord id (legacy ids: 2)')
        ->toContain('1 form submission(s) without an imported personnel file and without a unique Discord match, kept without owner (only editors see them) (legacy ids: 3)')
        ->toContain("1 form submission(s) with an unknown status '7', set to in_progress (legacy ids: 3)")
        ->toContain("1 form submission(s) whose legacy uniqueid was not six digits, given a new public id (old links are rewritten; legacy id 'uniqueid' => new) (legacy ids: 3 'ABC' => {$three->public_id})")
        ->toContain("1 form submission(s) whose legacy uniqueid was taken in the new instance, given a new public id (old links are rewritten; legacy id 'uniqueid' => new) (legacy ids: 4 '654321' => {$four->public_id})")
        ->not->toContain('no unused seed type')
        ->toContain('1 form submission(s) with values of deleted fields, kept with the field name as label (legacy ids: 2)')
        ->toContain('1 form submission(s) with several values for one field, the last one kept (legacy ids: 2)')
        ->toContain('1 form submission(s) with a value over the 1000 characters of its field type, cut (legacy ids: 3)')
        ->toContain('1 form submission(s) with a checkbox value other than 1/on/true/0/off/false, read as ticked (as legacy showed it) (legacy ids: 4)')
        ->and(implode("\n", $json['notes']))->toContain("legacy type 1 'Beförderungsantrag' merged into the existing type {$promo->id} of the same name");
});

it('falls back to text for an unknown field type', function () {
    LegacySeed::formTypes([['id' => 1, 'name' => 'Neu']]);
    LegacyDatabase::connection()->statement('ALTER TABLE intra_antrag_felder MODIFY feldtyp VARCHAR(20) NOT NULL');
    try {
        LegacySeed::formFields([['id' => 1, 'antragstyp_id' => 1, 'feldname' => 'farbe', 'feldtyp' => 'color'],
            ['id' => 2, 'antragstyp_id' => 1, 'feldname' => 'Größe'], ['id' => 3, 'antragstyp_id' => 1, 'feldname' => 'Maß €']]);
        $json = LegacySeed::run(['form']);
    } finally {
        LegacyDatabase::connection()->table('intra_antrag_felder')->delete();
        LegacyDatabase::connection()->statement("ALTER TABLE intra_antrag_felder MODIFY feldtyp ENUM('text','textarea','number','date','select','checkbox','email','time','tel') NOT NULL");
    }

    expect(FormField::findOrFail(formsImportId('form_field', 1))->type)->toBe('text')
        ->and(FormField::findOrFail(formsImportId('form_field', 2))->key)->toBe('groesse')
        ->and(FormField::findOrFail(formsImportId('form_field', 3))->key)->toBe('mass__')
        ->and(implode("\n", $json['warnings']))->toContain("1 form field(s) with an unknown type 'color', imported as text (legacy ids: 1)");
});

it('imports a second time with identical counts and rows', function () {
    formsImportSeed();
    $first = LegacySeed::run(['form']);
    $before = fmiCounts();
    $rows = fn () => collect(['form_types', 'form_fields', 'form_submissions'])->mapWithKeys(fn ($t) => [$t => DB::table($t)->orderBy('id')->get()->toArray()])->all();
    $snapshot = $rows();

    $second = LegacySeed::run(['form']);

    expect($second['exit'])->toBe(0)->and($second['entities'])->toBe($first['entities'])
        ->and(fmiCounts())->toBe($before)->and($rows())->toEqual($snapshot);
});

it('leaves no trace on a dry run', function () {
    formsImportSeed();
    $before = fmiCounts();
    $types = DB::table('form_types')->orderBy('id')->get()->toArray();

    $json = LegacySeed::run(['form'], dry: true);

    expect($json['exit'])->toBe(0)->and($json['dry_run'])->toBeTrue()
        ->and($json['entities']['form_submission']['written'])->toBe(6)
        ->and(fmiCounts())->toBe($before)->and(DB::table('form_types')->orderBy('id')->get()->toArray())->toEqual($types);
});

it('brings a submission deleted in the new instance back under its legacy number', function () {
    formsImportSeed();
    LegacySeed::run(['form']);
    fmiSubmission(1)->delete();

    $json = LegacySeed::run(['form']);

    expect($json['entities']['form_submission']['written'])->toBe(6)
        ->and(fmiSubmission(1)->public_id)->toBe('123456')->and(DB::table('form_submissions')->count())->toBe(7);
});

it('records a submission that cannot be stored as failed and goes on', function () {
    formsImportSeed();
    $db = LegacyDatabase::connection();
    $db->statement('SET FOREIGN_KEY_CHECKS=0');
    $db->table('intra_antraege')->whereIn('id', [4, 5])->update(['mitarbeiter_id' => 99]);
    $db->statement('SET FOREIGN_KEY_CHECKS=1');
    DB::table('legacy_id_map')->insert(['entity' => 'personnel', 'legacy_id' => '99', 'new_id' => 999999]);

    $json = LegacySeed::run(['form']);

    expect($json['exit'])->toBe(1)
        ->and($json['entities']['form_submission'])->toBe(['read' => 6, 'written' => 4, 'skipped' => 0, 'failed' => 2])
        ->and(implode("\n", $json['failures']))->toContain('legacy form submission 4')->toContain('legacy form submission 5')
        ->and(fmiSubmission(1)->public_id)->toBe('123456');
});

it('adopts only an unused seed type by name; other same-name types stay apart', function () {
    formsImportSeed();
    $seeded = FormType::query()->pluck('id', 'name')->all();
    DB::table('form_submissions')->insert(['public_id' => '999999', 'form_type_id' => $seeded['Urlaubsantrag'], 'applicant_name' => 'X',
        'status' => 'new', 'answers' => '[]', 'submitted_at' => now()]);
    $hand = DB::table('form_types')->insertGetId(['name' => 'sonstiges', 'created_at' => now(), 'updated_at' => now()]);

    $json = LegacySeed::run(['form']);

    expect(formsImportId('form_type', 1))->toBe($seeded['Beförderungsantrag'])
        ->and(formsImportId('form_type', 2))->not->toBe($seeded['Urlaubsantrag'])->and(formsImportId('form_type', 4))->not->toBe($hand)
        ->and(FormType::findOrFail($seeded['Urlaubsantrag'])->name)->toBe('Urlaubsantrag')
        ->and(FormType::findOrFail($hand)->name)->toBe('sonstiges')
        ->and(FormType::findOrFail(formsImportId('form_type', 2))->is_absence)->toBeTrue()
        ->and(implode("\n", $json['warnings']))
        ->toContain('2 form type(s) named like a type of the new instance that is no unused seed type, imported as a separate type (legacy ids: 2, 4)');
});

it('rewrites knowledge base links to applications in the same run', function () {
    formsImportSeed();
    LegacySeed::kbEntries([['id' => 1, 'content' => '<p><a href="/forms/view?antrag=ABC">Antrag</a> <a href="/antrag/admin/view.php?antrag=123456">zwei</a></p>']]);

    LegacySeed::run(['kb', 'form']);

    $content = json_encode(json_decode((string) DB::table('kb_entries')->value('content')), JSON_UNESCAPED_SLASHES);
    expect($content)->toContain('/forms/'.fmiSubmission(3)->public_id)->toContain('/forms/admin/123456')->not->toContain('antrag=');
});

it('rewrites notification links to applications through the legacy number', function () {
    formsImportSeed();
    config(['ignis.import.legacy_base_path' => '/intra/']);
    LegacySeed::notifications([
        ['id' => 1, 'type' => 'antrag', 'link' => '/forms/view?antrag=123456'],
        ['id' => 2, 'type' => 'antrag', 'link' => '/intra/antrag/admin/view.php?antrag=ABC'],
        ['id' => 3, 'type' => 'antrag', 'link' => '/forms/view?antrag=999999'],
        ['id' => 4, 'type' => 'antrag', 'link' => '/forms/admin/list'],
        ['id' => 5, 'type' => 'antrag', 'link' => '/forms/admin/view?antrag=654321'],
        ['id' => 6, 'type' => 'antrag', 'link' => '/antraege/select'],
    ]);

    LegacySeed::run(['form', 'notification']);

    $link = fn (int $id) => json_decode((string) DB::table('notifications')->where('id', NotificationImporter::uuid($id))->value('data'), true)['link'];
    expect($link(1))->toBe('/forms/123456')
        ->and($link(2))->toBe('/forms/admin/'.fmiSubmission(3)->public_id)
        ->and($link(3))->toBe('/forms')
        ->and($link(4))->toBe('/forms/admin')
        ->and($link(5))->toBe('/forms/admin/'.fmiSubmission(4)->public_id)
        ->and($link(6))->toBe('/forms');
});

it('skips the entity when the legacy forms plugin was never installed', function () {
    LegacyDatabase::connection()->statement('RENAME TABLE intra_antraege_daten TO intra_antraege_daten_x');
    try {
        $json = LegacySeed::run(['form']);
    } finally {
        LegacyDatabase::connection()->statement('RENAME TABLE intra_antraege_daten_x TO intra_antraege_daten');
    }

    expect($json['exit'])->toBe(0)->and(implode("\n", $json['notes']))->toContain('legacy table intra_antraege_daten missing');
});
