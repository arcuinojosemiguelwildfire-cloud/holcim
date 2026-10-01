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
    /** users.session_version at login; a password reset increments it (Phase 8.1). */
    private const SESSION_VERSION_KEY = '_session_version';

    /** @var array<string, mixed>|null */
    private static ?array $currentUser = null;
    private static bool $resolved = false;

    /**
     * @return array<string, mixed> the authenticated public user
     * @throws HttpException 401 on bad credentials or inactive account, 429 when locked out
     */
    public static function attempt(Request $request, string $email, string $password): array
    {
        // $email is the login identifier: an email address or (Phase 8) a username.
        LoginThrottle::assertNotLocked($email, $request->ip());

        $user = User::findByLoginWithPassword($email);

        // Always run password_verify so response time doesn't reveal whether
        // the email exists (user enumeration via timing).
        $hash = $user['password_hash'] ?? self::dummyHash();
        $passwordMatches = password_verify($password, $hash);

        if ($user === null || !$passwordMatches || $user['status'] !== User::STATUS_ACTIVE) {
            LoginThrottle::recordFailure($email, $request->ip());
            AuditLogger::log(
                $request,
                AuditLogger::AUTH_LOGIN_FAILED,
                'Failed login attempt.',
                metadata: [
                    'login' => strtolower($email),
                    'reason' => $user === null ? 'unknown_login' : ($passwordMatches ? 'inactive_account' : 'wrong_password'),
                ],
                userId: $user !== null ? (int) $user['id'] : null
            );

            throw new HttpException(401, 'INVALID_CREDENTIALS', 'Invalid email/username or password.');
        }

        $userId = (int) $user['id'];
        LoginThrottle::clear($email);

        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            User::updatePasswordHash($userId, password_hash($password, PASSWORD_DEFAULT));
        }

        // New session ID + new CSRF token on privilege change (anti-fixation).
        Session::regenerate();
        Session::set(self::SESSION_USER_KEY, $userId);
        Session::set(self::SESSION_ROLE_KEY, $user['role']);
        Session::set(self::SESSION_LOGIN_AT_KEY, time());
        Session::set(self::SESSION_VERSION_KEY, (int) ($user['session_version'] ?? 0));
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
        // Sessions created before migration 030 have no stored version: treat as 0.
        $sessionVersion = (int) (Session::get(self::SESSION_VERSION_KEY) ?? 0);
        if ($user === null || $user['status'] !== User::STATUS_ACTIVE || (int) $user['session_version'] !== $sessionVersion) {
            // Account removed, deactivated, or password reset since login: end the session.
            Session::forget(self::SESSION_USER_KEY);
            Session::forget(self::SESSION_ROLE_KEY);
            Session::forget(self::SESSION_VERSION_KEY);

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
