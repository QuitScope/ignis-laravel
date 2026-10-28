<?php

namespace App\Documents;

/** Escaped HTML of a document plus warnings such as "Pflichtfeld leer: <Label>". */
final class RenderResult
{
    /** @param list<string> $warnings */
    public function __construct(public readonly string $html, public readonly array $warnings) {}
}
