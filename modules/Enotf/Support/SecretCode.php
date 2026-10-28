<?php

namespace Modules\Enotf\Support;

/** Random codes people type in (access and clinic codes): 32 symbols without 0/O/1/I, so 5 bits per character. */
final class SecretCode
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public static function generate(int $length): string
    {
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= self::ALPHABET[random_int(0, 31)];
        }

        return $out;
    }

    public static function display(string $code): string
    {
        return implode('-', str_split($code, 4));
    }

    public static function normalize(string $input): string
    {
        return strtoupper((string) preg_replace('/[\s-]+/', '', $input));
    }
}
