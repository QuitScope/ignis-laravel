<?php

namespace Modules\Mail\Content;

/**
 * Plain text of a mail for `search_text` and the list preview (legacy MailBodyRenderer::plainText): block ends and hard
 * breaks become spaces, whitespace collapses to one space. Unescaped text: never bind it to v-html.
 */
final class PlainText
{
    public const SEARCH_MAX_BYTES = 60_000;

    /** @param array<mixed> $doc */
    public static function of(array $doc): string
    {
        return trim((string) preg_replace('/[\p{Z}\s]+/u', ' ', self::text(MailContentValidator::normalize($doc))));
    }

    /**
     * Subject and body text, cut to what the column holds.
     *
     * @param  array<mixed>|null  $body
     */
    public static function search(string $subject, ?array $body): string
    {
        $text = trim($subject."\n".($body === null ? '' : self::of($body)));

        return mb_strcut($text, 0, self::SEARCH_MAX_BYTES, 'UTF-8');
    }

    /** @param array<string, mixed> $node normalized */
    private static function text(array $node): string
    {
        return match ($node['type']) {
            'text' => $node['text'],
            'hardBreak', 'image' => ' ',
            default => implode('', array_map(self::text(...), $node['content'] ?? [])).' ',
        };
    }
}
