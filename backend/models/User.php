<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Data access for the `users` table. All queries use prepared statements.
 *
 * @phpstan-type UserRow array{id:int,name:string,email:string,role:string,status:string,last_login_at:?string,created_at:string,updated_at:string}
 */
final class User
{
    public const ROLE_ADMIN = 'admin';
    public const ROLE_REGISTRATION_STAFF = 'registration_staff';
    public const ROLE_EVENT_OPERATOR = 'event_operator';

    public const ROLES = [
        self::ROLE_ADMIN,
        self::ROLE_REGISTRATION_STAFF,
        self::ROLE_EVENT_OPERATOR,
    ];

    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';

    private const PUBLIC_COLUMNS = 'id, name, email, role, status, last_login_at, created_at, updated_at';

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
            'role' => $user['role'],
            'status' => $user['status'],
            'lastLoginAt' => $user['last_login_at'],
        ];
    }
}
