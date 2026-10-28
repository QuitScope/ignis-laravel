<?php

namespace App\Documents;

/** What SectionGuard made of a posted document. */
final class GuardResult
{
    /**
     * @param  array<string, mixed>  $document
     * @param  list<string>  $warnings  what was neutralised, for the log
     */
    public function __construct(public readonly array $document, public readonly array $warnings) {}
}
