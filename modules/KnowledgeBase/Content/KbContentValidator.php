<?php

namespace Modules\KnowledgeBase\Content;

use InvalidArgumentException;

/**
 * Allowlist for knowledge base editor JSON (Tiptap/ProseMirror). One rule set, two modes:
 *  - validate(): the editor's save path. Anything not on the list (node, mark, attribute, key, value, placement) is
 *    REJECTED with InvalidArgumentException, so nothing is lost silently (DocumentValidator style).
 *  - normalize(): the importer and the renderer. Unknown or invalid things are DROPPED (an unknown node with its
 *    content, a bad link mark keeps its text, a bad attribute goes), the result always passes validate().
 * Both return the cleaned document: null attributes dropped, a link with target=_blank gets rel="noopener noreferrer"
 * (an incoming rel is never trusted, it is recomputed from target).
 *
 * Handoff: send the editor JSON as a JSON STRING field (like DocumentTemplateRequest), so TrimStrings and
 * ConvertEmptyStringsToNull cannot alter text nodes; validate() decodes strings itself. Only
 * KbContentRenderer::html() output may be bound to v-html; plainText() is unescaped text and must never be.
 */
final class KbContentValidator
{
    public const MAX_BYTES = 2 * 1024 * 1024;

    public const MAX_DEPTH = 12;

    public const MAX_NODES = 20000;

    public const IMAGE_SRC = '~^/storage/kb-images/[a-f0-9]{32}\.(jpg|png|webp|gif)$~D';

    private const URL_WHITESPACE = '/[\x00-\x20\x7F-\x{00A0}\x{1680}\x{180E}\x{2000}-\x{2029}\x{205F}\x{3000}\x{FEFF}]/u';

    private const MAX_HREF = 2048;

    private const MARKS = ['bold', 'italic', 'underline', 'strike', 'link'];

    private const INLINE = ['text', 'hardBreak'];

    private const BLOCK = ['paragraph', 'heading', 'bulletList', 'orderedList', 'blockquote', 'table', 'horizontalRule', 'image'];

    private const CHILDREN = [
        'doc' => self::BLOCK,
        'paragraph' => self::INLINE,
        'heading' => self::INLINE,
        'blockquote' => self::BLOCK,
        'bulletList' => ['listItem'],
        'orderedList' => ['listItem'],
        'listItem' => self::BLOCK,
        'table' => ['tableRow'],
        'tableRow' => ['tableCell', 'tableHeader'],
        'tableCell' => self::BLOCK,
        'tableHeader' => self::BLOCK,
        'horizontalRule' => null,
        'image' => null,
        'text' => null,
        'hardBreak' => null,
    ];

    private const ATTRS = [
        'heading' => ['level' => 'level'],
        'orderedList' => ['start' => 'start', 'type' => 'listType'],
        'tableCell' => ['colspan' => 'span', 'rowspan' => 'span'],
        'tableHeader' => ['colspan' => 'span', 'rowspan' => 'span'],
        'image' => ['src' => 'src', 'alt' => 'alt'],
    ];

    /** @var array<string, true> */
    private array $reasons = [];

    private int $nodes = 0;

    private function __construct(private readonly bool $strict) {}

    /**
     * The editor's save path: rejects anything outside the allowlist.
     *
     * @return array{type: 'doc', content: list<array<string, mixed>>}
     *
     * @throws InvalidArgumentException with the user-facing reasons as message
     */
    public static function validate(mixed $json): array
    {
        return (new self(true))->run($json);
    }

    /**
     * Lenient: drops what is not allowed. Only a document that is no JSON object of type doc, or that is too large,
     * is refused (InvalidArgumentException).
     *
     * @return array{type: 'doc', content: list<array<string, mixed>>}
     */
    public static function normalize(mixed $json): array
    {
        return (new self(false))->run($json);
    }

    /**
     * An empty document.
     *
     * @return array{type: 'doc', content: list<array<string, mixed>>}
     */
    public static function empty(): array
    {
        return ['type' => 'doc', 'content' => []];
    }

    /**
     * A link target the renderer may print: http(s)://, mailto: or a same-site path starting with exactly one `/`.
     * No whitespace or control characters anywhere (they would hide a `javascript:` from a naive check), no
     * backslash (`/\host` is protocol-relative in browsers). JSON carries no HTML entities, so `&#106;avascript:`
     * stays a literal string that starts with `&` and fails.
     */
    public static function validHref(mixed $href): bool
    {
        return is_string($href) && $href !== '' && strlen($href) <= self::MAX_HREF
            && preg_match(self::URL_WHITESPACE, $href) === 0 && ! str_contains($href, '\\')
            && preg_match('~^(?:https?://|mailto:|/(?!/))~i', $href) === 1;
    }

