<?php

namespace Modules\Mail\Content;

use App\Models\Rank;
use InvalidArgumentException;
use Modules\Mail\Support\SendLimits;

/**
 * Allowlist of the `mail` preset, built like KbContentValidator. One rule set, two modes:
 *  - validate(): drafts and sending. Anything outside the list (node, mark, attribute, key, value, placement, a
 *    non-string text) is REJECTED with InvalidArgumentException.
 *  - normalize(): the renderer, quoting and the importer. Unknown or invalid things are DROPPED; the result passes
 *    validate().
 * Nodes: paragraph, hard break, bold/italic/strike, link (http, https, mailto only), blockquote, bullet/ordered list,
 * an inline image only for a rank badge of this instance. `docVariable` is not a mail node: placeholders exist only in
 * signature templates (SignatureTemplate) and are resolved before they reach a mail. Quotes deeper than
 * MAX_QUOTE_DEPTH are cut (capQuotes) in both modes.
 */
final class MailContentValidator
{
    public const MAX_QUOTE_DEPTH = 10;

    private const URL_WHITESPACE = '/[\x00-\x20\x7F-\x{00A0}\x{1680}\x{180E}\x{2000}-\x{2029}\x{205F}\x{3000}\x{FEFF}]/u';

    private const MAX_HREF = 2048;

    private const MARKS = ['bold', 'italic', 'strike', 'link'];

    private const INLINE = ['text', 'hardBreak', 'image'];

    private const BLOCK = ['paragraph', 'blockquote', 'bulletList', 'orderedList'];

    private const CHILDREN = [
        'doc' => self::BLOCK,
        'paragraph' => self::INLINE,
        'blockquote' => self::BLOCK,
        'bulletList' => ['listItem'],
        'orderedList' => ['listItem'],
        'listItem' => self::BLOCK,
        'text' => null,
        'hardBreak' => null,
        'image' => null,
    ];

    /** @var array<string, true> */
    private array $reasons = [];

    private function __construct(private readonly bool $strict) {}

    /**
     * @return array{type: 'doc', content: list<array<string, mixed>>}
     *
     * @throws InvalidArgumentException with the user-facing reasons as message
     */
    public static function validate(mixed $json): array
    {
        return (new self(true))->run($json);
    }

    /**
     * Lenient: drops what is not allowed. Only something that is no document, too large or too deep is refused.
     *
     * @return array{type: 'doc', content: list<array<string, mixed>>}
     */
    public static function normalize(mixed $json): array
    {
        return (new self(false))->run($json);
    }

    public static function validHref(mixed $href): bool
    {
        return is_string($href) && strlen($href) <= self::MAX_HREF
            && preg_match(self::URL_WHITESPACE, $href) === 0 && ! str_contains($href, '\\')
            && preg_match('~^(?:https?://[^/?#]|mailto:.)~i', $href) === 1;
    }

    public static function validImageSrc(mixed $src): bool
    {
        if (! is_string($src) || strlen($src) > 255) {
            return false;
        }
        $path = (string) preg_replace('~\?v=\d{1,12}$~D', '', $src);
        if (preg_match('~^/(?![/\\\\])[A-Za-z0-9._/-]+$~D', $path) !== 1 || str_contains($path, '..')) {
            return false;
        }

        // ponytail: one query per request; ranks are a short table.
        return in_array($path, once(fn () => Rank::query()->whereNotNull('badge')->pluck('badge')->all()), true);
    }

    /**
     * Replaces every quote nested deeper than MAX_QUOTE_DEPTH by a paragraph "[older quotes cut]". Without this a reply
     * chain hits the depth limit of the JSON column after a dozen replies.
     *
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    public static function capQuotes(array $node, int $depth = 0): array
    {
        if (! is_array($node['content'] ?? null)) {
            return $node;
        }
        foreach ($node['content'] as $i => $child) {
            if (! is_array($child)) {
                continue;
            }
            $isQuote = ($child['type'] ?? null) === 'blockquote';
            $node['content'][$i] = $isQuote && $depth >= self::MAX_QUOTE_DEPTH
                ? ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => __('mail.quote.capped')]]]
                : self::capQuotes($child, $depth + ($isQuote ? 1 : 0));
        }

        return $node;
    }

    /** @return array{type: 'doc', content: list<array<string, mixed>>} */
    private function run(mixed $json): array
    {
        if (is_string($json)) {
            if (strlen($json) > SendLimits::BODY_MAX_BYTES) {
                throw new InvalidArgumentException(__('mail.body.too_large', ['max' => SendLimits::BODY_MAX_BYTES / 1000]));
            }
            $json = json_decode($json, true, SendLimits::BODY_MAX_DEPTH);
            if (json_last_error() === JSON_ERROR_DEPTH) {
                throw new InvalidArgumentException(__('mail.body.too_deep'));
            }
        }
        if (! is_array($json) || ($json['type'] ?? null) !== 'doc') {
            throw new InvalidArgumentException(__('mail.body.invalid'));
        }
        // Also an array from elsewhere (importer, stored row): same size and depth limits as the posted string.
        $encoded = json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES, SendLimits::BODY_MAX_DEPTH);
        if ($encoded === false) {
            throw new InvalidArgumentException(json_last_error() === JSON_ERROR_DEPTH ? __('mail.body.too_deep') : __('mail.body.invalid'));
        }
        if (strlen($encoded) > SendLimits::BODY_MAX_BYTES) {
            throw new InvalidArgumentException(__('mail.body.too_large', ['max' => SendLimits::BODY_MAX_BYTES / 1000]));
        }

