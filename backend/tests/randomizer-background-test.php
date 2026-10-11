<?php

declare(strict_types=1);

/**
 * Randomizer background test (HTTP, end to end): upload / replace / restore
 * the Randomizer background through the API, permissions, validation of bad
 * files, persistence across logins, and that no randomizer or attendee data
 * changes.
 *
 *   HOLCIM_TEST_EMAIL=admin@example.com HOLCIM_TEST_PASSWORD='...' \
 *     php backend/tests/randomizer-background-test.php http://localhost/Holcim/backend/api --write
 *
 * DEVELOPMENT ONLY. It refuses to run when a custom background is already
 * active (it would be replaced and then removed). It creates two test
 * accounts (event operator, registration staff) with random passwords and
 * disables them afterwards. Images are generated in memory (GD).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/support/TestApiClient.php';

use App\Core\Database;
use App\Core\Env;
use App\Models\User;
use App\Utils\SettingsStore;

$positional = array_values(array_filter(array_slice($argv, 1), static fn ($a) => !str_starts_with($a, '--')));
$baseUrl = rtrim($positional[0] ?? 'http://localhost/Holcim/backend/api', '/');
$adminEmail = getenv('HOLCIM_TEST_EMAIL') ?: '';
$adminPassword = getenv('HOLCIM_TEST_PASSWORD') ?: '';
if (!in_array('--write', $argv, true)) {
    fwrite(STDERR, "This test changes the randomizer background. Re-run with --write on a DEVELOPMENT setup.\n");
    exit(1);
}
if (Env::get('APP_ENV') === 'production') {
    fwrite(STDERR, "Refusing to run with APP_ENV=production.\n");
    exit(1);
}
if (!function_exists('curl_init') || !function_exists('imagecreatetruecolor') || $adminEmail === '' || $adminPassword === '') {
    fwrite(STDERR, "Needs the curl and gd extensions and HOLCIM_TEST_EMAIL / HOLCIM_TEST_PASSWORD (an admin account).\n");
    exit(1);
}

$passed = 0;
$failed = 0;
function check(string $label, bool $condition, mixed $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  PASS  {$label}\n";
    } else {
        $failed++;
        echo "  FAIL  {$label}" . ($detail !== '' ? '  -> ' . (is_string($detail) ? $detail : json_encode($detail)) : '') . "\n";
    }
}
function section(string $title): void
{
    echo "\n{$title}\n";
}

/** In-memory test image: a gradient with a pattern (realistic size, not blank). */
function makeImage(string $type, int $w = 1920, int $h = 1080): string
{
    $img = imagecreatetruecolor($w, $h);
    for ($y = 0; $y < $h; $y += 4) {
        $c = imagecolorallocate($img, (int) (40 + 150 * $y / $h), 90, (int) (200 - 120 * $y / $h));
        imagefilledrectangle($img, 0, $y, $w, $y + 3, $c);
    }
    for ($i = 0; $i < 200; $i++) {
        $c = imagecolorallocate($img, ($i * 37) % 255, ($i * 91) % 255, ($i * 53) % 255);
        imagefilledellipse($img, ($i * 97) % $w, ($i * 61) % $h, 40, 40, $c);
    }
    ob_start();
    match ($type) {
        'png' => imagepng($img),
        'jpg' => imagejpeg($img, null, 90),
        'webp' => imagewebp($img, null, 85),
        'gif' => imagegif($img),
    };
    imagedestroy($img);

    return (string) ob_get_clean();
}

function tempFile(string $bytes): string
{
    $file = tempnam(sys_get_temp_dir(), 'rbg-');
    file_put_contents($file, $bytes);

    return $file;
}

/** multipart POST of field "image" with the given client filename; returns [status, body, raw]. */
function uploadAs(TestApiClient $client, string $baseUrl, string $bytes, string $filename, string $mime = 'application/octet-stream', bool $withCsrf = true): array
{
    $file = tempFile($bytes);
    if (!$withCsrf) {
        $old = $client->swapCsrf(null);
    }
    $r = $client->uploadField('/settings/randomizer-background', 'image', $file, $filename, $mime);
    if (!$withCsrf) {
        $client->swapCsrf($old);
    }
    @unlink($file);

    return [$r['status'], $r['body'], $r['raw']];
}

