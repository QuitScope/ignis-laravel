<?php

use App\Models\EmsQualification;
use App\Models\FireQualification;
use App\Models\Personnel;
use App\Models\PersonnelTitle;
use App\Models\Rank;
use App\Models\Specialty;
use Database\Seeders\PersonnelDefaultsSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\Support\LegacyDatabase;
use Tests\Support\LegacySeed;

uses(RefreshDatabase::class)->group('legacy');

beforeEach(function () {
    if (! LegacySeed::boot()) {
        $this->markTestSkipped('LEGACY_TEST_DB_HOST not set');
    }
    $this->seed(RoleSeeder::class);
    $this->seed(PersonnelDefaultsSeeder::class);
    Storage::fake('profile-pictures');
    $this->uploads = sys_get_temp_dir().'/ignis-legacy-uploads-'.bin2hex(random_bytes(6));
    File::ensureDirectoryExists($this->uploads.'/storage/profile-pictures');
    foreach (['a.png' => 'png', 'b.jpeg' => 'jpeg'] as $name => $type) {
        $image = imagecreatetruecolor(4, 4);
        $type === 'png' ? imagepng($image, $this->uploads.'/storage/profile-pictures/'.$name) : imagejpeg($image, $this->uploads.'/storage/profile-pictures/'.$name);
        imagedestroy($image);
    }
    file_put_contents($this->uploads.'/secret.png', (string) file_get_contents($this->uploads.'/storage/profile-pictures/a.png'));
});

afterEach(fn () => isset($this->uploads) && File::deleteDirectory($this->uploads));

function seedPersonnelCases(): void
{
    LegacySeed::personnel([
        ['id' => 1, 'fullname' => 'Anna Alpha', 'titel_id' => 50, 'geschlecht' => 1, 'forumprofil' => 42, 'discordtag' => '123456789012345678',
            'telefonnr' => '555-1', 'dienstnr' => 'A-100', 'gebdatum' => '1990-05-01', 'einstdatum' => '2020-01-02', 'charakterid' => 'char1',
            'dienstgrad' => 10, 'qualifw2' => 21, 'qualird' => 31, 'zusatz' => 'Taucher', 'fachdienste' => '["211","413","777"]',
            'pfp' => '/storage/profile-pictures/a.png', 'createdate' => '2024-07-15 12:00:00'],
        ['id' => 2, 'dienstnr' => ' A-100'],
        ['id' => 3, 'dienstnr' => '300', 'dienstgrad' => 11, 'discordtag' => 'name#1234', 'pfp' => '/assets/img/empty_user.png', 'qualifw2' => 999, 'fachdienste' => '[]'],
        ['id' => 4, 'dienstnr' => '400', 'dienstgrad' => 12, 'pfp' => '/storage/profile-pictures/../../secret.png'],
        ['id' => 5, 'dienstnr' => '500', 'dienstgrad' => 999],
        ['id' => 6, 'dienstnr' => '600', 'pfp' => '/intra/storage/profile-pictures/b.jpeg', 'fachdienste' => '[211]'],
    ]);
}

/** @param array<string, mixed> $json */
function personnelWarnings(array $json): string
{
    return implode("\n", $json['warnings']);
}

/** @return array<string, int> */
function personnelCounts(): array
{
    return collect(['ranks', 'fire_qualifications', 'ems_qualifications', 'specialties', 'personnel_titles', 'personnel', 'personnel_specialty', 'personnel_log_entries', 'legacy_id_map', 'users', 'registration_codes'])
        ->mapWithKeys(fn (string $t) => [$t => DB::table($t)->count()])->all();
}

