<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Hardened PHP session wrapper.
 *
 * Sessions are only started for endpoints that need them (auth, CSRF,
 * protected routes), so anonymous calls like /health never create one.
 */
final class Session
{
    private const LAST_ACTIVITY_KEY = '_last_activity';

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $config = Config::get('session');

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.gc_maxlifetime', (string) $config['lifetime']);

        session_name($config['name']);
        session_set_cookie_params([
            'lifetime' => 0, // browser-session cookie; idle timeout is enforced server-side
            'path' => $config['path'],
            'domain' => $config['domain'],
            'secure' => $config['secure'],
            'httponly' => true,
            'samesite' => $config['samesite'],
        ]);

        session_start();
        self::enforceIdleTimeout((int) $config['lifetime']);
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        self::start();

        return $_SESSION[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        self::start();
        $_SESSION[$key] = $value;
    }

    public static function forget(string $key): void
    {
        self::start();
        unset($_SESSION[$key]);
    }

    /** Issue a new session ID (prevents session fixation after login). */
    public static function regenerate(): void
    {
        self::start();
        session_regenerate_id(true);
    }

    public static function destroy(): void
    {
        self::start();
        $_SESSION = [];

        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 3600,
            'path' => $params['path'],
            'domain' => $params['domain'],
            'secure' => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'] ?: 'Lax',
        ]);

        session_destroy();
    }

    private static function enforceIdleTimeout(int $lifetimeSeconds): void
    {
        $now = time();
        $lastActivity = $_SESSION[self::LAST_ACTIVITY_KEY] ?? null;

        if (is_int($lastActivity) && ($now - $lastActivity) > $lifetimeSeconds) {
            $_SESSION = [];
            session_regenerate_id(true);
        }

        $_SESSION[self::LAST_ACTIVITY_KEY] = $now;
    }
}
