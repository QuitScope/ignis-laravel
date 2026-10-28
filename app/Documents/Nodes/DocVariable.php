<?php

namespace App\Documents\Nodes;

use Tiptap\Core\Node;

/**
 * A placeholder chip (inline atom): `<span class="efe-variable">value</span>`. Options: `values`
 * (name => value) and `blankUnresolved`; an unknown name shows as `{{name}}` or stays empty.
 */
class DocVariable extends Node
{
    /** @var string */
    public static $name = 'docVariable';

    /** @return array<string, mixed> */
    public function addOptions()
    {
        return ['values' => [], 'blankUnresolved' => false];
    }

    /**
     * @param  object  $node
     * @param  array<string, mixed>  $HTMLAttributes
     * @return array<int, mixed>
     */
    public function renderHTML($node, $HTMLAttributes = [])
    {
        return ['span', ['class' => 'efe-variable'], 0];
    }

    /** @param object{attrs?: object{name?: string}} $node */
    public function renderText($node): string
    {
        $name = (string) ($node->attrs->name ?? '');
        $value = $this->options['values'][$name] ?? ($this->options['blankUnresolved'] ? '' : '{{'.$name.'}}');

        return htmlspecialchars(is_scalar($value) ? (string) $value : '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
