<?php

namespace App\Documents;

use App\Documents\Nodes\DocField;
use App\Documents\Nodes\DocSection;
use App\Documents\Nodes\DocVariable;
use App\Documents\Nodes\PageBreak;
use Tiptap\Editor;
use Tiptap\Extensions\TextAlign;
use Tiptap\Marks;
use Tiptap\Nodes;

/**
 * Document JSON -> HTML for the PDF. Every document goes through DocumentValidator first, so only
 * allowlisted nodes, marks and checked attribute values reach tiptap-php; the schema below registers
 * exactly those nodes, so the tags come from a fixed map. Text and values are escaped by tiptap-php
 * (text) and by DocVariable/DocField (values); attribute values by tiptap-php's htmlentities.
 */
final class DocumentRenderer
{
    /**
     * @param  array<mixed>  $doc
     * @param  array<string, string>  $vars  resolved variables (VariableCatalog::resolve or frozen_values)
     * @param  bool  $blankUnresolved  unknown variables print nothing instead of `{{name}}` (PDF)
     *
     * @throws InvalidDocument
     */
    public function render(array $doc, array $vars, bool $blankUnresolved = false): RenderResult
    {
        $doc = DocumentValidator::validate($doc, false);
        $warnings = [];

        $editor = new Editor(['extensions' => [
            new Nodes\Text,
            new Nodes\Paragraph,
            new Nodes\Heading(['levels' => [1, 2, 3]]),
            new Nodes\BulletList,
            new Nodes\OrderedList,
            new Nodes\ListItem,
            new Nodes\Blockquote,
            new Nodes\Table,
            new Nodes\TableRow,
            new Nodes\TableCell,
            new Nodes\TableHeader,
            new Nodes\HorizontalRule,
            new Nodes\HardBreak,
            new Marks\Bold,
            new Marks\Italic,
            new Marks\Underline,
            new Marks\Strike,
            new TextAlign(['types' => ['heading', 'paragraph']]),
            new DocSection,
            new PageBreak,
            new DocVariable(['values' => $vars, 'blankUnresolved' => $blankUnresolved]),
            new DocField(['onRequiredEmpty' => function (string $label) use (&$warnings) {
                $warnings[] = 'Pflichtfeld leer: '.$label;
            }]),
        ]]);

        $html = $editor->setContent($doc)->getHTML();

        return new RenderResult($html, $warnings);
    }

    /**
     * Variable names in the document without a value, each once, in document order.
     *
     * @param  array<mixed>  $doc
     * @param  array<string, string>  $vars
     * @return list<string>
     */
    public static function unresolvedVariables(array $doc, array $vars): array
    {
        $names = [];
        array_walk_recursive($doc, function ($value, $key) use (&$names) {
            if ($key === 'name' && is_string($value)) {
                $names[] = $value;
            }
        });

        return array_values(array_unique(array_filter($names, fn (string $n) => ! array_key_exists($n, $vars))));
    }
}