it('imports the lookup tables, adopting installer rows by name and keeping duplicates apart', function () {
    $ranksBefore = Rank::count();

    $json = LegacySeed::run(['rank', 'fire_qualification', 'ems_qualification', 'specialty', 'personnel_title']);

    $rank = Rank::find(DB::table('legacy_id_map')->where(['entity' => 'rank', 'legacy_id' => '10'])->value('new_id'));
    $archive = Rank::find(DB::table('legacy_id_map')->where(['entity' => 'rank', 'legacy_id' => '11'])->value('new_id'));
    $ems = EmsQualification::find(DB::table('legacy_id_map')->where(['entity' => 'ems_qualification', 'legacy_id' => '31'])->value('new_id'));
    $fire = FireQualification::find(DB::table('legacy_id_map')->where(['entity' => 'fire_qualification', 'legacy_id' => '20'])->value('new_id'));
    $disabled = Specialty::find(DB::table('legacy_id_map')->where(['entity' => 'specialty', 'legacy_id' => '42'])->value('new_id'));
    $titles = DB::table('legacy_id_map')->where('entity', 'personnel_title')->orderBy('legacy_id')->pluck('new_id')->all();

    expect($json['exit'])->toBe(0)
        ->and(Rank::count())->toBe($ranksBefore + 1)
        ->and($rank->name)->toBe('brandmeister/-in')->and($rank->priority)->toBe(3)->and($rank->badge)->toBe('/b.png')
        ->and($rank->name_male)->toBe('Brandmeister')->and($rank->name_female)->toBe('Brandmeisterin')->and($rank->is_archive)->toBeFalse()
        ->and($archive->is_archive)->toBeTrue()
        ->and($ems->abbreviation)->toBe('NFS')->and($ems->is_trainable)->toBeTrue()->and($ems->is_none)->toBeFalse()
        ->and($fire->is_none)->toBeTrue()->and($fire->short_name)->toBe('-')
        ->and($disabled->number)->toBe(999)->and($disabled->is_disabled)->toBeTrue()
        ->and($titles)->toHaveCount(2)->and($titles[0])->not->toBe($titles[1])
        ->and(PersonnelTitle::whereRaw('lower(name) = ?', ['dr.'])->count())->toBe(2)
        ->and(implode("\n", $json['notes']))->toContain('adopted')
        ->and(personnelWarnings($json))->toContain('only in the new instance');
});

it('imports personnel with mappings, specialties, archive rank and the edge cases', function () {
    seedPersonnelCases();

    $json = LegacySeed::run(['personnel'], uploads: $this->uploads);

    $anna = Personnel::where('service_number', 'A-100')->with('specialties', 'title', 'rank')->first();
    $three = Personnel::where('service_number', '300')->with('rank', 'fireQualification')->first();
    $four = Personnel::where('service_number', '400')->first();
    $six = Personnel::where('service_number', '600')->with('specialties')->first();

    expect($json['entities']['personnel'])->toBe(['read' => 6, 'written' => 4, 'skipped' => 1, 'failed' => 1])
        ->and(Personnel::count())->toBe(4)
        ->and($anna->full_name)->toBe('Anna Alpha')->and($anna->title->name)->toBe('Dr.')
        ->and($anna->gender)->toBe(1)->and($anna->forum_profile)->toBe(42)->and($anna->discord_id)->toBe('123456789012345678')
        ->and($anna->phone)->toBe('555-1')->and($anna->character_id)->toBe('char1')->and($anna->extra_qualification)->toBe('Taucher')
        ->and($anna->birth_date->format('Y-m-d'))->toBe('1990-05-01')->and($anna->hired_at->format('Y-m-d'))->toBe('2020-01-02')
        ->and($anna->rank->name_male)->toBe('Brandmeister')
        ->and($anna->specialties->pluck('number')->sort()->values()->all())->toBe([211, 413])
        ->and(DB::table('personnel')->where('id', $anna->id)->value('created_at'))->toBe('2024-07-15 10:00:00')
        ->and($anna->profile_picture)->toMatch(Personnel::PICTURE_PATTERN)
        ->and(Storage::disk('profile-pictures')->exists($anna->profile_picture))->toBeTrue()
        ->and($three->rank->is_archive)->toBeTrue()->and($three->discord_id)->toBeNull()
        ->and($three->fireQualification->is_none)->toBeTrue()->and($three->profile_picture)->toBeNull()
        ->and($four->profile_picture)->toBeNull()
        ->and($six->profile_picture)->toEndWith('.jpg')
        ->and($six->specialties->pluck('number')->all())->toBe([211])
        ->and(Storage::disk('profile-pictures')->allFiles())->toHaveCount(2)
        ->and(implode("\n", $json['skips']))->toContain('"legacy_id":"2"')->toContain('"kept_legacy_id":"1"')
        ->and(implode("\n", $json['failures']))->toContain('legacy personnel 5');
    $warnings = personnelWarnings($json);
    expect($warnings)->toContain('1 personnel record(s): discord tag is not a Discord ID (17-20 digits), imported as empty')
        ->toContain('fire qualification not imported, "Keine" used')
        ->toContain('1 unknown specialty number(s)')
        ->toContain('picture file missing or invalid');
});

