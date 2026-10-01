<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\Core\HttpException;

/**
 * Basic brute-force protection for POST /auth/login (Phase 7).
 *
 * Failed attempts are counted per email (hashed) and per IP inside a sliding
 * window (LOGIN_LOCKOUT_MINUTES). When either limit is reached, further
 * attempts are refused with a generic 429 until old failures age out.
 * A successful login clears that email's failures. The response is the same
 * whether or not the account exists.
 */
final class LoginThrottle
{
    public static function assertNotLocked(string $email, string $ip): void
    {
        $minutes = (int) Config::get('auth.lockout_minutes', 15);
        $since = (new \DateTimeImmutable("-{$minutes} minutes"))->format('Y-m-d H:i:s');

        $statement = Database::connection()->prepare(
            'SELECT
                (SELECT COUNT(*) FROM login_attempts WHERE email_hash = :hash AND attempted_at > :since1) AS by_email,
                (SELECT COUNT(*) FROM login_attempts WHERE ip_address = :ip AND attempted_at > :since2) AS by_ip'
        );
        $statement->execute(['hash' => self::hash($email), 'ip' => $ip, 'since1' => $since, 'since2' => $since]);
        $counts = $statement->fetch() ?: ['by_email' => 0, 'by_ip' => 0];

        if ((int) $counts['by_email'] >= (int) Config::get('auth.max_attempts_per_email', 5)
            || (int) $counts['by_ip'] >= (int) Config::get('auth.max_attempts_per_ip', 30)) {
            throw new HttpException(
                429,
                'TOO_MANY_ATTEMPTS',
                "Too many failed sign-in attempts. Please wait {$minutes} minutes and try again, or ask an administrator."
            );
        }
    }

    public static function recordFailure(string $email, string $ip): void
    {
        $pdo = Database::connection();
        $pdo->prepare('INSERT INTO login_attempts (email_hash, ip_address, attempted_at) VALUES (:hash, :ip, NOW())')
            ->execute(['hash' => self::hash($email), 'ip' => $ip]);

        // Occasional housekeeping: drop rows older than a day.
        if (random_int(1, 50) === 1) {
            $pdo->exec('DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL 1 DAY)');
        }
    }

    public static function clear(string $email): void
    {
        Database::connection()->prepare('DELETE FROM login_attempts WHERE email_hash = :hash')
            ->execute(['hash' => self::hash($email)]);
    }

    private static function hash(string $email): string
    {
        return hash('sha256', strtolower(trim($email)));
    }
}
