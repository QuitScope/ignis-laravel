<?php

namespace Modules\Mail\Support;

use App\Models\Personnel;
use Closure;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Builds the address of a personal mailbox (rebuilt; the legacy package emergencyforge/mail is not available, the
 * legacy MailProvisioningTest is the specification). From `full_name` only, so a title never appears in it.
 *
 * - umlauts and ß transliterated (ä => ae, ß => ss), other accents stripped, lower case;
 * - spaces and hyphens inside a name part become one hyphen ("von der Heide" => "von-der-heide");
 * - first word = first name, the rest = last name (one word: that word alone);
 * - `initial_dot_last`: "m.muster"; on collision the first name grows a letter at a time ("an.schmidt"), then a counter;
 * - `first_dot_last`: "joerg.mueller"; on collision a counter "joerg.mueller2", "…3";
 * - the local part is at most 64 characters (the name is cut, the counter kept).
 */
final class AddressGenerator
{
    public const PATTERNS = ['initial_dot_last', 'first_dot_last'];

    private const MAX_COUNTER = 9999;

    public static function for(Personnel $person, string $pattern, ?string $domain = null): string
    {
        return self::generate($person->full_name, $pattern, $domain ?? AddressRules::defaultDomain(), fn (string $a) => AddressRules::isTaken($a));
    }

    /**
     * @param  Closure(string): bool  $isTaken
     *
     * @throws InvalidArgumentException when the name yields no usable local part or no free address is found
     */
    public static function generate(string $fullName, string $pattern, string $domain, Closure $isTaken): string
    {
        $words = preg_split('/\s+/u', trim($fullName), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $first = count($words) > 1 ? self::slug((string) array_shift($words)) : '';
        $last = self::slug(implode(' ', $words));
        if ($last === '') {
            throw new InvalidArgumentException("No address can be built from the name \"{$fullName}\"");
        }

        $candidates = [];
        if ($first === '') {
            $base = $last;
        } elseif ($pattern === 'first_dot_last') {
            $base = $first.'.'.$last;
        } else {
            for ($n = 1; $n <= strlen($first); $n++) {
                $prefix = substr($first, 0, $n);
                if (! str_ends_with($prefix, '-')) {
                    $candidates[] = $prefix.'.'.$last;
                }
            }
            $base = $first[0].'.'.$last;
        }
        foreach ($candidates ?: [$base] as $local) {
            $address = self::cut($local, '').'@'.$domain;
            if (! $isTaken($address)) {
                return $address;
            }
        }
        for ($i = 2; $i <= self::MAX_COUNTER; $i++) {
            $address = self::cut($base, (string) $i).'@'.$domain;
            if (! $isTaken($address)) {
                return $address;
            }
        }

        throw new InvalidArgumentException("No free address for \"{$fullName}\"");
    }

    public static function slug(string $part): string
    {
        $part = strtr(mb_strtolower($part, 'UTF-8'), ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
        $part = strtolower(Str::ascii($part));
        $part = (string) preg_replace('/[\s_-]+/', '-', $part);
        $part = (string) preg_replace('/[^a-z0-9-]/', '', $part);

        return trim((string) preg_replace('/-+/', '-', $part), '-');
    }

    private static function cut(string $local, string $counter): string
    {
        return rtrim(substr($local, 0, AddressRules::MAX_LOCAL_LENGTH - strlen($counter)), '.-').$counter;
    }
}