it('adopts an existing record with the same service number', function () {
    $existing = Personnel::factory()->create(['service_number' => '300', 'full_name' => 'Alt']);
    LegacySeed::personnel([['id' => 3, 'dienstnr' => '300', 'fullname' => 'Neu']]);

    $json = LegacySeed::run(['personnel']);

    expect(Personnel::count())->toBe(1)->and($existing->fresh()->full_name)->toBe('Neu')
        ->and(implode("\n", $json['notes']))->toContain('adopted existing record');
});

it('counts pictures it cannot copy without --uploads and keeps the stored ones', function () {
    seedPersonnelCases();
    LegacySeed::run(['personnel'], uploads: $this->uploads);
    $before = Personnel::where('service_number', 'A-100')->value('profile_picture');

    $json = LegacySeed::run(['personnel']);

    expect(implode("\n", $json['notes']))->toContain('3 Profilbilder nicht kopiert')
        ->and(Personnel::where('service_number', 'A-100')->value('profile_picture'))->toBe($before)
        ->and(Storage::disk('profile-pictures')->allFiles())->toHaveCount(2);
});

it('imports the personnel log in blocks, converts html and is idempotent', function () {
    seedPersonnelCases();
    $rows = [];
    for ($i = 1; $i <= 2500; $i++) {
        $rows[] = ['logid' => $i, 'profilid' => 1, 'datetime' => sprintf('2024-01-%02d 10:00:00', ($i % 28) + 1)];
    }
    $rows[0] = ['logid' => 1, 'profilid' => 1, 'type' => 4, 'content' => 'Dienstgrad wurde von <strong>A &amp; B</strong> auf <strong>C</strong> geändert.', 'metadata' => '{"change_type":"rank"}', 'datetime' => '2026-07-15 12:00:00'];
    $rows[1] = ['logid' => 2, 'profilid' => 1, 'type' => 0, 'content' => 'Ich <3 Feuerwehr'];
    $rows[2] = ['logid' => 3, 'profilid' => 1, 'type' => 9, 'metadata' => '{oops'];
    $rows[3] = ['logid' => 4, 'profilid' => 5];
    $rows[4] = ['logid' => 5, 'profilid' => 77];
    $rows[5] = ['logid' => 6, 'profilid' => 77];
    LegacySeed::personnelLog($rows);

    $json = LegacySeed::run(['personnel_log'], uploads: $this->uploads);
    $counts = personnelCounts();
    $again = LegacySeed::run(['personnel_log'], uploads: $this->uploads);

    $entry = fn (int $id) => DB::table('personnel_log_entries')->where('legacy_id', $id)->first();
    expect($json['entities']['personnel_log'])->toBe(['read' => 2500, 'written' => 2497, 'skipped' => 3, 'failed' => 0])
        ->and(implode("\n", $json['skips']))->not->toContain('personnel_log')
        ->and(collect($json['warnings'])->filter(fn ($w) => str_contains($w, 'log entry(ies) of')))->toHaveCount(1)
        ->and(personnelWarnings($json))->toContain('3 log entry(ies) of 2 personnel record(s) that were not imported were skipped (profilid: 5, 77)')
        ->and($json['notes'])->toContain('personnel_log: 3 chunks of up to 1000 rows')
        ->and($entry(1)->content)->toBe('Dienstgrad wurde von A & B auf C geändert.')
        ->and($entry(1)->created_at)->toBe('2026-07-15 10:00:00')
        ->and(json_decode($entry(1)->metadata, true))->toBe(['change_type' => 'rank'])
        ->and($entry(1)->author)->toBe('Admin')
        ->and($entry(2)->content)->toBe('Ich <3 Feuerwehr')
        ->and((int) $entry(3)->type)->toBe(9)->and($entry(3)->metadata)->toBeNull()
        ->and($entry(4))->toBeNull()
        ->and(personnelWarnings($json))->toContain('1 log entry(ies) with an unknown type')->toContain('1 log entry(ies) had invalid metadata')
        ->and($again['entities']['personnel_log'])->toBe($json['entities']['personnel_log'])
        ->and(personnelCounts())->toBe($counts);
});

