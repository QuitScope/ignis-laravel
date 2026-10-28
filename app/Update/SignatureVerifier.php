<?php

namespace App\Update;

/**
 * Ed25519 check of the operator's release signature. The public key (Base64) ships in resources/update/public.key and
 * is read through config('ignis.update.public_key'), never from settings or the network. As long as it is the
 * placeholder (or anything but a 32-byte key) nothing verifies and the update stays locked.
 */
final class SignatureVerifier
{
    public static function isConfigured(): bool
    {
        return self::key() !== null;
    }

    public static function verify(string $payload, string $signatureBase64): bool
    {
        $key = self::key();
        $signature = base64_decode(trim($signatureBase64), true);
        if ($key === null || $signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return false;
        }

        return sodium_crypto_sign_verify_detached($signature, $payload, $key);
    }

    private static function key(): ?string
    {
        $configured = config('ignis.update.public_key');
        $key = is_string($configured) ? base64_decode(trim($configured), true) : false;

        return is_string($key) && strlen($key) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES ? $key : null;
    }
}
