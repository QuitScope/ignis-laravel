<?php

use App\Models\EmsQualification;
use App\Models\FireQualification;
use App\Models\PersonnelTitle;
use App\Models\Rank;
use App\Models\Specialty;
use Database\Seeders\PersonnelDefaultsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('seeds the legacy defaults', function () {
    $this->seed(PersonnelDefaultsSeeder::class);

    expect(Rank::count())->toBe(19)
        ->and(Rank::where('is_archive', true)->pluck('name')->all())->toBe(['Entlassen/Archiv'])
        ->and(Rank::where('name', 'Hauptbrandmeister/-in mit AZ')->first()->name_female)->toBe('Hauptbrandmeisterin mit AZ')
        ->and(Rank::where('name', 'Brandinspektoranwärter/-in')->first()->priority)->toBe(7)
        ->and(FireQualification::count())->toBe(7)
        ->and(FireQualification::where('is_none', true)->pluck('name')->all())->toBe(['Keine'])
        ->and(EmsQualification::count())->toBe(7)
        ->and(EmsQualification::where('is_none', true)->pluck('name')->all())->toBe(['Keine'])
        ->and(EmsQualification::where('is_trainable', true)->pluck('abbreviation')->all())->toEqualCanonicalizing(['RettSan', 'NotSan'])
        ->and(EmsQualification::where('name', 'Notarzt/ärztin')->first()->abbreviation)->toBe('Notarzt')
        ->and(Specialty::count())->toBe(13)
        ->and(Specialty::where('number', 232)->first()->name)->toBe('CBRN-SChutz')
        ->and(PersonnelTitle::orderBy('priority')->pluck('name')->all())->toBe(['Dr.', 'Dr. med.', 'Prof.', 'Prof. Dr.']);
});

it('is idempotent', function () {
    $this->seed(PersonnelDefaultsSeeder::class);
    $this->seed(PersonnelDefaultsSeeder::class);

    expect(Rank::count())->toBe(19)->and(FireQualification::count())->toBe(7)
        ->and(EmsQualification::count())->toBe(7)->and(Specialty::count())->toBe(13)->and(PersonnelTitle::count())->toBe(4);
});