it('runs everything twice with identical counts and copies each picture once', function () {
    seedPersonnelCases();
    LegacySeed::personnelLog([['logid' => 1, 'profilid' => 1]]);

    $first = LegacySeed::run(uploads: $this->uploads);
    $counts = personnelCounts();
    $files = Storage::disk('profile-pictures')->allFiles();
    $second = LegacySeed::run(uploads: $this->uploads);

    unset($first['entities']['auth_settings'], $second['entities']['auth_settings']);
    expect($second['entities'])->toBe($first['entities'])
        ->and(personnelCounts())->toBe($counts)
        ->and(Storage::disk('profile-pictures')->allFiles())->toBe($files);
});

it('dry run leaves no trace and copies no files', function () {
    seedPersonnelCases();
    LegacySeed::personnelLog([['logid' => 1, 'profilid' => 1]]);
    $before = personnelCounts();

    $json = LegacySeed::run(dry: true, uploads: $this->uploads);

    expect($json['entities']['personnel']['written'])->toBe(4)
        ->and(personnelCounts())->toBe($before)
        ->and(Storage::disk('profile-pictures')->allFiles())->toBe([]);
});

it('rejects an --uploads folder that does not exist or has no storage/profile-pictures', function () {
    File::ensureDirectoryExists($this->uploads.'/empty');

    $missing = LegacySeed::run(['personnel'], uploads: $this->uploads.'/nope');
    $noPictures = Artisan::call('ignis:import-legacy', ['--only' => ['personnel'], '--uploads' => $this->uploads.'/empty']);

    expect($missing['exit'])->toBe(1)->and($noPictures)->toBe(1)
        ->and(Artisan::output())->toContain('storage/profile-pictures')
        ->and(Personnel::count())->toBe(0);
});

it('retries a failing log block row by row so one bad row costs one failure', function () {
    seedPersonnelCases();
    $rows = [];
    for ($i = 1; $i <= 10; $i++) {
        $rows[] = ['logid' => $i, 'profilid' => 1, 'content' => $i === 7 ? 'BOOM' : "Eintrag {$i}"];
    }
    LegacySeed::personnelLog($rows);
    LegacySeed::run(['personnel']);
    DB::connection()->beforeExecuting(function (string $sql, array $bindings) {
        if (str_contains($sql, 'personnel_log_entries') && in_array('BOOM', $bindings, true)) {
            throw new RuntimeException('simulated bad row');
        }
    });

    $json = LegacySeed::run(['personnel_log']);

    expect($json['entities']['personnel_log'])->toBe(['read' => 10, 'written' => 9, 'skipped' => 0, 'failed' => 1])
        ->and(implode("\n", $json['failures']))->toContain('legacy log 7')
        ->and(DB::table('personnel_log_entries')->count())->toBe(9);
});

it('adopts a renamed legacy default specialty by number and falls back to the name', function () {
    $db = LegacyDatabase::connection();
    $db->table('intra_mitarbeiter_fdquali')->where('id', 40)->update(['sgname' => 'Leitstelle (umbenannt)']);
    $db->table('intra_mitarbeiter_fdquali')->where('id', 41)->update(['sgnr' => 499]);
    $before = Specialty::count();
    LegacySeed::personnel([['id' => 1, 'dienstnr' => '100', 'fachdienste' => '["211","499"]']]);

    LegacySeed::run(['personnel']);

    $person = Personnel::with('specialties')->first();
    expect(Specialty::count())->toBe($before + 1)
        ->and(Specialty::where('number', 211)->pluck('name')->all())->toBe(['Leitstelle (umbenannt)'])
        ->and(Specialty::where('name', 'Luftrettung')->pluck('number')->all())->toBe([499])
        ->and(Specialty::where('number', 413)->count())->toBe(0)
        ->and($person->specialties->pluck('number')->sort()->values()->all())->toBe([211, 499]);
});

it('ignores a picture url with a scheme or host', function () {
    LegacySeed::personnel([
        ['id' => 1, 'dienstnr' => '100', 'pfp' => 'https://other.host/storage/profile-pictures/a.png'],
        ['id' => 2, 'dienstnr' => '200', 'pfp' => '//other.host/storage/profile-pictures/a.png'],
    ]);

    $json = LegacySeed::run(['personnel'], uploads: $this->uploads);

    expect(Personnel::whereNotNull('profile_picture')->count())->toBe(0)
        ->and(Storage::disk('profile-pictures')->allFiles())->toBe([])
        ->and(personnelWarnings($json))->toContain('2 personnel record(s): picture path not recognised');
});
