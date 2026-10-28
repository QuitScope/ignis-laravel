<?php

namespace Modules\KnowledgeBase\Import;

use Closure;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use InvalidArgumentException;
use Modules\KnowledgeBase\Content\KbContentValidator;

/**
 * Legacy knowledge base HTML (CKEditor and the legacy editor) => Tiptap JSON, by the legacy allowlist
 * (KBHelper::sanitizeContent). Pure: no database, no files.
 *
 * - Kept: p, br, hr, strong/b, em/i, u, s, h2-h4 (=> heading 1-3; CKEditor stored the editor's levels one lower),
 *   ul/ol/li (nested), blockquote, a, img, figure (unwrapped), table/thead/tbody/tfoot/tr/th/td (colspan/rowspan 1-999).
 * - h1 => heading 1, h5/h6 => heading 3 (counted in $headings, they are outside the allowlist).
 * - script, style, iframe, svg, object, ... go WITH their content; every other element is unwrapped (its text
 *   stays). Both are counted per element name in $dropped. Attributes are never copied except href/target, src/alt,
 *   colspan/rowspan, start/type.
 * - Links: control characters are removed, then $rewriteHref maps the target (null: the importer could not resolve it
 *   and counts that itself; the text stays). A result outside http(s)/mailto/relative `/...` drops the link mark, its
 *   text stays ($droppedLinks).
 * - Images: $rewriteImageSrc maps the source (null: the importer reports it); the result must be
 *   `/storage/kb-images/<32hex>.(jpg|png|webp|gif)` or the image goes ($droppedImages). Images are block nodes: an
 *   image inside a paragraph splits it.
 * - Whitespace: runs of HTML whitespace collapse to one blank, blanks at the start/end of a block and around hard
 *   breaks go, whitespace-only text between blocks makes no paragraph. Non-breaking spaces stay.
 * The result always went through KbContentValidator::normalize() (so it passes validate()); what normalize() cuts
 * beyond MAX_DEPTH / MAX_NODES is counted in $truncatedDepth / $truncatedNodes. Input over 8 MB or a result over the
 * validator's 2 MB throws InvalidArgumentException.
 */
final class LegacyHtmlToTiptap
{
    public const DROP = [
        'script', 'style', 'iframe', 'frame', 'frameset', 'object', 'applet', 'noscript', 'noembed', 'noframes',
        'template', 'svg', 'math', 'textarea', 'select', 'title', 'xmp',
    ];

    private const BLOCKY = [
        'div', 'section', 'article', 'header', 'footer', 'main', 'aside', 'nav', 'address', 'center', 'pre', 'dl', 'dt',
        'dd', 'details', 'summary', 'form', 'fieldset', 'legend', 'caption', 'figcaption', 'menu', 'dir', 'listing',
        'plaintext', 'hgroup', 'search', 'dialog', 'option', 'optgroup', 'label', 'button', 'output', 'video', 'audio',
        'picture', 'canvas', 'map', 'body', 'html', 'head',
    ];

