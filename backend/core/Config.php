<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Read-only access to the arrays returned by backend/config/*.php,
 * using dot notation: Config::get('database.host').
 */
final class Config
{
    /** @var array<string, array<string, mixed>> */
    private static array $items = [];

    public static function loadDirectory(string $directory): void
    {
        foreach (glob(rtrim($directory, '/') . '/*.php') ?: [] as $file) {
            $name = basename($file, '.php');
            if ($name === 'bootstrap') {
                continue;
            }
            $values = require $file;
            if (is_array($values)) {
                self::$items[$name] = $values;
            }
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = self::$items;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }
}
