<?php

namespace App\Events;

use App\Models\Document;

/** Dispatched after an issue committed. */
class DocumentIssued
{
    public function __construct(public readonly Document $document) {}
}
