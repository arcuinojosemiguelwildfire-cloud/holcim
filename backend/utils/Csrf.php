<?php

declare(strict_types=1);

namespace App\Utils;

use App\Core\Session;

/**
 * Synchronizer-token CSRF protection for the session-based API.
 *
 * The token lives in the server-side session and is handed to the SPA via
 * GET /auth/session. The SPA sends it back in the X-CSRF-Token header on every
 * POST/PUT/PATCH/DELETE. A cross-site attacker cannot read the token, so
 * forged requests are rejected even though the browser attaches the cookie.
 */
final class Csrf
{
    public const HEADER = 'X-CSRF-Token';
    private const SESSION_KEY = '_csrf_token';

    public static function token(): string
    {
        $token = Session::get(self::SESSION_KEY);
        if (!is_string($token) || $token === '') {
            $token = self::rotate();
        }

        return $token;
    }

    public static function rotate(): string
    {
        $token = Token::random(32);
        Session::set(self::SESSION_KEY, $token);

        return $token;
    }

    public static function isValid(?string $provided): bool
    {
        $expected = Session::get(self::SESSION_KEY);

        return is_string($expected)
            && $expected !== ''
            && is_string($provided)
            && hash_equals($expected, $provided);
    }
}
