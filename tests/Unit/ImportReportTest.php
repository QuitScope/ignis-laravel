<?php

use App\Import\ImportReport;

it('counts per entity and renders a table with warnings', function () {
    $r = new ImportReport;
    $r->read('role', 3);
    $r->written('role', 2);
    $r->skipped('role', 'no name', ['id' => 4]);
    $r->failed('user', 'boom');
    $r->warn('careful');
    $r->note('adopted');

    expect($r->toArray())->toMatchArray([
        'entities' => [
            'role' => ['read' => 3, 'written' => 2, 'skipped' => 1, 'failed' => 0],
            'user' => ['read' => 0, 'written' => 0, 'skipped' => 0, 'failed' => 1],
        ],
        'warnings' => ['careful'],
        'notes' => ['adopted'],
    ])->and($r->hasFailures())->toBeTrue();

    $out = $r->render();
    expect($out)->toContain('role')->toContain('careful')->toContain('boom');
});

it('has no failures by default', function () {
    expect((new ImportReport)->hasFailures())->toBeFalse();
});