        $doc = $this->node($json, 'doc', true) ?? ['type' => 'doc'];
        if ($this->strict && $this->reasons !== []) {
            throw new InvalidArgumentException(implode(' ', array_slice(array_keys($this->reasons), 0, 10)));
        }
        $doc['content'] ??= [];

        /** @var array{type: 'doc', content: list<array<string, mixed>>} */
        return self::capQuotes($doc);
    }

    /** @return array<string, mixed>|null the cleaned node, null when it has to go */
    private function node(mixed $node, string $parent, bool $root = false): ?array
    {
        $type = is_array($node) ? ($node['type'] ?? null) : null;
        if (! is_string($type) || ! array_key_exists($type, self::CHILDREN)) {
            return $this->fail(__('mail.content.unknown', ['name' => self::short($type)]));
        }
        /** @var array<string, mixed> $node */
        if (! $root && ! in_array($type, self::CHILDREN[$parent] ?? [], true)) {
            return $this->fail(__('mail.content.misplaced', ['name' => $type, 'parent' => $parent]));
        }

        $children = self::CHILDREN[$type];
        $inline = in_array($type, self::INLINE, true);
        $keys = ['type', 'attrs', ...($children === null ? [] : ['content']), ...($type === 'text' ? ['text'] : []), ...($inline ? ['marks'] : [])];
        foreach (array_diff(array_keys($node), $keys) as $key) {
            $this->fail(__('mail.content.attribute', ['name' => self::short($key), 'node' => $type]));
        }

        $clean = ['type' => $type];
        if ($type === 'text') {
            if (! is_string($node['text'] ?? null) || $node['text'] === '' || ! mb_check_encoding($node['text'], 'UTF-8')) {
                return $this->fail(__('mail.body.invalid'));
            }
            $clean['text'] = $node['text'];
        }

        $attrs = $this->attrs($type, $node['attrs'] ?? []);
        if ($attrs === null) {
            return null;
        }
        if ($attrs !== []) {
            $clean['attrs'] = $attrs;
        }

        $content = $node['content'] ?? [];
        if ($children !== null && $content !== []) {
            if (! is_array($content) || ! array_is_list($content)) {
                $this->fail(__('mail.body.invalid'));
            } else {
                $kept = array_values(array_filter(array_map(fn ($c) => $this->node($c, $type), $content)));
                if ($kept !== []) {
                    $clean['content'] = $kept;
                }
            }
        }

        if ($inline && ($node['marks'] ?? []) !== [] && $type !== 'image') {
            $marks = $this->marks($node['marks']);
            if ($marks !== []) {
                $clean['marks'] = $marks;
            }
        }

        return $clean;
    }

    /** @return array<string, mixed>|null null: the node has to go */
    private function attrs(string $type, mixed $attrs): ?array
    {
        if (! is_array($attrs)) {
            $this->fail(__('mail.content.attribute', ['name' => 'attrs', 'node' => $type]));

            return $type === 'image' ? null : [];
        }
        $attrs = array_filter($attrs, fn ($v) => $v !== null);
        $allowed = match ($type) {
            'image' => ['src', 'alt'],
            'orderedList' => ['start'],
            default => [],
        };
        foreach (array_diff(array_keys($attrs), $allowed) as $name) {
            $this->fail(__('mail.content.attribute', ['name' => self::short($name), 'node' => $type]));
        }

        if ($type === 'orderedList') {
            $start = $attrs['start'] ?? 1;
            if (! is_int($start) || $start < 0 || $start > 99999) {
                $this->fail(__('mail.content.attribute', ['name' => 'start', 'node' => $type]));
                $start = 1;
            }

            return $start === 1 ? [] : ['start' => $start];
        }
        if ($type === 'image') {
            $alt = $attrs['alt'] ?? '';
            if (! self::validImageSrc($attrs['src'] ?? null) || ! is_string($alt) || mb_strlen($alt) > 255) {
                return $this->fail(__('mail.content.image'));
            }

            return ['src' => $attrs['src'], 'alt' => $alt];
        }

        return [];
    }

    /** @return list<array<string, mixed>> */
    private function marks(mixed $marks): array
    {
        if (! is_array($marks) || ! array_is_list($marks)) {
            $this->fail(__('mail.content.mark', ['name' => '?']));

            return [];
        }
        $clean = [];
        foreach ($marks as $mark) {
            $type = is_array($mark) ? ($mark['type'] ?? null) : null;
            if (! is_string($type) || ! in_array($type, self::MARKS, true) || isset($clean[$type]) || array_diff(array_keys($mark), ['type', 'attrs']) !== []) {
                $this->fail(__('mail.content.mark', ['name' => self::short($type)]));

                continue;
            }
            $attrs = $mark['attrs'] ?? [];
            $attrs = is_array($attrs) ? array_filter($attrs, fn ($v) => $v !== null) : ['?' => true];
            if ($type !== 'link') {
                if ($attrs !== []) {
                    $this->fail(__('mail.content.mark', ['name' => $type]));
                }
                $clean[$type] = ['type' => $type];

                continue;
            }
            if (array_diff(array_keys($attrs), ['href', 'target', 'rel']) !== []) {
                $this->fail(__('mail.content.mark', ['name' => $type]));
            }
            if (! self::validHref($attrs['href'] ?? null)) {
                $this->fail(__('mail.content.link'));

                continue;
            }
            $clean[$type] = ['type' => 'link', 'attrs' => ['href' => $attrs['href']]];
        }

        return array_values($clean);
    }

    private static function short(mixed $name): string
    {
        if (! is_string($name) && ! is_int($name)) {
            return '?';
        }
        $name = (string) $name;

        return mb_strlen($name) > 40 ? mb_substr($name, 0, 40).'…' : $name;
    }

    private function fail(string $reason): null
    {
        $this->reasons[$reason] = true;

        return null;
    }
}
