<?php

use App\Documents\DocumentValidator;
use App\Documents\SystemTemplates;
use App\Models\DocumentTemplate;
use Database\Seeders\DocumentTemplatesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('seeds the ten templates once', function () {
    $this->seed(DocumentTemplatesSeeder::class);
    $this->seed(DocumentTemplatesSeeder::class);

    expect(DocumentTemplate::count())->toBe(10)
        ->and(DocumentTemplate::pluck('name')->all())->toEqualCanonicalizing(array_keys(SystemTemplates::all()));
    DocumentTemplate::all()->each(fn ($t) => DocumentValidator::validate($t->content, true));
});

it('keeps templates the operator edited', function () {
    $this->seed(DocumentTemplatesSeeder::class);
    DocumentTemplate::where('name', 'Dienstentfernung')->update(['category' => 'Eigene', 'is_active' => false]);

    $this->seed(DocumentTemplatesSeeder::class);

    $t = DocumentTemplate::where('name', 'Dienstentfernung')->sole();
    expect($t->category)->toBe('Eigene')->and($t->is_active)->toBeFalse()->and(DocumentTemplate::count())->toBe(10);
});

it('matches existing names case-insensitively', function () {
    DocumentTemplate::create(['name' => 'DIENSTENTFERNUNG', 'content' => ['type' => 'doc', 'content' => []]]);

    $this->seed(DocumentTemplatesSeeder::class);

    expect(DocumentTemplate::count())->toBe(10);
});

it('restores only a deleted template', function () {
    $this->seed(DocumentTemplatesSeeder::class);
    $kept = DocumentTemplate::where('name', 'Ernennungsurkunde')->sole()->id;
    DocumentTemplate::where('name', 'Dienstentfernung')->delete();

    $this->seed(DocumentTemplatesSeeder::class);

    expect(DocumentTemplate::count())->toBe(10)
        ->and(DocumentTemplate::where('name', 'Ernennungsurkunde')->sole()->id)->toBe($kept);
});