/** GET of the image: [status, content-type, bytes, headers]. */
function fetchImage(TestApiClient $client, string $baseUrl, string $path): array
{
    $handle = $client->handle('GET', $path, null);
    $headers = [];
    curl_setopt($handle, CURLOPT_HEADERFUNCTION, static function ($ch, string $line) use (&$headers): int {
        if (str_contains($line, ':')) {
            [$k, $v] = explode(':', $line, 2);
            $headers[strtolower(trim($k))] = trim($v);
        }

        return strlen($line);
    });
    $body = (string) curl_exec($handle);

    return [(int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), $headers['content-type'] ?? '', $body, $headers];
}

$pdo = Database::connection();
$stamp = date('His') . '-' . bin2hex(random_bytes(2));
$admin = new TestApiClient($baseUrl);
if ($admin->login($adminEmail, $adminPassword) !== 200) {
    fwrite(STDERR, "Admin login failed.\n");
    exit(1);
}
$state = static fn (TestApiClient $c): array => $c->data('GET', '/randomizers/background')['background'] ?? [];
if (($state($admin)['custom'] ?? false) === true) {
    fwrite(STDERR, "A custom randomizer background is active. Restore the default first (Settings) or use a development copy.\n");
    exit(1);
}

$folder = SettingsStore::storagePath('randomizer-backgrounds');
$bgFiles = static fn (): array => is_dir($folder) ? array_values(array_filter(scandir($folder) ?: [], static fn ($f) => str_starts_with($f, 'bg-'))) : [];
$dataSnapshot = static fn (): string => md5(json_encode([
    $pdo->query('SELECT COUNT(*), COALESCE(SUM(CRC32(CONCAT_WS("|", id, attendee_id, randomizer_type, voided_at, reset_at))), 0) FROM randomizer_draws')->fetch(PDO::FETCH_NUM),
    $pdo->query('SELECT COUNT(*), COALESCE(SUM(CRC32(CONCAT_WS("|", attendee_id, token))), 0) FROM attendee_qr_codes')->fetch(PDO::FETCH_NUM),
    $pdo->query('SELECT COUNT(*), COALESCE(SUM(CRC32(CONCAT_WS("|", id, attendee_code, full_name, status))), 0) FROM attendees')->fetch(PDO::FETCH_NUM),
    $pdo->query('SELECT COUNT(*) FROM session_registrations')->fetchColumn(),
    $pdo->query('SELECT COUNT(*) FROM scan_logs')->fetchColumn(),
]));
$dataBefore = $dataSnapshot();
$testUserIds = [];
$unrelated = null;

