<?php

declare(strict_types=1);

namespace App\Utils;

use App\Core\Env;
use App\Core\HttpException;

/**
 * Small persistent app settings in {storage}/settings.json (no database
 * table needed). Values are plain JSON; writes hold an exclusive lock and
 * replace the file atomically, so a crash never leaves a half-written file.
 */
final class SettingsStore
{
    /**
     * Folder for runtime files: STORAGE_PATH from backend/.env, default
     * backend/storage. Must be writable by the web server user (on macOS
     * XAMPP that is usually "daemon"). backend/ routes every URL to
     * index.php, so nothing in it is ever served or executed directly.
     */
    public static function storagePath(string $sub = ''): string
    {
        $base = rtrim((string) (Env::get('STORAGE_PATH', '') ?: BACKEND_ROOT . '/storage'), '/\\');

        return $sub === '' ? $base : $base . '/' . $sub;
    }

    /** Creates a storage folder if needed; a clear error (no path) when the server cannot. */
    public static function ensureDirectory(string $path): void
    {
        if (!is_dir($path) && !@mkdir($path, 0755, true) && !is_dir($path)) {
            error_log('[holcim-storage] Cannot create folder ' . $path);
            throw self::notWritable();
        }
        if (!is_writable($path)) {
            error_log('[holcim-storage] Folder is not writable by the web server: ' . $path);
            throw self::notWritable();
        }
    }

    public static function notWritable(): HttpException
    {
        return new HttpException(500, 'STORAGE_NOT_WRITABLE',
            'The server cannot save files: its storage folder is missing or not writable. Ask the administrator to check the server setup (README > Randomizer background).');
    }

    public static function get(string $key): mixed
    {
        return self::readAll()[$key] ?? null;
    }

    /** Sets (or with null removes) one key; other keys are kept as they are. */
    public static function set(string $key, mixed $value): void
    {
        $dir = self::storagePath();
        self::ensureDirectory($dir);
        $lock = fopen($dir . '/settings.lock', 'c');
        if ($lock === false) {
            throw self::notWritable();
        }
        try {
            flock($lock, LOCK_EX);
            $all = self::readAll();
            if ($value === null) {
                unset($all[$key]);
            } else {
                $all[$key] = $value;
            }
            $tmp = $dir . '/settings.json.' . bin2hex(random_bytes(6)) . '.tmp';
            $json = json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            if (file_put_contents($tmp, $json . "\n") === false || !@chmod($tmp, 0644) || !rename($tmp, $dir . '/settings.json')) {
                @unlink($tmp);
                throw self::notWritable();
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return array<string, mixed> */
    private static function readAll(): array
    {
        $file = self::storagePath('settings.json');
        if (!is_file($file)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($file), true);

        return is_array($data) ? $data : [];
    }
}
