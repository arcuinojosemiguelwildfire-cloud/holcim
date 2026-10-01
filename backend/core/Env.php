<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Minimal .env loader (no Composer dependency, works on any PHP host).
 *
 * Precedence: real environment variables (set by the host / Apache SetEnv)
 * always win over values from the .env file, so production servers can
 * inject secrets without a file on disk.
 */
final class Env
{
    /** @var array<string, string> */
    private static array $values = [];

    public static function load(string $path): void
    {
        if (!is_file($path) || !is_readable($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = array_map('trim', explode('=', $line, 2));
            if ($key === '' || !preg_match('/^[A-Z][A-Z0-9_]*$/', $key)) {
                continue;
            }

            self::$values[$key] = self::stripQuotes($value);
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $fromEnvironment = getenv($key);
        if ($fromEnvironment !== false) {
            return $fromEnvironment;
        }

        return self::$values[$key] ?? $default;
    }

    public static function require(string $key): string
    {
        $value = self::get($key);
        if ($value === null) {
            throw new \RuntimeException("Missing required environment variable: {$key}");
        }

        return $value;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);
        if ($value === null || $value === '') {
            return $default;
        }

        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    public static function int(string $key, int $default): int
    {
        $value = self::get($key);

        return ($value !== null && is_numeric($value)) ? (int) $value : $default;
    }

    private static function stripQuotes(string $value): string
    {
        $length = strlen($value);
        if ($length >= 2) {
            $first = $value[0];
            $last = $value[$length - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                return substr($value, 1, -1);
            }
        }

        // Strip trailing inline comments for unquoted values: KEY=value # note
        $commentPosition = strpos($value, ' #');

        return $commentPosition === false ? $value : rtrim(substr($value, 0, $commentPosition));
    }
}
