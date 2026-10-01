<?php

declare(strict_types=1);

namespace App\Utils;

/**
 * Cryptographically secure opaque tokens (CSRF tokens now; attendee QR
 * tokens in a later phase). Output is URL-safe base64 without padding.
 */
final class Token
{
    /** 32 bytes -> 43 characters, 256 bits of entropy. */
    public static function random(int $bytes = 32): string
    {
        if ($bytes < 16) {
            throw new \InvalidArgumentException('Tokens must use at least 16 random bytes.');
        }

        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }
}