    /**
     * @return array{type: 'doc', content: list<array<string, mixed>>}
     */
    private function run(mixed $json): array
    {
        if (is_string($json)) {
            if (strlen($json) > self::MAX_BYTES) {
                throw $this->tooLarge();
            }
            $json = json_decode($json, true, 512);
        }
        if (! is_array($json)) {
            throw new InvalidArgumentException(trans('knowledgebase.content.invalid'));
        }
        // Also refuses invalid UTF-8, nesting beyond 512 and self-referencing arrays (recursion), before any walking.
        $encoded = json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES, 512);
        if ($encoded === false) {
            throw new InvalidArgumentException(trans('knowledgebase.content.invalid'));
        }
        if (strlen($encoded) > self::MAX_BYTES) {
            throw $this->tooLarge();
        }
        if (($json['type'] ?? null) !== 'doc') {
            throw new InvalidArgumentException(trans('knowledgebase.content.invalid'));
        }

        $doc = $this->node($json, 'doc', 1, true) ?? self::empty();
        if ($this->strict && $this->reasons !== []) {
            throw new InvalidArgumentException(implode(' ', array_slice(array_keys($this->reasons), 0, 10)));
        }
        $doc['content'] ??= [];

        /** @var array{type: 'doc', content: list<array<string, mixed>>} $doc */
        return $doc;
    }

    /**
     * The cleaned node, or null when it has to go (normalize) / was refused (validate).
     *
     * @return array<string, mixed>|null
     */
    private function node(mixed $node, string $parent, int $depth, bool $root = false): ?array
    {
        if ($depth > self::MAX_DEPTH) {
            return $this->fail(trans('knowledgebase.content.too_deep', ['max' => self::MAX_DEPTH]));
        }
        if (++$this->nodes > self::MAX_NODES) {
            return $this->fail(trans('knowledgebase.content.too_many', ['max' => self::MAX_NODES]));
        }
        $type = is_array($node) ? ($node['type'] ?? null) : null;
        if (! is_string($type) || ! array_key_exists($type, self::CHILDREN)) {
            return $this->fail(trans('knowledgebase.content.unknown', ['name' => self::short($type)]));
        }
        /** @var array<string, mixed> $node */
        if (! $root && ! in_array($type, self::CHILDREN[$parent] ?? [], true)) {
            return $this->fail(trans('knowledgebase.content.misplaced', ['name' => $type, 'parent' => $parent]));
        }

        $children = self::CHILDREN[$type];
        $keys = ['type', 'attrs', ...($children === null ? [] : ['content']), ...($type === 'text' ? ['text'] : []), ...(in_array($type, self::INLINE, true) ? ['marks'] : [])];
        foreach (array_diff(array_keys($node), $keys) as $key) {
            $this->fail(trans('knowledgebase.content.attribute', ['name' => self::short($key), 'node' => $type]));
        }

        $clean = ['type' => $type];
        if ($type === 'text') {
            if (! is_string($node['text'] ?? null) || $node['text'] === '') {
                return $this->fail(trans('knowledgebase.content.invalid'));
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

        if ($children !== null && ($node['content'] ?? []) !== []) {
            if (! is_array($node['content']) || ! array_is_list($node['content'])) {
                $this->fail(trans('knowledgebase.content.invalid'));
            } else {
                $content = [];
                foreach ($node['content'] as $child) {
                    if (($c = $this->node($child, $type, $depth + 1)) !== null) {
                        $content[] = $c;
                    }
                }
                if ($content !== []) {
                    $clean['content'] = $content;
                }
            }
        }

        $marks = $node['marks'] ?? [];
        if (in_array($type, self::INLINE, true) && $marks !== []) {
            $marks = $this->marks($marks);
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
            $this->fail(trans('knowledgebase.content.attribute', ['name' => 'attrs', 'node' => $type]));

            return $type === 'heading' ? ['level' => 1] : ($type === 'image' ? null : []);
        }

        $rules = self::ATTRS[$type] ?? [];
        $clean = [];
        foreach ($attrs as $name => $value) {
            $rule = $rules[$name] ?? null;
            if ($rule === null) {
                if ($value !== null) {
                    $this->fail(trans('knowledgebase.content.attribute', ['name' => self::short($name), 'node' => $type]));
                }
            } elseif ($value !== null) {
                $ok = $this->attr($rule, $value);
                if ($ok !== null) {
                    $clean[$name] = $ok;
                } elseif ($rule === 'src') {
                    $this->fail(trans('knowledgebase.content.image'));
                } else {
                    $this->fail(trans('knowledgebase.content.value', ['name' => self::short($name), 'node' => $type]));
                }
            }
        }

        if ($type === 'heading' && ! isset($clean['level'])) {
            $this->fail(trans('knowledgebase.content.value', ['name' => 'level', 'node' => $type]));
            $level = $attrs['level'] ?? null;
            $level = is_string($level) && ctype_digit($level) ? (int) $level : $level;
            $clean['level'] = is_int($level) ? max(1, min(3, $level)) : 1;
        }
        if ($type === 'image' && ! isset($clean['src'])) {
            return $this->fail(trans('knowledgebase.content.image'));
        }

        return $clean;
    }

    private function attr(string $rule, mixed $v): mixed
    {
        return match ($rule) {
            'level' => in_array($v, [1, 2, 3], true) ? $v : null,
            'start' => is_int($v) && $v >= 0 && $v <= 99999 ? $v : null,
            'listType' => in_array($v, ['1', 'a', 'A', 'i', 'I'], true) ? $v : null,
            'span' => is_int($v) && $v >= 1 && $v <= 999 ? $v : null,
            'src' => is_string($v) && preg_match(self::IMAGE_SRC, $v) === 1 ? $v : null,
            'alt' => is_string($v) ? ($this->strict ? (mb_strlen($v) <= 255 ? $v : null) : mb_substr($v, 0, 255)) : null,
            default => null,
        };
    }

    /** @return list<array<string, mixed>> */
    private function marks(mixed $marks): array
    {
        if (! is_array($marks) || ! array_is_list($marks)) {
            $this->fail(trans('knowledgebase.content.mark', ['name' => '?']));

            return [];
        }

        $clean = [];
        foreach ($marks as $mark) {
            $type = is_array($mark) ? ($mark['type'] ?? null) : null;
            if (! is_string($type) || ! in_array($type, self::MARKS, true) || array_diff(array_keys($mark), ['type', 'attrs']) !== []) {
                $this->fail(trans('knowledgebase.content.mark', ['name' => self::short($type)]));

                continue;
            }
            if (isset($clean[$type])) {
                $this->fail(trans('knowledgebase.content.mark', ['name' => $type]));

                continue;
            }
            $attrs = $mark['attrs'] ?? [];
            if (is_array($attrs)) {
                $attrs = array_filter($attrs, fn ($v) => $v !== null);
            }
            if ($type === 'link') {
                $link = $this->link($attrs);
                if ($link !== null) {
                    $clean[$type] = ['type' => 'link', 'attrs' => $link];
                }
            } elseif ($attrs !== []) {
                $this->fail(trans('knowledgebase.content.mark', ['name' => $type]));
            } else {
                $clean[$type] = ['type' => $type];
            }
        }

        return array_values($clean);
    }

    /**
     * href (required), target (null or _blank), rel (any value, ignored). The result carries rel="noopener noreferrer"
     * exactly when target is _blank, whatever rel came in.
     *
     * @return array{href: string, target?: string, rel?: string}|null
     */
    private function link(mixed $attrs): ?array
    {
        if (! is_array($attrs)) {
            $this->fail(trans('knowledgebase.content.link'));

            return null;
        }
        foreach (array_diff(array_keys($attrs), ['href', 'target', 'rel']) as $key) {
            if ($attrs[$key] !== null) {
                $this->fail(trans('knowledgebase.content.attribute', ['name' => self::short($key), 'node' => 'link']));
            }
        }

        $href = $attrs['href'] ?? null;
        if (! $this->strict && is_string($href)) {
            $href = (string) preg_replace(self::URL_WHITESPACE, '', $href);
        }
        if (! self::validHref($href)) {
            $this->fail(trans('knowledgebase.content.link'));

            return null;
        }

        $target = $attrs['target'] ?? null;
        if ($target !== null && $target !== '_blank') {
            $this->fail(trans('knowledgebase.content.value', ['name' => 'target', 'node' => 'link']));
            $target = null;
        }

        /** @var string $href */
        return $target === '_blank' ? ['href' => $href, 'target' => '_blank', 'rel' => 'noopener noreferrer'] : ['href' => $href];
    }

    private static function short(mixed $name): string
    {
        if (! is_string($name) && ! is_int($name)) {
            return '?';
        }
        $name = (string) $name;

        return mb_strlen($name) > 40 ? mb_substr($name, 0, 40).'…' : $name;
    }

    private function tooLarge(): InvalidArgumentException
    {
        return new InvalidArgumentException(trans('knowledgebase.content.too_large', ['max' => '2 MB']));
    }

    private function fail(string $reason): null
    {
        $this->reasons[$reason] = true;

        return null;
    }
}
