<?php

namespace App\Documents\Nodes;

use Tiptap\Core\Node;

/** A template section: `<section class="efe-section">`. mode/ids/title/hint are editor data, never HTML. */
class DocSection extends Node
{
    /** @var string */
    public static $name = 'docSection';

    /**
     * @param  object  $node
     * @param  array<string, mixed>  $HTMLAttributes
     * @return array<int, mixed>
     */
    public function renderHTML($node, $HTMLAttributes = [])
    {
        return ['section', ['class' => 'efe-section'], 0];
    }
}
