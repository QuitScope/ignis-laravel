<?php

use App\Models\Personnel;
use App\Models\PersonnelLogEntry;
use App\Personnel\PersonnelLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('writes entries with the legacy wording, types and author', function () {
    $p = Personnel::factory()->create();
    $log = new PersonnelLogger;

    $log->created($p, 'A');
    $log->rankChanged($p, 'Brandmeister', 'Oberbrandmeister', 'A');
    $log->qualificationChanged($p, 'RD', 'Keine', 'Notfallsanitäter', 'A');
    $log->modified($p, 'A');
    $log->specialtiesModified($p, 'A');
    $log->note($p, PersonnelLogEntry::POSITIVE, 'Gut gemacht', 'A');

    $rows = PersonnelLogEntry::orderBy('id')->get();
    expect($rows->pluck('type')->all())->toBe([6, 4, 5, 5, 5, 1])
        ->and($rows->pluck('content')->all())->toBe([
            'Mitarbeiterprofil wurde angelegt.',
            'Dienstgrad wurde von Brandmeister auf Oberbrandmeister geändert.',
            'Qualifikation (RD) wurde von Keine auf Notfallsanitäter geändert.',
            'Profildaten wurden bearbeitet.',
            'Fachdienste wurden bearbeitet.',
            'Gut gemacht',
        ])
        ->and($rows->pluck('author')->unique()->all())->toBe(['A'])
        ->and($rows[1]->metadata)->toBe(['change_type' => 'rank', 'old_value' => 'Brandmeister', 'new_value' => 'Oberbrandmeister']);
});

it('accepts only note types for manual notes', function () {
    (new PersonnelLogger)->note(Personnel::factory()->create(), PersonnelLogEntry::RANK_CHANGE, 'x', 'A');
})->throws(InvalidArgumentException::class);

it('deletes an entry', function () {
    $entry = (new PersonnelLogger)->note(Personnel::factory()->create(), 0, 'weg', 'A');

    expect((new PersonnelLogger)->delete($entry))->toBeTrue()->and(PersonnelLogEntry::count())->toBe(0);
});
