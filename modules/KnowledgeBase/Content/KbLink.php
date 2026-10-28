<?php

namespace Modules\KnowledgeBase\Content;

use Tiptap\Core\Mark;

/** tiptap-php `link` of the knowledge base: href re-checked, rel="noopener noreferrer" whenever target is _blank. */
final class KbLink extends Mark
{
    /** @var string */
    public static $name = 'link';

    /** @return array<string, array<never>> */
    public function addAttributes()
    {
        return ['href' => [], 'target' => []];
    }

    /**
     * @param  object  $mark
     * @param  array<string, mixed>  $HTMLAttributes
     * @return array<int, mixed>
     */
    public function renderHTML($mark, $HTMLAttributes = [])
    {
        $href = $HTMLAttributes['href'] ?? null;
        $attrs = KbContentValidator::validHref($href) ? ['href' => $href] : [];
        if (($HTMLAttributes['target'] ?? null) === '_blank') {
            $attrs += ['target' => '_blank', 'rel' => 'noopener noreferrer'];
        }

        return ['a', $attrs, 0];
    }
}
