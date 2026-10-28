<?php

namespace Modules\Mail\Support;

use App\Support\Settings;
use Illuminate\Support\Facades\DB;

/**
 * Address rules (legacy MailAddressRules): allowed domains, the form of hand-set addresses (lists, corrections by the
 * administration) and whether an address is taken. Mailboxes, lists and former addresses share one address space;
 * the unique index of each table only covers itself, isTaken() covers all three.
 */
final class AddressRules
{
    public const MAX_LOCAL_LENGTH = 64;

    public const DEFAULT_DOMAIN = 'ignis.ef';

    private const LOCAL_PATTERN = '/^[a-z0-9]([a-z0-9._-]*[a-z0-9])?$/D';

    private const DOMAIN_PATTERN = '/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/D';

    public static function defaultDomain(): string
    {
        $domain = self::normalize((string) app(Settings::class)->get('mail.domain', self::DEFAULT_DOMAIN));

        return self::isDomain($domain) ? $domain : self::DEFAULT_DOMAIN;
    }

    /**
     * The selectable domains, default first, without duplicates or invalid entries.
     *
     * @return list<string>
     */
    public static function allowedDomains(): array
    {
        $extra = app(Settings::class)->get('mail.allowed_domains', []);

        return self::parseDomains([self::defaultDomain(), ...(is_array($extra) ? $extra : [])]);
    }

    /**
     * @param  iterable<mixed>  $entries
     * @return list<string>
     */
    public static function parseDomains(iterable $entries): array
    {
        $domains = [];
        foreach ($entries as $entry) {
            $entry = is_string($entry) ? self::normalize($entry) : '';
            if (self::isDomain($entry) && ! in_array($entry, $domains, true)) {
                $domains[] = $entry;
            }
        }

        return $domains;
    }

    public static function isDomain(string $domain): bool
    {
        return strlen($domain) <= 100 && preg_match(self::DOMAIN_PATTERN, $domain) === 1;
    }

    public static function normalize(string $value): string
    {
        return mb_strtolower(trim($value), 'UTF-8');
    }

    public static function validate(string $address, ?int $exceptMailboxId = null, ?int $exceptListId = null, ?string $keepDomain = null): ?string
    {
        $at = strrpos($address, '@');
        if ($at === false || $at === 0) {
            return __('mail.address.invalid');
        }
        $local = substr($address, 0, $at);
        $domain = substr($address, $at + 1);

        if (strlen($local) > self::MAX_LOCAL_LENGTH) {
            return __('mail.address.too_long', ['max' => self::MAX_LOCAL_LENGTH]);
        }
        if (preg_match(self::LOCAL_PATTERN, $local) !== 1 || str_contains($local, '..')) {
            return __('mail.address.local');
        }
        $allowed = self::allowedDomains();
        if (! in_array($domain, $allowed, true) && $domain !== ($keepDomain === null ? null : self::normalize($keepDomain))) {
            return __('mail.address.domain', ['domain' => $domain, 'allowed' => implode(', ', $allowed)]);
        }
        if (self::isTaken($address, $exceptMailboxId, $exceptListId)) {
            return __('mail.address.taken', ['address' => $address]);
        }

        return null;
    }

    /**
     * Taken: a mailbox or list carries it, or a mailbox had it before. A former address stays reserved for its own
     * mailbox (only `$exceptMailboxId` equal to it gets it back), else replies to old mail would reach someone else.
     */
    public static function isTaken(string $address, ?int $exceptMailboxId = null, ?int $exceptListId = null): bool
    {
        $address = self::normalize($address);

        return DB::table('mail_mailboxes')->where('address', $address)
            ->when($exceptMailboxId !== null, fn ($q) => $q->where('id', '!=', $exceptMailboxId))->exists()
            || DB::table('mail_lists')->where('address', $address)
                ->when($exceptListId !== null, fn ($q) => $q->where('id', '!=', $exceptListId))->exists()
            || DB::table('mail_address_history')->where('address', $address)
                ->when($exceptMailboxId !== null, fn ($q) => $q->where('mailbox_id', '!=', $exceptMailboxId))->exists();
    }

    public static function reserve(string $address, int $mailboxId): void
    {
        DB::table('mail_address_history')->updateOrInsert(['address' => self::normalize($address)], ['mailbox_id' => $mailboxId, 'released_at' => now()]);
    }
}
