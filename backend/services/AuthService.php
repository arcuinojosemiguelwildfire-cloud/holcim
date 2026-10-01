<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Session;
use App\Models\User;
use App\Utils\Csrf;

/**
 * Session-based authentication.
 *
 * The session stores only the user ID. The user row is reloaded once per
 * request so role changes and deactivation take effect immediately.
 */
final class AuthService
{
    private const SESSION_USER_KEY = '_user_id';
    private const SESSION_ROLE_KEY = '_user_role';
    private const SESSION_LOGIN_AT_KEY = '_login_at';

    /** @var array<string, mixed>|null */
    private static ?array $currentUser = null;
    private static bool $resolved = false;

    /**
     * @return array<string, mixed> the authenticated public user
     * @throws HttpException 401 on bad credentials or inactive account
     */
    public static function attempt(Request $request, string $email, string $password): array
    {
        $user = User::findByEmailWithPassword($email);

        // Always run password_verify so response time doesn't reveal whether
        // the email exists (user enumeration via timing).
        $hash = $user['password_hash'] ?? self::dummyHash();
        $passwordMatches = password_verify($password, $hash);

        if ($user === null || !$passwordMatches || $user['status'] !== User::STATUS_ACTIVE) {
            AuditLogger::log(
                $request,
                AuditLogger::AUTH_LOGIN_FAILED,
                'Failed login attempt.',
                metadata: [
                    'email' => strtolower($email),
                    'reason' => $user === null ? 'unknown_email' : ($passwordMatches ? 'inactive_account' : 'wrong_password'),
                ],
                userId: $user !== null ? (int) $user['id'] : null
            );

            throw new HttpException(401, 'INVALID_CREDENTIALS', 'Invalid email or password.');
        }

        $userId = (int) $user['id'];

        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            User::updatePasswordHash($userId, password_hash($password, PASSWORD_DEFAULT));
        }

        // New session ID + new CSRF token on privilege change (anti-fixation).
        Session::regenerate();
        Session::set(self::SESSION_USER_KEY, $userId);
        Session::set(self::SESSION_ROLE_KEY, $user['role']);
        Session::set(self::SESSION_LOGIN_AT_KEY, time());
        Csrf::rotate();

        User::touchLastLogin($userId);

        self::$currentUser = User::findById($userId);
        self::$resolved = true;

        AuditLogger::log($request, AuditLogger::AUTH_LOGIN, 'User logged in.', userId: $userId);

        return User::toPublic(self::$currentUser ?? $user);
    }

    public static function logout(Request $request): void
    {
        $userId = self::currentUserId();
        if ($userId !== null) {
            AuditLogger::log($request, AuditLogger::AUTH_LOGOUT, 'User logged out.', userId: $userId);
        }

        Session::destroy();
        self::$currentUser = null;
        self::$resolved = true;
    }

    /** @return array<string, mixed>|null raw user row for the current session */
    public static function currentUser(): ?array
    {
        if (self::$resolved) {
            return self::$currentUser;
        }

        self::$resolved = true;
        $userId = Session::get(self::SESSION_USER_KEY);
        if (!is_int($userId)) {
            return null;
        }

        $user = User::findById($userId);
        if ($user === null || $user['status'] !== User::STATUS_ACTIVE) {
            // Account removed or deactivated since login: end the session.
            Session::forget(self::SESSION_USER_KEY);
            Session::forget(self::SESSION_ROLE_KEY);

            return null;
        }

        if (Session::get(self::SESSION_ROLE_KEY) !== $user['role']) {
            Session::set(self::SESSION_ROLE_KEY, $user['role']);
        }

        self::$currentUser = $user;

        return $user;
    }

    public static function currentUserId(): ?int
    {
        $user = self::currentUser();

        return $user !== null ? (int) $user['id'] : null;
    }

    private static function dummyHash(): string
    {
        static $hash = null;

        return $hash ??= password_hash('timing-equaliser-' . bin2hex(random_bytes(8)), PASSWORD_DEFAULT);
    }
}
