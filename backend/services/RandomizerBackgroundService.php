<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Utils\SettingsStore;

/**
 * Custom background image of the Minor / Major Randomizer screen.
 *
 * The image is stored in {storage}/randomizer-backgrounds/ under a generated
 * name (bg-<random>.<ext>); the active one is recorded in settings.json under
 * "randomizer_background" (file name + metadata, never a path or blob URL).
 * No setting = the built-in default background. The storage folder is never
 * served directly: the image is streamed by GET /randomizers/background/image
 * after the normal authorization check.
 *
 * Uploads are validated on the server: size (<= 10 MB), real content type
 * (finfo), extension, image signature + structure (complete file), and that
 * GD can decode it without errors (GD is required). A failed upload never
 * touches the active background.
 */
final class RandomizerBackgroundService
{
    public const MAX_BYTES = 10 * 1024 * 1024;
    /** Largest accepted image (pixels); larger images are refused before decoding. */
    public const MAX_PIXELS = 50_000_000;
    public const MAX_SIDE = 12000;
    private const SETTING = 'randomizer_background';
    private const FOLDER = 'randomizer-backgrounds';
    /** mime => [extension, getimagesize type] */
    private const TYPES = [
        'image/jpeg' => ['jpg', IMAGETYPE_JPEG],
        'image/png' => ['png', IMAGETYPE_PNG],
        'image/webp' => ['webp', IMAGETYPE_WEBP],
    ];
    private const EXTENSIONS = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
    private const FILE_PATTERN = '/^bg-[a-f0-9]{24}\.(jpg|png|webp)$/';

    /** @return array{custom: bool, version: string|null, width: int|null, height: int|null, originalName: string|null, updatedAt: string|null, imagePath: string|null} */
    public static function current(): array
    {
        $setting = self::activeSetting();
        if ($setting === null) {
            return ['custom' => false, 'version' => null, 'width' => null, 'height' => null, 'originalName' => null, 'updatedAt' => null, 'imagePath' => null];
        }

        return [
            'custom' => true,
            'version' => substr((string) $setting['file'], 3, 12),
            'width' => (int) ($setting['width'] ?? 0),
            'height' => (int) ($setting['height'] ?? 0),
            'originalName' => (string) ($setting['originalName'] ?? ''),
            'updatedAt' => (string) ($setting['updatedAt'] ?? ''),
            // API path (relative to the API base); the version busts caches when the image changes.
            'imagePath' => '/randomizers/background/image?v=' . substr((string) $setting['file'], 3, 12),
        ];
    }

    /**
     * Validates and stores a new background, then makes it active.
     *
     * @param array<string, mixed>|null $file entry from $_FILES
     * @return array<string, mixed> the new current() state
     */
    public static function upload(Request $request, ?array $file): array
    {
        [$tmp, $originalName, $mime, $width, $height] = self::validate($file);

        $dir = SettingsStore::storagePath(self::FOLDER);
        SettingsStore::ensureDirectory($dir);
        $name = 'bg-' . bin2hex(random_bytes(12)) . '.' . self::TYPES[$mime][0];
        $target = $dir . '/' . $name;
        // move_uploaded_file only accepts real uploads; the generated name never overwrites anything.
        if (file_exists($target) || !move_uploaded_file($tmp, $target)) {
            error_log('[holcim-storage] Could not store the uploaded background image.');
            throw SettingsStore::notWritable();
        }
        @chmod($target, 0644);

        $previous = self::activeSetting();
        try {
            SettingsStore::set(self::SETTING, [
                'file' => $name,
                'mime' => $mime,
                'width' => $width,
                'height' => $height,
                'bytes' => filesize($target) ?: 0,
                'originalName' => $originalName,
                'updatedAt' => date('c'),
                'updatedBy' => AuthService::currentUserId(),
            ]);
        } catch (\Throwable $exception) {
            @unlink($target); // keep the current background active
            throw $exception;
        }
        if ($previous !== null) {
            self::deleteImage((string) $previous['file']);
        }

        AuditLogger::log($request, 'settings.randomizer_background_changed',
            sprintf('Changed the randomizer background image (%dx%d %s).', $width, $height, strtoupper(self::TYPES[$mime][0])),
            null, ['width' => $width, 'height' => $height, 'mime' => $mime]);

        return self::current();
    }

    /** Back to the built-in default. Only our own previous image file is removed. */
    public static function restoreDefault(Request $request): array
    {
        $previous = self::activeSetting();
        SettingsStore::set(self::SETTING, null);
        if ($previous !== null) {
            self::deleteImage((string) $previous['file']);
            AuditLogger::log($request, 'settings.randomizer_background_restored', 'Restored the default randomizer background.');
        }

        return self::current();
    }

    /** Streams the active image (404 when the default background is active). */
    public static function image(): Response
    {
        $setting = self::activeSetting();
        $path = $setting !== null ? SettingsStore::storagePath(self::FOLDER) . '/' . $setting['file'] : null;
        if ($path === null || !is_file($path) || !is_readable($path)) {
            throw HttpException::notFound('No custom randomizer background is set.');
        }

        return Response::raw(200, (string) file_get_contents($path), (string) $setting['mime'], [
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            // The URL carries a version (?v=), so the browser may keep it; private = not in shared caches.
            'Cache-Control' => 'private, max-age=86400',
            'Content-Disposition' => 'inline; filename="randomizer-background.' . self::TYPES[(string) $setting['mime']][0] . '"',
        ]);
    }

