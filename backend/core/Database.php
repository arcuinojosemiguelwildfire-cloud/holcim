<?php

declare(strict_types=1);

namespace App\Core;

use PDO;

/**
 * Lazily-created shared PDO connection.
 *
 * - Real prepared statements (ATTR_EMULATE_PREPARES = false)
 * - Exceptions on error
 * - utf8mb4 and a session time zone that matches APP_TIMEZONE
 */
final class Database
{
    private static ?PDO $connection = null;

    public static function connection(): PDO
    {
        if (self::$connection === null) {
            self::$connection = self::connect();
        }

        return self::$connection;
    }

    /**
     * Runs $callback inside a transaction and returns its result.
     *
     * @template T
     * @param callable(PDO): T $callback
     * @return T
     */
    public static function transaction(callable $callback): mixed
    {
        $pdo = self::connection();
        $pdo->beginTransaction();

        try {
            $result = $callback($pdo);
            $pdo->commit();

            return $result;
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    private static function connect(): PDO
    {
        $config = Config::get('database');

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $config['host'],
            $config['port'],
            $config['database'],
            $config['charset']
        );

        $pdo = new PDO($dsn, $config['username'], $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);

        // Keep MySQL timestamps consistent with PHP's configured time zone.
        $pdo->exec("SET time_zone = '" . (new \DateTimeImmutable())->format('P') . "'");

        return $pdo;
    }
}
