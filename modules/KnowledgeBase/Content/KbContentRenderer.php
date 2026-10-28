<?php

namespace Modules\KnowledgeBase\Content;

use Tiptap\Editor;
use Tiptap\Marks;
use Tiptap\Nodes;

/**
 * Knowledge base JSON -> HTML / plain text. Every document first goes through KbContentValidator::normalize(), so only
 * allowlisted nodes, marks and checked attribute values reach tiptap-php, and the schema below registers exactly
 * those (fixed tag map). tiptap-php escapes text (htmlspecialchars) and attribute values (htmlentities). Links and
 * images use KbLink/KbImage, which print only their checked attributes. The HTML is safe for v-html.
 */
final class KbContentRenderer
{
    /** @param array<mixed> $doc */
    public static function html(array $doc): string
    {
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
            new KbImage,
            new Marks\Bold,
            new Marks\Italic,
            new Marks\Underline,
            new Marks\Strike,
            new KbLink,
        ]]);

        return $editor->setContent(KbContentValidator::normalize($doc))->getHTML();
    }

    /**
     * Text for `search_text`: every block on its own line, hard breaks as line breaks, no markup, nothing escaped.
     *
     * @param  array<mixed>  $doc
     */
    public static function plainText(array $doc): string
    {
        $lines = explode("\n", self::text(KbContentValidator::normalize($doc)));

        return implode("\n", array_filter(array_map('trim', $lines), fn (string $l) => $l !== ''));
    }

    /** @param  array<string, mixed>  $node  normalized */
    private static function text(array $node): string
    {
        return match ($node['type']) {
            'text' => $node['text'],
            'hardBreak' => "\n",
            default => implode('', array_map(self::text(...), $node['content'] ?? []))."\n",
        };
    }
}