try {
    section('Setup');
    $secret = [];
    foreach (['operator' => User::ROLE_EVENT_OPERATOR, 'staff' => User::ROLE_REGISTRATION_STAFF] as $key => $role) {
        $secret[$key] = bin2hex(random_bytes(12));
        $testUserIds[] = User::create("RB {$key} {$stamp}", "rb-{$key}-{$stamp}@example.invalid", password_hash($secret[$key], PASSWORD_DEFAULT), $role);
    }
    $operator = new TestApiClient($baseUrl);
    $staff = new TestApiClient($baseUrl);
    $anonymous = new TestApiClient($baseUrl);
    check('Event operator and registration staff log in', $operator->login("rb-operator-{$stamp}@example.invalid", $secret['operator']) === 200
        && $staff->login("rb-staff-{$stamp}@example.invalid", $secret['staff']) === 200);
    $minorBefore = $operator->request('GET', '/randomizers/minor');

    section('Default background');
    $s = $state($admin);
    check('Default is active: custom=false, no image path', ($s['custom'] ?? true) === false && array_key_exists('imagePath', $s) && $s['imagePath'] === null, $s);
    check('No custom image -> GET image is 404', fetchImage($admin, $baseUrl, '/randomizers/background/image')[0] === 404);

    section('Permissions (direct API requests)');
    $png = makeImage('png');
    check('Anonymous upload -> 401', uploadAs($anonymous, $baseUrl, $png, 'bg.png')[0] === 401);
    check('Anonymous GET background -> 401', $anonymous->request('GET', '/randomizers/background')['status'] === 401);
    check('Event operator upload -> 403', uploadAs($operator, $baseUrl, $png, 'bg.png')[0] === 403);
    check('Event operator restore -> 403', $operator->request('DELETE', '/settings/randomizer-background')['status'] === 403);
    check('Registration staff upload -> 403 and cannot read the setting (403)', uploadAs($staff, $baseUrl, $png, 'bg.png')[0] === 403
        && $staff->request('GET', '/randomizers/background')['status'] === 403);
    [$code, $body] = uploadAs($admin, $baseUrl, $png, 'bg.png', 'image/png', withCsrf: false);
    check('Admin upload WITHOUT the CSRF token is refused (403 CSRF_TOKEN_MISMATCH)', $code === 403 && ($body['error']['code'] ?? '') === 'CSRF_TOKEN_MISMATCH', [$code, $body['error']['code'] ?? null]);
    check('Nothing was stored by the refused requests', $bgFiles() === [] && ($state($admin)['custom'] ?? true) === false);

    section('Upload a valid image');
    [$code, $body, $raw] = uploadAs($admin, $baseUrl, $png, '../../../evil name.png', 'image/png');
    $bg = $body['data']['background'] ?? [];
    check('Admin uploads a 1920x1080 PNG (200, custom, size recorded)', $code === 200 && ($bg['custom'] ?? false) === true
        && ($bg['width'] ?? 0) === 1920 && ($bg['height'] ?? 0) === 1080, $raw);
    check('Stored under a generated name in the storage folder (path traversal ignored)', count($bgFiles()) === 1
        && preg_match('/^bg-[a-f0-9]{24}\.png$/', $bgFiles()[0] ?? '') === 1 && !file_exists(dirname(SettingsStore::storagePath()) . '/evil name.png'), $bgFiles());
    check('The response never contains a server path', !str_contains($raw, SettingsStore::storagePath()) && !str_contains($raw, '/storage'));
    [$imgCode, $imgType, $imgBytes, $imgHeaders] = fetchImage($operator, $baseUrl, (string) ($bg['imagePath'] ?? ''));
    check('Event operator (Randomizer screen) gets the exact image, image/png, nosniff', $imgCode === 200 && $imgType === 'image/png'
        && $imgBytes === $png && ($imgHeaders['x-content-type-options'] ?? '') === 'nosniff', [$imgCode, $imgType, strlen($imgBytes)]);
    check('Registration staff cannot fetch the image (403)', fetchImage($staff, $baseUrl, (string) ($bg['imagePath'] ?? ''))[0] === 403);
    $version1 = $bg['version'] ?? null;

    section('Persistence');
    $again = new TestApiClient($baseUrl);
    check('New login session sees the same custom background', $again->login($adminEmail, $adminPassword) === 200 && ($state($again)['version'] ?? null) === $version1);
    $settings = json_decode((string) file_get_contents(SettingsStore::storagePath('settings.json')), true);
    check('Saved in settings.json as a file name (no blob URL, no path, no image data)', is_array($settings['randomizer_background'] ?? null)
        && preg_match('/^bg-[a-f0-9]{24}\.png$/', $settings['randomizer_background']['file'] ?? '') === 1
        && !str_contains(json_encode($settings), 'blob:') && strlen(json_encode($settings)) < 2000, $settings);

    section('Invalid uploads are rejected and keep the current background');
    $truncatedPng = substr($png, 0, (int) (strlen($png) * 0.6));
    $jpg = makeImage('jpg');
    $corruptJpg = substr($jpg, 0, 600) . random_bytes(strlen($jpg) - 1200) . substr($jpg, -600);
    $bad = [
        'Text file named .png' => ["hello\n", 'notes.png', 'image/png'],
        'PHP script named .png' => ['<?php echo "x"; ?>', 'shell.png', 'image/png'],
        'PHP script named .php' => ['<?php echo "x"; ?>', 'shell.php', 'application/x-php'],
        'PNG content with .php extension' => [$png, 'image.php', 'image/png'],
        'GIF image' => [makeImage('gif', 200, 100), 'anim.gif', 'image/gif'],
        'PNG content named .jpg' => [$png, 'photo.jpg', 'image/jpeg'],
        'SVG image' => ['<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'x.svg', 'image/svg+xml'],
        'Truncated PNG' => [$truncatedPng, 'cut.png', 'image/png'],
        'Truncated JPEG' => [substr($jpg, 0, (int) (strlen($jpg) * 0.5)), 'cut.jpg', 'image/jpeg'],
        'Corrupted JPEG data' => [$corruptJpg, 'broken.jpg', 'image/jpeg'],
        'Empty file' => ['', 'empty.png', 'image/png'],
        'Over 10 MB' => [str_repeat("\x00", 10 * 1024 * 1024 + 10), 'huge.png', 'image/png'],
    ];
    foreach ($bad as $label => [$bytes, $name, $mime]) {
        [$code, $body, $raw] = uploadAs($admin, $baseUrl, $bytes, $name, $mime);
        $message = (string) ($body['error']['message'] ?? '');
        check("{$label} -> 422 with a clear message", $code === 422 && $message !== '' && !str_contains($raw, SettingsStore::storagePath()), [$code, $message]);
    }
    check('After all failed uploads the same background is still active and served', ($state($admin)['version'] ?? null) === $version1
        && fetchImage($admin, $baseUrl, (string) ($bg['imagePath'] ?? ''))[2] === $png && count($bgFiles()) === 1);

    section('Replace (JPEG, WebP)');
    // An unrelated file in the folder must survive replace and restore.
    $unrelated = $folder . '/keep-me.txt';
    file_put_contents($unrelated, 'unrelated');
    @chmod($unrelated, 0644);
    [$code, $body] = uploadAs($admin, $baseUrl, $jpg, 'Holcim Stage.JPEG', 'image/jpeg');
    $version2 = $body['data']['background']['version'] ?? null;
    check('JPEG replaces the PNG (new version, original name kept for display)', $code === 200 && $version2 !== null && $version2 !== $version1
        && ($body['data']['background']['originalName'] ?? '') === 'Holcim Stage.JPEG', $body);
    check('Only the new image file remains (old one removed); unrelated file kept', count($bgFiles()) === 1 && str_ends_with($bgFiles()[0] ?? '', '.jpg') && is_file($unrelated), $bgFiles());
    check('Old image URL no longer returns the old image', fetchImage($admin, $baseUrl, '/randomizers/background/image?v=' . $version1)[2] === $jpg);
    $webp = makeImage('webp');
    [$code, $body] = uploadAs($admin, $baseUrl, $webp, 'bg.webp', 'image/webp');
    $wbg = $body['data']['background'] ?? [];
    [$imgCode, $imgType] = fetchImage($operator, $baseUrl, (string) ($wbg['imagePath'] ?? ''));
    check('WebP accepted and served as image/webp', $code === 200 && $imgCode === 200 && $imgType === 'image/webp', [$code, $imgType]);

    section('Restore default');
    // Another (unrelated) setting must be kept.
    SettingsStore::set('test_unrelated_' . $stamp, 'keep');
    @chmod(SettingsStore::storagePath('settings.json'), 0644);
    $r = $admin->request('DELETE', '/settings/randomizer-background');
    check('Restore default (200, custom=false)', $r['status'] === 200 && ($r['body']['data']['background']['custom'] ?? true) === false, $r['raw']);
    check('Image endpoint 404 again; uploaded image removed; unrelated file kept', fetchImage($operator, $baseUrl, (string) ($wbg['imagePath'] ?? ''))[0] === 404
        && $bgFiles() === [] && is_file($unrelated));
    check('Other settings untouched', SettingsStore::get('test_unrelated_' . $stamp) === 'keep');
    check('Restore again is harmless (200)', $admin->request('DELETE', '/settings/randomizer-background')['status'] === 200);

    section('Randomizer and event data unchanged');
    $minorAfter = $operator->request('GET', '/randomizers/minor');
    check('Minor Randomizer summary answers as before', $minorAfter['status'] === $minorBefore['status']
        && ($minorAfter['body']['data']['eligibleCount'] ?? null) === ($minorBefore['body']['data']['eligibleCount'] ?? null), [$minorBefore['status'], $minorAfter['status']]);
    check('Draws, winners, QR tokens, attendees, registrations and scan logs unchanged', $dataSnapshot() === $dataBefore);
} finally {
    section('Cleanup');
    if (($state($admin)['custom'] ?? false) === true) {
        $admin->request('DELETE', '/settings/randomizer-background');
    }
    if ($unrelated !== null) {
        @unlink($unrelated);
    }
    try {
        SettingsStore::set('test_unrelated_' . $stamp, null);
    } catch (Throwable) {
    }
    foreach (array_filter($testUserIds) as $userId) {
        User::setStatus((int) $userId, User::STATUS_INACTIVE);
    }
    echo "  Default background restored; test accounts disabled.\n";
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
