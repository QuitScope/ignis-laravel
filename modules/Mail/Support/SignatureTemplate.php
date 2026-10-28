<?php

namespace Modules\Mail\Support;

use App\Models\Personnel;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Mail\Content\MailContentValidator;
use Modules\Mail\Models\Mailbox;
use Modules\Mail\Models\MailSignature;

/**
 * Signatures as templates with placeholders (legacy SignatureTemplate). A template is a document of
 * paragraphs with text, hard breaks and `docVariable` placeholders (catalog()), four marks. Which one applies to a
 * mailbox (forMailbox()): its own (also an empty one = "none"), else the default (mail_signatures row with mailbox_id
 * NULL), else builtIn(). resolve() fills in the writer's details at compose time: a line (paragraph or the part
 * between two hard breaks) whose placeholders all stay empty and that has no other text is dropped. For a group
 * mailbox the writer is the member who writes; address and mailbox name are the group's.
 */
final class SignatureTemplate
{
    public const MAX_BYTES = 16384;

    private const MAX_DEPTH = 16;

    private const MARKS = ['bold', 'italic', 'strike', 'link'];

    public const KEYS = [
        'absender.name', 'absender.titel', 'absender.dienstgrad', 'absender.position', 'absender.fachdienste',
        'absender.dienstnummer', 'absender.mailadresse', 'postfach.name', 'organisation',
    ];

    /** @return array<string, string> key => label, in the order the editor offers them */
    public static function catalog(): array
    {
        return array_combine(self::KEYS, array_map(fn (string $k) => __('mail.signature.var.'.$k), self::KEYS));
    }

    /** @return array{type: 'doc', content: list<array<string, mixed>>} one paragraph per detail, the name bold */
    public static function builtIn(): array
    {
        $line = fn (string $key, bool $bold = false): array => ['type' => 'paragraph', 'content' => [
            ['type' => 'docVariable', 'attrs' => ['name' => $key]] + ($bold ? ['marks' => [['type' => 'bold']]] : []),
        ]];

        return ['type' => 'doc', 'content' => [
            $line('absender.name', true), $line('absender.dienstgrad'), $line('absender.position'),
            $line('absender.fachdienste'), $line('organisation'),
        ]];
    }

