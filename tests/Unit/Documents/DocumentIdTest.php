<?php

use App\Documents\DocumentId;
use App\Models\Document;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('generates seven digits, leading zeros included', function () {
    $ids = array_map(fn () => DocumentId::generate(), range(1, 200));

    expect($ids)->each->toMatch('/^\d{7}$/D');
});

it('tries a new id after a collision on the unique index', function () {
    $taken = Document::factory()->create(['public_id' => '1234567']);
    $tried = [];

    $document = DocumentId::create(function (string $id) use (&$tried, $taken) {
        $tried[] = $id;

        return Document::factory()->create(['public_id' => count($tried) === 1 ? $taken->public_id : $id, 'personnel_id' => $taken->personnel_id]);
    });

    expect($tried)->toHaveCount(2)
        ->and($document->public_id)->toBe($tried[1])
        ->and(Document::count())->toBe(2);
});

it('gives up after three collisions', function () {
    $attempts = 0;

    try {
        DocumentId::create(function () use (&$attempts) {
            $attempts++;
            throw new UniqueConstraintViolationException('sqlite', 'insert', [], new PDOException('UNIQUE'));
        });
    } finally {
        expect($attempts)->toBe(3);
    }
})->throws(UniqueConstraintViolationException::class);

it('does not retry other errors', function () {
    $attempts = 0;

    try {
        DocumentId::create(function () use (&$attempts) {
            $attempts++;
            throw new RuntimeException('kaputt');
        });
    } finally {
        expect($attempts)->toBe(1);
    }
})->throws(RuntimeException::class, 'kaputt');
