<?php

namespace App\Documents\Nodes;

use Tiptap\Core\Node;

/** Forced page break (block atom): `<div class="efe-page-break"></div>`; the print CSS breaks on it. */
class PageBreak extends Node
{
    /** @var string */
    public static $name = 'pageBreak';

    /**
     * @param  object  $node
     * @param  array<string, mixed>  $HTMLAttributes
     * @return array<int, mixed>
     */
    public function renderHTML($node, $HTMLAttributes = [])
    {
        return ['div', ['class' => 'efe-page-break'], 0];
    }
}
