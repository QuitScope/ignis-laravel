<?php

use Modules\KnowledgeBase\Models\KbEntry;
use Modules\KnowledgeBase\Models\KbEntryRelation;

it('orders a relation pair smaller id first', function () {
    expect(KbEntryRelation::normalize(5, 2))->toBe([2, 5])->and(KbEntryRelation::normalize(2, 5))->toBe([2, 5]);
});

it('lists every section key of both types', function () {
    expect(KbEntry::allSectionKeys())->toEqualCanonicalizing([...KbEntry::SECTIONS_BY_TYPE['medication'], ...KbEntry::SECTIONS_BY_TYPE['measure']]);
});
