<?php

namespace Modules\KnowledgeBase\Content;

use Tiptap\Core\Node;

/**
 * tiptap-php `image` of the knowledge base: prints only a src of the upload pattern and alt, nothing else. The tag is
 * built here because tiptap-php leaves out empty attributes, and an image without alt text needs `alt=""`.
 */
final class KbImage extends Node
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
        if (! is_string($src) || preg_match(KbContentValidator::IMAGE_SRC, $src) !== 1) {
            return null;
        }
        $alt = $HTMLAttributes['alt'] ?? null;
        $e = fn (string $v) => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');

        return ['content' => '<img src="'.$e($src).'" alt="'.$e(is_string($alt) ? $alt : '').'" loading="lazy">'];
    }
}
