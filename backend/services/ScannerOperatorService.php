<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Models\Event;
use App\Models\EventDay;
use App\Models\User;

/**
 * Settings > Scanner Operators (Phase 8, admin only).
 *
 * Scanner operators are ordinary `users` rows with role scanner_operator and
 * a username (no email). They sign in through the normal login and session
 * system. Passwords are hashed with password_hash() and never returned.
 * Disabling an account ends its session on the next request (AuthService
 * reloads the user on every request). Only scanner_operator accounts can be
 * changed here.
 */
final class ScannerOperatorService
{
    public const MIN_PASSWORD_LENGTH = 8;

    /** @return array<string, mixed> */
    public static function list(): array
    {
        $users = User::allWithRole(User::ROLE_SCANNER_OPERATOR);

        // Today's successful check-ins per operator in one grouped query.
        $today = [];
        $event = Event::findActive();
        $day = $event !== null ? EventDay::findActiveForEvent((int) $event['id']) : null;
        if ($day !== null) {
            $statement = Database::connection()->prepare(
                'SELECT scanner_user_id, COUNT(*) AS total FROM registration_scans
                 WHERE event_day_id = :day_id AND scanner_user_id IS NOT NULL GROUP BY scanner_user_id'
            );
            $statement->execute(['day_id' => (int) $day['id']]);
            foreach ($statement->fetchAll() as $row) {
                $today[(int) $row['scanner_user_id']] = (int) $row['total'];
            }
        }

        return [
            'eventDay' => $day !== null ? EventDay::toPublic($day) : null,
            'operators' => array_map(static fn (array $user): array => self::present($user, $today[(int) $user['id']] ?? 0), $users),
        ];
    }

    /**
     * @param array{name: string, username: string, status?: string} $data
     * @return array<string, mixed>
     */
    public static function create(Request $request, array $data, string $password): array
    {
        $username = strtolower($data['username']);
        if (User::usernameTaken($username)) {
            throw HttpException::validation(['username' => ['This username is already in use.']]);
        }

        try {
            $id = User::createWithUsername(
                $data['name'],
                $username,
                password_hash($password, PASSWORD_DEFAULT),
                User::ROLE_SCANNER_OPERATOR,
                $data['status'] ?? User::STATUS_ACTIVE
            );
        } catch (\PDOException $exception) {
            if ($exception->getCode() === '23000') {
                throw HttpException::validation(['username' => ['This username is already in use.']]);
            }
            throw $exception;
        }
        $user = self::findOrFail($id);

        AuditLogger::log($request, 'user.scanner_operator_created', "Created scanner operator \"{$user['name']}\" ({$user['username']}).", metadata: [
            'user_id' => $id,
            'status' => $user['status'],
        ]);

        return self::present($user, 0);
    }

    /** @return array<string, mixed> */
    public static function setStatus(Request $request, int $id, string $status): array
    {
        $before = self::findOrFail($id);
        if ($before['status'] !== $status) {
            User::setStatus($id, $status);
            AuditLogger::log(
                $request,
                $status === User::STATUS_ACTIVE ? 'user.scanner_operator_enabled' : 'user.scanner_operator_disabled',
                ($status === User::STATUS_ACTIVE ? 'Enabled' : 'Disabled') . " scanner operator \"{$before['name']}\" ({$before['username']}).",
                metadata: ['user_id' => $id]
            );
        }

        return self::present(self::findOrFail($id), 0);
    }

    public static function resetPassword(Request $request, int $id, string $password): void
    {
        $user = self::findOrFail($id);
        User::updatePasswordHash($id, password_hash($password, PASSWORD_DEFAULT));
        AuditLogger::log(
            $request,
            'user.scanner_operator_password_reset',
            "Reset the password of scanner operator \"{$user['name']}\" ({$user['username']}).",
            metadata: ['user_id' => $id]
        );
    }

    /** Validates a new password + confirmation; returns the raw password. */
    public static function validatePassword(mixed $password, mixed $confirmation): string
    {
        $errors = [];
        if (!is_string($password) || $password === '') {
            $errors['password'][] = 'Password is required.';
        } elseif (mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
            $errors['password'][] = 'Password must be at least ' . self::MIN_PASSWORD_LENGTH . ' characters.';
        } elseif (strlen($password) > 1024) {
            $errors['password'][] = 'Password is too long.';
        }
        if ($errors === [] && $confirmation !== $password) {
            $errors['password_confirmation'][] = 'Passwords do not match.';
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }

        return (string) $password;
    }

    /** @return array<string, mixed> */
    private static function findOrFail(int $id): array
    {
        $user = User::findById($id);
        if ($user === null || $user['role'] !== User::ROLE_SCANNER_OPERATOR) {
            throw HttpException::notFound('Scanner operator not found.');
        }

        return $user;
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed> never includes the password hash
     */
    private static function present(array $user, int $scansToday): array
    {
        return [
            'id' => (int) $user['id'],
            'name' => $user['name'],
            'username' => $user['username'],
            'status' => $user['status'],
            'lastLoginAt' => $user['last_login_at'],
            'createdAt' => $user['created_at'],
            'scansToday' => $scansToday,
        ];
    }
}