    /**
     * A stored legacy value (MAIL_DEFAULT_SIGNATURE) as a template, null when empty. A value that is no editor
     * document is legacy plain text: one paragraph per line.
     *
     * @return array<string, mixed>|null
     */
    public static function decode(?string $stored): ?array
    {
        $stored = trim((string) $stored);
        if ($stored === '') {
            return null;
        }
        $doc = json_decode($stored, true);
        if (is_array($doc) && ($doc['type'] ?? null) === 'doc') {
            return $doc;
        }
        $content = [];
        foreach (preg_split('/\R/u', $stored) ?: [] as $line) {
            $line = trim($line);
            $content[] = $line === '' ? ['type' => 'paragraph'] : ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $line]]];
        }

        return ['type' => 'doc', 'content' => $content];
    }

    /**
     * The posted template (JSON string), rebuilt: only paragraphs with text, hard breaks and known placeholders, bold,
     * italic, strike and links (http, https, mailto). Other attributes (alignment) are dropped.
     *
     * @return array{type: 'doc', content: list<array<string, mixed>>}
     *
     * @throws InvalidArgumentException with the message for the form
     */
    public static function parse(mixed $raw): array
    {
        if (! is_string($raw)) {
            throw new InvalidArgumentException(__('mail.signature.invalid'));
        }
        if (strlen($raw) > self::MAX_BYTES) {
            throw new InvalidArgumentException(__('mail.signature.too_large', ['max' => intdiv(self::MAX_BYTES, 1024)]));
        }
        $doc = json_decode($raw, true, self::MAX_DEPTH);
        if (! is_array($doc) || ($doc['type'] ?? null) !== 'doc' || ! is_array($doc['content'] ?? [])) {
            throw new InvalidArgumentException(__('mail.signature.invalid'));
        }

        $content = [];
        foreach ($doc['content'] ?? [] as $node) {
            if (! is_array($node) || ($node['type'] ?? null) !== 'paragraph' || ! is_array($node['content'] ?? [])) {
                throw new InvalidArgumentException(__('mail.signature.paragraphs_only'));
            }
            $content[] = self::paragraph($node['content'] ?? []);
        }

        return ['type' => 'doc', 'content' => $content];
    }

    /**
     * The template that applies to $mailbox: its own (an empty one means none), else the default, else builtIn().
     *
     * @return array<string, mixed>
     */
    public static function forMailbox(Mailbox $mailbox): array
    {
        $own = MailSignature::query()->where('mailbox_id', $mailbox->id)->first();

        return $own !== null ? $own->body : self::default();
    }

    /** @return array<string, mixed> the default template of the instance, builtIn() without a stored one */
    public static function default(): array
    {
        return MailSignature::query()->whereNull('mailbox_id')->orderBy('id')->first()->body ?? self::builtIn();
    }

    /**
     * Stores the default template (administration, importer). Empty or equal to builtIn() removes the row, so the
     * built-in template keeps following future changes. Under a lock: exactly one row with mailbox_id NULL (the unique
     * index does not cover NULL).
     *
     * @param  array<string, mixed>  $doc  result of parse()/decode()
     */
    public static function saveDefault(array $doc): void
    {
        $doc = self::parse((string) json_encode($doc));
        $builtIn = self::parse((string) json_encode(self::builtIn()));
        Cache::lock('mail.default-signature', 10)->block(5, fn () => DB::transaction(function () use ($doc, $builtIn) {
            MailSignature::query()->whereNull('mailbox_id')->lockForUpdate()->get(['id']);
            MailSignature::query()->whereNull('mailbox_id')->delete();
            if (self::hasContent($doc) && $doc !== $builtIn) {
                MailSignature::query()->forceCreate(['mailbox_id' => null, 'body' => $doc]);
            }
        }));
    }

    public static function writer(Mailbox $mailbox, User $user): ?Personnel
    {
        return $mailbox->isGroup() ? $user->personnel : $mailbox->personnel;
    }

    /**
     * Fills in the details. A line that had placeholders and no text afterwards is dropped, a paragraph without any
     * line left too.
     *
     * @param  array<string, mixed>  $template
     * @return array{type: 'doc', content: list<array<string, mixed>>}
     */
    public static function resolve(array $template, ?Personnel $person, string $address, string $mailboxName = ''): array
    {
        $values = self::values($person, $address, $mailboxName);
        $content = [];
        foreach ((array) ($template['content'] ?? []) as $node) {
            if (! is_array($node)) {
                continue;
            }
            $hadVariable = false;
            $node = self::fillLines($node, $values, $hadVariable);
            if (! $hadVariable || self::hasText($node)) {
                $content[] = $node;
            }
        }

        return ['type' => 'doc', 'content' => $content];
    }

    /**
     * The details per placeholder as inline nodes (empty = no nodes). The rank gets its badge in front when it is a
     * badge path the mail preset accepts.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public static function values(?Personnel $person, string $address, string $mailboxName = ''): array
    {
        $text = function (mixed $value): array {
            $value = trim((string) $value);

            return $value === '' ? [] : [['type' => 'text', 'text' => $value]];
        };
        $settings = app(Settings::class);
        $organisation = trim($settings->get('organisation.type', '').' '.$settings->get('organisation.city', ''));

        $values = ['absender.mailadresse' => $text($address), 'postfach.name' => $text($mailboxName), 'organisation' => $text($organisation)];
        if ($person === null) {
            return $values;
        }

        $rank = $person->rank;
        $rankText = $text($rank->labelFor($person->gender));
        $values['absender.dienstgrad'] = $rankText !== [] && MailContentValidator::validImageSrc($rank->badge)
            ? [['type' => 'image', 'attrs' => ['src' => $rank->badge, 'alt' => '']], ...$rankText]
            : $rankText;
        $values['absender.name'] = $text($person->displayName());
        $values['absender.titel'] = $text($person->title?->name);
        $values['absender.position'] = $text($person->extra_qualification);
        $values['absender.fachdienste'] = $text($person->specialties()->where('is_disabled', false)->orderBy('number')->pluck('name')->implode(', '));
        $values['absender.dienstnummer'] = $text($person->service_number);

        return $values;
    }

    /**
     * values() as plain text for the preview in the signature editor (the badge left out).
     *
     * @return array<string, string>
     */
    public static function previewValues(?Personnel $person, string $address, string $mailboxName = ''): array
    {
        return array_filter(array_map(
            fn (array $nodes) => implode('', array_column($nodes, 'text')),
            self::values($person, $address, $mailboxName),
        ), fn (string $v) => $v !== '');
    }

    /**
     * Fills a paragraph line by line (lines are split by hard breaks). A line that had placeholders and no text
     * afterwards goes with its break; an empty line without placeholders is wanted and stays.
     *
     * @param  array<string, mixed>  $node
     * @param  array<string, list<array<string, mixed>>>  $values
     * @return array<string, mixed>
     */
    private static function fillLines(array $node, array $values, bool &$hadVariable): array
    {
        if (($node['type'] ?? null) !== 'paragraph' || ! is_array($node['content'] ?? null)) {
            return $node;
        }
        $lines = [[]];
        foreach ($node['content'] as $child) {
            if (is_array($child) && ($child['type'] ?? null) === 'hardBreak') {
                $lines[] = [];
            } else {
                $lines[array_key_last($lines)][] = $child;
            }
        }

        $content = [];
        $kept = 0;
        foreach ($lines as $line) {
            $lineHadVariable = false;
            $filled = self::fill($line, $values, $lineHadVariable);
            $hadVariable = $hadVariable || $lineHadVariable;
            if ($lineHadVariable && ! self::hasText(['content' => $filled])) {
                continue;
            }
            if ($kept++ > 0) {
                $content[] = ['type' => 'hardBreak'];
            }
            array_push($content, ...$filled);
        }
        $node['content'] = $content;

        return $node;
    }

    /**
     * Replaces each placeholder by its nodes; marks of the placeholder (bold) go to the text, not to the badge.
     * An unknown placeholder stays empty.
     *
     * @param  array<mixed>  $inline
     * @param  array<string, list<array<string, mixed>>>  $values
     * @return list<array<string, mixed>>
     */
    private static function fill(array $inline, array $values, bool &$hadVariable): array
    {
        $out = [];
        foreach ($inline as $child) {
            if (! is_array($child)) {
                continue;
            }
            if (($child['type'] ?? null) !== 'docVariable') {
                $out[] = $child;

                continue;
            }
            $hadVariable = true;
            $name = $child['attrs']['name'] ?? '';
            foreach (is_string($name) ? ($values[$name] ?? []) : [] as $node) {
                if ($node['type'] === 'text' && isset($child['marks'])) {
                    $node['marks'] = $child['marks'];
                }
                $out[] = $node;
            }
        }

        return $out;
    }

    /**
     * @param  array<mixed>  $inline
     * @return array<string, mixed>
     */
    private static function paragraph(array $inline): array
    {
        $content = [];
        foreach ($inline as $child) {
            $type = is_array($child) ? ($child['type'] ?? null) : null;
            $name = is_array($child) ? ($child['attrs']['name'] ?? null) : null;
            $node = match ($type) {
                'text' => is_string($child['text'] ?? null) && $child['text'] !== '' ? ['type' => 'text', 'text' => $child['text']] : null,
                'hardBreak' => ['type' => 'hardBreak'],
                'docVariable' => in_array($name, self::KEYS, true)
                    ? ['type' => 'docVariable', 'attrs' => ['name' => $name]]
                    : throw new InvalidArgumentException(__('mail.signature.unknown_variable', ['name' => is_string($name) ? mb_substr($name, 0, 40) : '?'])),
                default => null,
            };
            if ($node === null) {
                throw new InvalidArgumentException(__('mail.signature.not_allowed'));
            }
            $marks = self::marks($child['marks'] ?? []);
            if ($marks !== [] && $type !== 'hardBreak') {
                $node['marks'] = $marks;
            }
            $content[] = $node;
        }

        return $content === [] ? ['type' => 'paragraph'] : ['type' => 'paragraph', 'content' => $content];
    }

    /** @return list<array<string, mixed>> */
    private static function marks(mixed $marks): array
    {
        if (! is_array($marks)) {
            throw new InvalidArgumentException(__('mail.signature.not_allowed'));
        }
        $clean = [];
        foreach ($marks as $mark) {
            $type = is_array($mark) ? ($mark['type'] ?? null) : null;
            if (! in_array($type, self::MARKS, true)) {
                throw new InvalidArgumentException(__('mail.signature.marks'));
            }
            if ($type === 'link') {
                $href = $mark['attrs']['href'] ?? null;
                if (! MailContentValidator::validHref($href)) {
                    throw new InvalidArgumentException(__('mail.content.link'));
                }
                $clean[] = ['type' => 'link', 'attrs' => ['href' => $href]];
            } else {
                $clean[] = ['type' => $type];
            }
        }

        return $clean;
    }

    public static function hasContent(mixed $node): bool
    {
        return is_array($node) && (($node['type'] ?? null) === 'docVariable' || self::hasText($node)
            || array_filter((array) ($node['content'] ?? []), self::hasContent(...)) !== []);
    }

    public static function hasText(mixed $node): bool
    {
        if (! is_array($node)) {
            return false;
        }
        if (($node['type'] ?? null) === 'text' && trim((string) ($node['text'] ?? '')) !== '') {
            return true;
        }

        return array_filter((array) ($node['content'] ?? []), self::hasText(...)) !== [];
    }
}
