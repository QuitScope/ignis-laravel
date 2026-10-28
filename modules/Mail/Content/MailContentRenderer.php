<?php

namespace Modules\Mail\Content;

use Tiptap\Core\Mark;
use Tiptap\Core\Node;
use Tiptap\Editor;
use Tiptap\Marks;
use Tiptap\Nodes;

/**
 * Mail JSON -> HTML (the only output path of a mail text). Every document first goes through
 * MailContentValidator::normalize(), so only allowlisted nodes, marks and checked values reach tiptap-php, and the
 * schema below registers exactly those. tiptap-php escapes text and attribute values; the link and the badge image
 * print only their re-checked attributes. Every link opens in a new tab with rel="noopener noreferrer". Safe for the
 * one v-html of the reading pane.
 */
final class MailContentRenderer
{
    /** @param array<mixed> $doc */
    public static function html(array $doc): string
    {
        $link = new class extends Mark
        {
            /** @var string */
            public static $name = 'link';

            /** @return array<string, array<never>> */
            public function addAttributes()
            {
                return ['href' => []];
            }

            /**
             * @param  object  $mark
             * @param  array<string, mixed>  $HTMLAttributes
             * @return array<int, mixed>
             */
            public function renderHTML($mark, $HTMLAttributes = [])
            {
                $href = $HTMLAttributes['href'] ?? null;

                return MailContentValidator::validHref($href)
                    ? ['a', ['href' => $href, 'target' => '_blank', 'rel' => 'noopener noreferrer'], 0]
                    : ['a', [], 0];
            }
        };
        $image = new class extends Node
        {
            /** @var string */
            public static $name = 'image';

            /** @return array<string, array<never>> */
            public function addAttributes()
            {
                return ['src' => [], 'alt' => []];
            }

            /**
             * @param  object  $node
             * @param  array<string, mixed>  $HTMLAttributes
             * @return array{content: string}|null
             */
            public function renderHTML($node, $HTMLAttributes = [])
            {
                $src = $HTMLAttributes['src'] ?? null;
                if (! MailContentValidator::validImageSrc($src)) {
                    return null;
                }
                $alt = $HTMLAttributes['alt'] ?? null;
                $e = fn (string $v) => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');

                /** @var string $src */
                return ['content' => '<img class="mail-badge" src="'.$e($src).'" alt="'.$e(is_string($alt) ? $alt : '').'">'];
            }
        };

        $editor = new Editor(['extensions' => [
            new Nodes\Text,
            new Nodes\Paragraph,
            new Nodes\HardBreak,
            new Nodes\Blockquote,
            new Nodes\BulletList,
            new Nodes\OrderedList,
            new Nodes\ListItem,
            $image,
            new Marks\Bold,
            new Marks\Italic,
            new Marks\Strike,
            $link,
        ]]);

        return $editor->setContent(MailContentValidator::normalize($doc))->getHTML();
    }
}
