<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Data access for the `users` table. All queries use prepared statements.
 *
 * @phpstan-type UserRow array{id:int,name:string,email:?string,username:?string,role:string,status:string,last_login_at:?string,created_at:string,updated_at:string}
 */
final class User
{
    public const ROLE_ADMIN = 'admin';
    public const ROLE_REGISTRATION_STAFF = 'registration_staff';
    public const ROLE_EVENT_OPERATOR = 'event_operator';
    /** Phase 8: scanner-only account, managed in Settings > Scanner Operators. */
    public const ROLE_SCANNER_OPERATOR = 'scanner_operator';

    public const ROLES = [
        self::ROLE_ADMIN,
        self::ROLE_REGISTRATION_STAFF,
        self::ROLE_EVENT_OPERATOR,
        self::ROLE_SCANNER_OPERATOR,
    ];

    /** Lower-case letters, digits, dot, underscore, hyphen; 3-60 characters. */
    public const USERNAME_PATTERN = '/^[a-z0-9._-]{3,60}$/';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';

    private const PUBLIC_COLUMNS = 'id, name, email, username, role, status, session_version, last_login_at, created_at, updated_at';

    /** @return array<string, mixed>|null */
    public static function findById(int $id): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT ' . self::PUBLIC_COLUMNS . ' FROM users WHERE id = :id LIMIT 1'
        );
        $statement->execute(['id' => $id]);

        return $statement->fetch() ?: null;
    }

    /**
     * Includes password_hash: only for authentication, never return to clients.
     *
     * @return array<string, mixed>|null
     */
    public static function findByEmailWithPassword(string $email): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT ' . self::PUBLIC_COLUMNS . ', password_hash FROM users WHERE email = :email LIMIT 1'
        );
        $statement->execute(['email' => strtolower($email)]);

        return $statement->fetch() ?: null;
    }

    /**
     * Login identifier: an email (contains "@") or a username.
     * Includes password_hash: only for authentication, never return to clients.
     *
     * @return array<string, mixed>|null
     */
    public static function findByLoginWithPassword(string $login): ?array
    {
        $login = strtolower(trim($login));
        if (str_contains($login, '@')) {
            return self::findByEmailWithPassword($login);
        }
        $statement = Database::connection()->prepare(
            'SELECT ' . self::PUBLIC_COLUMNS . ', password_hash FROM users WHERE username = :username LIMIT 1'
        );
        $statement->execute(['username' => $login]);

        return $statement->fetch() ?: null;
    }

    /** Password hash for re-authentication checks only; never return it to clients. */
    public static function passwordHashFor(int $id): ?string
    {
        $statement = Database::connection()->prepare('SELECT password_hash FROM users WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $id]);
        $hash = $statement->fetchColumn();

        return is_string($hash) ? $hash : null;
    }

    public static function usernameTaken(string $username): bool
    {
        $statement = Database::connection()->prepare('SELECT COUNT(*) FROM users WHERE username = :username');
        $statement->execute(['username' => strtolower($username)]);

        return (int) $statement->fetchColumn() > 0;
    }

    /** Scanner operator: username login, no email. */
    public static function createWithUsername(string $name, string $username, string $passwordHash, string $role, string $status): int
    {
        Database::connection()->prepare(
            'INSERT INTO users (name, email, username, password_hash, role, status)
             VALUES (:name, NULL, :username, :password_hash, :role, :status)'
        )->execute([
            'name' => $name,
            'username' => strtolower($username),
            'password_hash' => $passwordHash,
            'role' => $role,
            'status' => $status,
        ]);

        return (int) Database::connection()->lastInsertId();
    }

    /** @return list<array<string, mixed>> users of a role, by name */
    public static function allWithRole(string $role): array
    {
        $statement = Database::connection()->prepare(
            'SELECT ' . self::PUBLIC_COLUMNS . ' FROM users WHERE role = :role ORDER BY name, id'
        );
        $statement->execute(['role' => $role]);

        return $statement->fetchAll();
    }

    public static function setStatus(int $id, string $status): void
    {
        Database::connection()->prepare('UPDATE users SET status = :status WHERE id = :id')
            ->execute(['status' => $status, 'id' => $id]);
    }

    public static function countActiveWithRole(string $role): int
    {
        $statement = Database::connection()->prepare("SELECT COUNT(*) FROM users WHERE role = :role AND status = 'active'");
        $statement->execute(['role' => $role]);

        return (int) $statement->fetchColumn();
    }

    public static function create(string $name, string $email, string $passwordHash, string $role): int
    {
        $statement = Database::connection()->prepare(
            'INSERT INTO users (name, email, password_hash, role, status)
             VALUES (:name, :email, :password_hash, :role, :status)'
        );
        $statement->execute([
            'name' => $name,
            'email' => strtolower($email),
            'password_hash' => $passwordHash,
            'role' => $role,
            'status' => self::STATUS_ACTIVE,
        ]);

        return (int) Database::connection()->lastInsertId();
    }

    public static function updatePasswordHash(int $id, string $passwordHash): void
    {
        $statement = Database::connection()->prepare(
            'UPDATE users SET password_hash = :password_hash WHERE id = :id'
        );
        $statement->execute(['password_hash' => $passwordHash, 'id' => $id]);
    }

    /**
     * Sets a new password hash and increments session_version in one
     * statement, so every session opened before the change is rejected on
     * its next request (AuthService::currentUser compares versions).
     */
    public static function resetPassword(int $id, string $passwordHash): void
    {
        Database::connection()->prepare(
            'UPDATE users SET password_hash = :password_hash, session_version = session_version + 1 WHERE id = :id'
        )->execute(['password_hash' => $passwordHash, 'id' => $id]);
    }

    public static function touchLastLogin(int $id): void
    {
        $statement = Database::connection()->prepare(
            'UPDATE users SET last_login_at = NOW() WHERE id = :id'
        );
        $statement->execute(['id' => $id]);
    }

    /**
     * Shape returned to API clients.
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public static function toPublic(array $user): array
    {
        return [
            'id' => (int) $user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'username' => $user['username'] ?? null,
            'role' => $user['role'],
            'status' => $user['status'],
            'lastLoginAt' => $user['last_login_at'],
        ];
    }
}