    /**
     * @param array<string, mixed>|null $file
     * @return array{0: string, 1: string, 2: string, 3: int, 4: int} tmp path, safe original name, mime, width, height
     */
    public static function validate(?array $file): array
    {
        $fail = static fn (string $message): HttpException => HttpException::validation(['image' => [$message]], $message);

        if ($file === null || !isset($file['error']) || is_array($file['error'])) {
            throw $fail('Choose a JPG, PNG or WebP image to upload.');
        }
        if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
            throw $fail('The image is larger than the server allows (maximum 10 MB).');
        }
        if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
            throw $fail('The upload failed. Please try again.');
        }
        $tmp = (string) $file['tmp_name'];
        $size = (int) (filesize($tmp) ?: 0);
        if ($size === 0) {
            throw $fail('The image file is empty.');
        }
        if ($size > self::MAX_BYTES) {
            throw $fail('The image must be 10 MB or smaller.');
        }

        $originalName = self::safeName((string) ($file['name'] ?? 'image'));
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!isset(self::EXTENSIONS[$extension])) {
            throw $fail('Only JPG, JPEG, PNG and WebP images are allowed.');
        }

        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        if (!isset(self::TYPES[$mime])) {
            throw $fail('This file is not a JPG, PNG or WebP image.');
        }
        if (self::EXTENSIONS[$extension] !== $mime) {
            throw $fail('The file extension does not match its content (.' . $extension . ' but the file is ' . strtoupper(self::TYPES[$mime][0]) . ').');
        }

        $info = @getimagesize($tmp);
        if ($info === false || ($info[2] ?? null) !== self::TYPES[$mime][1]) {
            throw $fail('The image could not be read. It may be damaged.');
        }
        [$width, $height] = [(int) $info[0], (int) $info[1]];
        if ($width < 1 || $height < 1 || $width > self::MAX_SIDE || $height > self::MAX_SIDE || $width * $height > self::MAX_PIXELS) {
            throw $fail('The image dimensions are not supported (maximum ' . self::MAX_SIDE . ' pixels per side).');
        }
        if (!self::isComplete($tmp, $mime)) {
            throw $fail('The image is incomplete or damaged. Export it again and retry.');
        }
        if (!self::decodes($tmp, $mime)) {
            throw $fail('The image could not be decoded. It may be damaged.');
        }

        return [$tmp, $originalName, $mime, $width, $height];
    }

    /** Structural end-of-file check: catches truncated / corrupted uploads that headers alone miss. */
    private static function isComplete(string $path, string $mime): bool
    {
        $size = (int) filesize($path);
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }
        try {
            if ($mime === 'image/png') {
                fseek($handle, max(0, $size - 12));
                $tail = (string) fread($handle, 12);

                return str_contains($tail, 'IEND');
            }
            if ($mime === 'image/jpeg') {
                $start = (string) fread($handle, 2);
                // End-of-image marker near the end (phones may append extra data after it).
                fseek($handle, max(0, $size - 65536));

                return $start === "\xFF\xD8" && str_contains((string) fread($handle, 65536), "\xFF\xD9");
            }
            // WebP: RIFF size field = file size - 8 (padding byte allowed).
            $head = (string) fread($handle, 12);
            $riff = unpack('V', substr($head, 4, 4))[1] ?? 0;

            return str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP' && ($riff + 8 === $size || $riff + 9 === $size);
        } finally {
            fclose($handle);
        }
    }

    private static function decodes(string $path, string $mime): bool
    {
        // Without a decoder a damaged image cannot be told apart from a good one, so refuse.
        if (!function_exists('imagecreatefromstring')) {
            error_log('[holcim-storage] PHP GD extension missing: randomizer background uploads are disabled.');
            throw new HttpException(500, 'IMAGE_CHECK_UNAVAILABLE',
                'The server cannot check images (the PHP GD extension is not enabled). Ask the administrator to enable it.');
        }
        $flag = ['image/jpeg' => IMG_JPG, 'image/png' => IMG_PNG, 'image/webp' => IMG_WEBP][$mime];
        if ((imagetypes() & $flag) === 0) {
            throw HttpException::validation(['image' => ['This server cannot read ' . strtoupper(self::TYPES[$mime][0]) . ' images. Use a JPG or PNG image.']],
                'This server cannot read ' . strtoupper(self::TYPES[$mime][0]) . ' images. Use a JPG or PNG image.');
        }
        // libjpeg / libpng report damaged data as warnings while still returning
        // a (partly grey) image, so warnings count as failures too.
        $warnings = [];
        // PHP hides libjpeg warnings by default (gd.jpeg_ignore_warning=1); show them for this check only.
        $ignore = ini_set('gd.jpeg_ignore_warning', '0');
        set_error_handler(static function (int $no, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        });
        try {
            $image = imagecreatefromstring((string) file_get_contents($path));
        } finally {
            restore_error_handler();
            if ($ignore !== false) {
                ini_set('gd.jpeg_ignore_warning', $ignore);
            }
        }
        if ($image === false) {
            return false;
        }
        imagedestroy($image);

        return !array_filter($warnings, static fn (string $w): bool => (bool) preg_match('/premature|corrupt|unrecoverable|truncat|damaged/i', $w));
    }

    /** @return array<string, mixed>|null the active setting when its file name is one of ours */
    private static function activeSetting(): ?array
    {
        $setting = SettingsStore::get(self::SETTING);
        if (!is_array($setting) || !preg_match(self::FILE_PATTERN, (string) ($setting['file'] ?? '')) || !isset(self::TYPES[(string) ($setting['mime'] ?? '')])) {
            return null;
        }

        return $setting;
    }

    /** Deletes one of OUR generated background files (never anything else). */
    private static function deleteImage(string $name): void
    {
        if (preg_match(self::FILE_PATTERN, $name)) {
            @unlink(SettingsStore::storagePath(self::FOLDER) . '/' . $name);
        }
    }

    private static function safeName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F]/', '', $name) ?? '';

        return mb_substr($name !== '' ? $name : 'image', 0, 150);
    }
}