    private const STRAY = ['li', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th'];

    private const MARKS = ['strong' => 'bold', 'b' => 'bold', 'em' => 'italic', 'i' => 'italic', 'u' => 'underline', 's' => 'strike'];

    /** @var array<string, int> element name => count of elements outside the allowlist (removed or unwrapped) */
    public array $dropped = [];

    public int $droppedLinks = 0;

    public int $droppedImages = 0;

    /** @var array<string, int> h1/h5/h6 => count; kept as heading 1 resp. 3 */
    public array $headings = [];

    public int $truncatedDepth = 0;

    public int $truncatedNodes = 0;

    public const MAX_INPUT_BYTES = 8 * 1024 * 1024;

    /** @var Closure(string): ?string */
    private Closure $rewriteHref;

    /** @var Closure(string): ?string */
    private Closure $rewriteImageSrc;

    private ?DOMNode $wrapperMeta = null;

    /**
     * @param  ?Closure(string): ?string  $rewriteHref  default: unchanged
     * @param  ?Closure(string): ?string  $rewriteImageSrc  default: unchanged
     */
    public function __construct(?Closure $rewriteHref = null, ?Closure $rewriteImageSrc = null)
    {
        $this->rewriteHref = $rewriteHref ?? fn (string $href): string => $href;
        $this->rewriteImageSrc = $rewriteImageSrc ?? fn (string $src): string => $src;
    }

    /**
     * The Tiptap document of $html (an empty document for null/empty input). Resets the counters.
     *
     * @return array{type: 'doc', content: list<array<string, mixed>>}
     *
     * @throws InvalidArgumentException when the result exceeds the validator's size limit
     */
    public function convert(?string $html): array
    {
        $this->dropped = [];
        $this->droppedLinks = 0;
        $this->droppedImages = $this->truncatedDepth = $this->truncatedNodes = 0;
        $this->headings = [];
        if ($html === null || trim($html) === '') {
            return KbContentValidator::empty();
        }
        if (strlen($html) > self::MAX_INPUT_BYTES) {
            throw new InvalidArgumentException('HTML larger than 8 MB');
        }

        $ascii = mb_encode_numericentity(str_replace("\0", '', mb_scrub((string) preg_replace('/<!\[CDATA\[.*?\]\]>/s', '', $html), 'UTF-8')), [0x80, 0x10FFFF, 0, 0x1FFFFF], 'UTF-8');
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML(
            '<!DOCTYPE html><html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head><body>'.$ascii.'</body></html>',
            LIBXML_NONET | LIBXML_COMPACT,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (! $loaded) {
            return KbContentValidator::empty();
        }
        $this->wrapperMeta = $dom->getElementsByTagName('meta')->item(0);

        $raw = ['type' => 'doc', 'content' => $this->blocksOf($dom, [])];
        $doc = KbContentValidator::normalize($raw);
        [$rawNodes, $deep] = self::measure($raw, 1);
        $this->truncatedDepth = $deep;
        $this->truncatedNodes = max(0, $rawNodes - $deep - self::measure($doc, 1)[0]);

        return $doc;
    }

    /**
     * Nodes in the tree and how many of them lie deeper than MAX_DEPTH (doc = depth 1, as in the validator).
     *
     * @param  array<string, mixed>  $node
     * @return array{0: int, 1: int}
     */
    private static function measure(array $node, int $depth): array
    {
        $count = 1;
        $deep = $depth > KbContentValidator::MAX_DEPTH ? 1 : 0;
        foreach ($node['content'] ?? [] as $child) {
            [$c, $d] = self::measure($child, $depth + 1);
            $count += $c;
            $deep += $d;
        }

        return [$count, $deep];
    }

    /**
     * @param  array<string, array<string, mixed>>  $marks  mark type => mark
     * @return list<array<string, mixed>>
     */
    private function blocksOf(DOMNode $parent, array $marks): array
    {
        $sink = ['blocks' => [], 'inline' => [], 'heading' => null];
        $this->walk($parent, $marks, $sink);
        $this->flush($sink);

        return $sink['blocks'];
    }

    /**
     * @param  array<string, array<string, mixed>>  $marks
     * @param  array{blocks: list<array<string, mixed>>, inline: list<array<string, mixed>>, heading: ?int}  $sink
     */
    private function walk(DOMNode $parent, array $marks, array &$sink): void
    {
        foreach ($parent->childNodes as $child) {
            $this->child($child, $marks, $sink);
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $marks
     * @param  array{blocks: list<array<string, mixed>>, inline: list<array<string, mixed>>, heading: ?int}  $sink
     */
    private function child(DOMNode $node, array $marks, array &$sink): void
    {
        if ($node instanceof DOMText) {
            $text = (string) preg_replace('/[ \t\n\r\f]+/', ' ', $node->data);
            if ($text !== '') {
                $sink['inline'][] = ['type' => 'text', 'text' => $text] + ($marks === [] ? [] : ['marks' => array_values($marks)]);
            }

            return;
        }
        if (! $node instanceof DOMElement || $node->isSameNode($this->wrapperMeta)) {
            return;
        }

        $tag = strtolower($node->localName ?? $node->tagName);
        if (in_array($tag, self::DROP, true)) {
            $this->count($tag);

            return;
        }
        if (isset(self::MARKS[$tag])) {
            $type = self::MARKS[$tag];
            $this->walk($node, array_merge($marks, [$type => ['type' => $type]]), $sink);

            return;
        }

        switch ($tag) {
            case 'p':
            case 'figure':
                $this->flush($sink);
                $this->walk($node, $marks, $sink);
                $this->flush($sink);

                return;
            case 'h1': case 'h2': case 'h3': case 'h4': case 'h5': case 'h6':
                $level = max(1, min(3, (int) $tag[1] - 1));
                if (in_array($tag, ['h1', 'h5', 'h6'], true)) {
                    $this->headings[$tag] = ($this->headings[$tag] ?? 0) + 1;
                }
                $this->flush($sink);
                $outer = $sink['heading'];
                $sink['heading'] = $level;
                $this->walk($node, $marks, $sink);
                $this->flush($sink);
                $sink['heading'] = $outer;

                return;
            case 'br':
                $sink['inline'][] = ['type' => 'hardBreak'];

                return;
            case 'hr':
                $this->flush($sink);
                $sink['blocks'][] = ['type' => 'horizontalRule'];

                return;
            case 'a':
                $this->walk($node, $this->linkMarks($node, $marks), $sink);

                return;
            case 'img':
                $image = $this->image($node);
                if ($image !== null) {
                    $this->flush($sink);
                    $sink['blocks'][] = $image;
                }

                return;
            case 'ul':
            case 'ol':
                $this->flush($sink);
                $list = $this->listNode($node, $tag, $marks);
                if ($list !== null) {
                    $sink['blocks'][] = $list;
                }

                return;
            case 'blockquote':
                $this->flush($sink);
                $inner = $this->blocksOf($node, $marks);
                if ($inner !== []) {
                    $sink['blocks'][] = ['type' => 'blockquote', 'content' => $inner];
                }

                return;
            case 'table':
                $this->flush($sink);
                array_push($sink['blocks'], ...$this->table($node, $marks));

                return;
        }

        $silent = in_array($tag, self::STRAY, true) || in_array($tag, ['html', 'head', 'body'], true);
        if (! $silent) {
            $this->count($tag);
        }
        if ($silent || in_array($tag, self::BLOCKY, true)) {
            $this->flush($sink);
            $this->walk($node, $marks, $sink);
            $this->flush($sink);
        } else {
            $this->walk($node, $marks, $sink);
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $marks
     * @return array<string, array<string, mixed>>
     */
    private function linkMarks(DOMElement $a, array $marks): array
    {
        $href = (string) preg_replace('/^[\s\x{A0}]+|[\s\x{A0}]+$/u', '', (string) preg_replace('/[\x00-\x1F\x7F]+/', '', $a->getAttribute('href')));
        if ($href === '') {
            return $marks;
        }
        $href = ($this->rewriteHref)(str_replace(' ', '%20', $href));
        if ($href === null) {
            return $marks;
        }
        if (! KbContentValidator::validHref($href)) {
            $this->droppedLinks++;

            return $marks;
        }
        $attrs = ['href' => $href];
        if (strtolower(trim($a->getAttribute('target'))) === '_blank') {
            $attrs += ['target' => '_blank', 'rel' => 'noopener noreferrer'];
        }
        unset($marks['link']);

        return $marks + ['link' => ['type' => 'link', 'attrs' => $attrs]];
    }

    /** @return ?array<string, mixed> */
    private function image(DOMElement $img): ?array
    {
        $src = trim($img->getAttribute('src'));
        $src = $src === '' ? '' : ($this->rewriteImageSrc)($src);
        if ($src === null) {
            return null;
        }
        if (preg_match(KbContentValidator::IMAGE_SRC, $src) !== 1) {
            $this->droppedImages++;

            return null;
        }
        $alt = trim($img->getAttribute('alt'));

        return ['type' => 'image', 'attrs' => ['src' => $src] + ($alt === '' ? [] : ['alt' => mb_substr($alt, 0, 255)])];
    }

    /**
     * @param  array<string, array<string, mixed>>  $marks
     * @return ?array<string, mixed>
     */
    private function listNode(DOMElement $list, string $tag, array $marks): ?array
    {
        $items = [];
        foreach ($list->childNodes as $child) {
            if ($child instanceof DOMElement && strtolower($child->localName ?? '') === 'li') {
                $content = $this->blocksOf($child, $marks);
                if ($content === [] || $content[0]['type'] !== 'paragraph') {
                    array_unshift($content, ['type' => 'paragraph']);
                }
                $items[] = ['type' => 'listItem', 'content' => $content];

                continue;
            }
            $stray = $this->strayBlocks($child, $marks);
            if ($stray === []) {
                continue;
            }
            if ($items === []) {
                $items[] = ['type' => 'listItem', 'content' => [['type' => 'paragraph']]];
            }
            array_push($items[count($items) - 1]['content'], ...$stray);
        }
        if ($items === []) {
            return null;
        }

        $attrs = [];
        if ($tag === 'ol') {
            $start = trim($list->getAttribute('start'));
            if (preg_match('/^\d{1,5}$/D', $start) === 1 && (int) $start !== 1) {
                $attrs['start'] = (int) $start;
            }
            $type = trim($list->getAttribute('type'));
            if (in_array($type, ['1', 'a', 'A', 'i', 'I'], true)) {
                $attrs['type'] = $type;
            }
        }

        return ['type' => $tag === 'ol' ? 'orderedList' : 'bulletList'] + ($attrs === [] ? [] : ['attrs' => $attrs]) + ['content' => $items];
    }

    /**
     * Rows of a table; a caption and anything else that is not in a cell come as blocks before it.
     *
     * @param  array<string, array<string, mixed>>  $marks
     * @return list<array<string, mixed>>
     */
    private function table(DOMElement $table, array $marks): array
    {
        $before = [];
        $rows = [];
        $sections = [$table];
        for ($i = 0; $i < count($sections); $i++) {
            foreach ($sections[$i]->childNodes as $child) {
                $tag = $child instanceof DOMElement ? strtolower($child->localName ?? '') : null;
                if ($i === 0 && in_array($tag, ['thead', 'tbody', 'tfoot'], true)) {
                    $sections[] = $child;
                } elseif ($tag === 'tr') {
                    /** @var DOMElement $child */
                    $row = $this->row($child, $marks, $before);
                    if ($row !== null) {
                        $rows[] = $row;
                    }
                } elseif ($tag !== 'colgroup' && $tag !== 'col') {
                    array_push($before, ...$this->strayBlocks($child, $marks));
                }
            }
        }

        return $rows === [] ? $before : [...$before, ['type' => 'table', 'content' => $rows]];
    }

    /**
     * @param  array<string, array<string, mixed>>  $marks
     * @param  list<array<string, mixed>>  $before
     * @return ?array<string, mixed>
     */
    private function row(DOMElement $tr, array $marks, array &$before): ?array
    {
        $cells = [];
        foreach ($tr->childNodes as $child) {
            $tag = $child instanceof DOMElement ? strtolower($child->localName ?? '') : null;
            if ($child instanceof DOMElement && ($tag === 'td' || $tag === 'th')) {
                $attrs = [];
                foreach (['colspan', 'rowspan'] as $name) {
                    $value = trim($child->getAttribute($name));
                    if (preg_match('/^[1-9]\d{0,2}$/D', $value) === 1) {
                        $attrs[$name] = (int) $value;
                    }
                }
                $content = $this->blocksOf($child, $marks);
                $cells[] = ['type' => $tag === 'th' ? 'tableHeader' : 'tableCell'] + ($attrs === [] ? [] : ['attrs' => $attrs])
                    + ['content' => $content === [] ? [['type' => 'paragraph']] : $content];
            } else {
                array_push($before, ...$this->strayBlocks($child, $marks));
            }
        }

        return $cells === [] ? null : ['type' => 'tableRow', 'content' => $cells];
    }

    /**
     * @param  array<string, array<string, mixed>>  $marks
     * @return list<array<string, mixed>>
     */
    private function strayBlocks(DOMNode $node, array $marks): array
    {
        $sink = ['blocks' => [], 'inline' => [], 'heading' => null];
        $this->child($node, $marks, $sink);
        $this->flush($sink);

        return $sink['blocks'];
    }

    /**
     * Ends the current paragraph/heading: blanks trimmed at the edges and around hard breaks, trailing hard breaks
     * dropped, neighbouring text with the same marks merged. Nothing is emitted when no content is left.
     *
     * @param  array{blocks: list<array<string, mixed>>, inline: list<array<string, mixed>>, heading: ?int}  $sink
     */
    private function flush(array &$sink): void
    {
        $out = [];
        $space = true;
        foreach ($sink['inline'] as $node) {
            if ($node['type'] === 'hardBreak') {
                self::trimEnd($out);
                $out[] = $node;
                $space = true;

                continue;
            }
            if ($space) {
                $node['text'] = ltrim($node['text'], ' ');
            }
            if ($node['text'] === '') {
                continue;
            }
            $space = str_ends_with($node['text'], ' ');
            $last = count($out) - 1;
            if ($last >= 0 && $out[$last]['type'] === 'text' && ($out[$last]['marks'] ?? null) === ($node['marks'] ?? null)) {
                $out[$last]['text'] .= $node['text'];
            } else {
                $out[] = $node;
            }
        }
        do {
            self::trimEnd($out);
            $breaks = $out !== [] && $out[count($out) - 1]['type'] === 'hardBreak';
            if ($breaks) {
                array_pop($out);
            }
        } while ($breaks);
        $sink['inline'] = [];
        if ($out === []) {
            return;
        }
        $sink['blocks'][] = $sink['heading'] === null
            ? ['type' => 'paragraph', 'content' => $out]
            : ['type' => 'heading', 'attrs' => ['level' => $sink['heading']], 'content' => $out];
    }

    /** @param list<array<string, mixed>> $inline */
    private static function trimEnd(array &$inline): void
    {
        $last = count($inline) - 1;
        if ($last >= 0 && $inline[$last]['type'] === 'text') {
            $inline[$last]['text'] = rtrim($inline[$last]['text'], ' ');
            if ($inline[$last]['text'] === '') {
                array_pop($inline);
                self::trimEnd($inline);
            }
        }
    }

    private function count(string $tag): void
    {
        $tag = mb_substr($tag, 0, 40);
        $this->dropped[$tag] = ($this->dropped[$tag] ?? 0) + 1;
    }
}
