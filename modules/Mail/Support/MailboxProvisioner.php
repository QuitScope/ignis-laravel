<?php

namespace Modules\Mail\Support;

use App\Models\Personnel;
use App\Support\Settings;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Modules\Mail\Models\Mailbox;

/**
 * Personal mailboxes follow the records (legacy MailboxProvisioner):
 * - record in service: create or reactivate the mailbox; the display name follows the formal name with title, the
 *   address is built once (AddressGenerator, without title) and then stays;
 * - record in an archive rank (left the service): mailbox inactive;
 * - record deleted: its personal mailbox goes inactive while it is deleted (deactivateFor, before the FK sets
 *   personnel_id NULL); the nightly backfill deactivates any other personal mailbox without a record.
 * Group mailboxes and `is_locked` are never touched; `user_id` only through MailboxAccess::autoBind (never moved).
 * An address error is logged and swallowed: saving the record matters more.
 */
final class MailboxProvisioner
{
    private const MAX_ADDRESS_ATTEMPTS = 3;

    public function sync(int $personnelId): ?Mailbox
    {
        $person = Personnel::query()->with(['rank', 'title'])->find($personnelId);
        if ($person === null) {
            $this->deactivateFor($personnelId);

            return null;
        }
        $mailbox = Mailbox::query()->where('personnel_id', $person->id)->first();

        if ($person->rank->is_archive) {
            if ($mailbox !== null && $mailbox->is_active) {
                $mailbox->forceFill(['is_active' => false])->save();
            }

            return $mailbox;
        }

        $name = self::displayName($person);
        if ($mailbox === null) {
            $mailbox = $this->create($person, $name);
        } elseif (! $mailbox->is_active || ($name !== '' && $mailbox->display_name !== $name)) {
            $mailbox->forceFill(['is_active' => true] + ($name !== '' ? ['display_name' => $name] : []))->save();
        }
        if ($mailbox !== null) {
            MailboxAccess::autoBind($mailbox);
        }

        return $mailbox;
    }

    public function deactivateOrphans(): int
    {
        $count = Mailbox::query()->where('is_active', true)->where('kind', Mailbox::PERSONAL)->whereNull('personnel_id')
            ->update(['is_active' => false, 'updated_at' => now()]);
        MailboxAccess::forget();

        return $count;
    }

    public function deactivateFor(int $personnelId): void
    {
        Mailbox::query()->where('personnel_id', $personnelId)->where('kind', Mailbox::PERSONAL)->where('is_active', true)
            ->update(['is_active' => false, 'updated_at' => now()]);
        MailboxAccess::forget();
    }

    private function create(Personnel $person, string $name): ?Mailbox
    {
        $domain = AddressRules::defaultDomain();
        $pattern = (string) app(Settings::class)->get('mail.address_pattern', 'initial_dot_last');

        for ($attempt = 1; $attempt <= self::MAX_ADDRESS_ATTEMPTS; $attempt++) {
            try {
                $address = AddressGenerator::for($person, $pattern, $domain);
            } catch (InvalidArgumentException $e) {
                Log::error('Mail: mailbox not created: '.$e->getMessage(), ['personnel_id' => $person->id]);

                return null;
            }
            try {
                return Mailbox::query()->forceCreate([
                    'kind' => Mailbox::PERSONAL, 'personnel_id' => $person->id, 'address' => $address,
                    'display_name' => $name !== '' ? $name : $address, 'domain' => $domain,
                ]);
            } catch (UniqueConstraintViolationException) {
                $existing = Mailbox::query()->where('personnel_id', $person->id)->first();
                if ($existing !== null) {
                    return $existing;
                }
            }
        }
        Log::error('Mail: address collision after retries', ['personnel_id' => $person->id]);

        return null;
    }

    private static function displayName(Personnel $person): string
    {
        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', $person->displayName())), 0, 150);
    }
}
