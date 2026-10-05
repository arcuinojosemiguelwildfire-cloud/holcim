<?php

declare(strict_types=1);

/**
 * Tests for backend/cli/verify-qr-migration.php on a THROWAWAY database.
 *
 *   php backend/tests/qr-migration-test.php --confirm
 *
 * Creates the scratch database QRMIG_DB (default holcim_qrmig_test; its name
 * must contain "test"), migrates it, seeds 264 synthetic attendees with QR
 * codes, saves a manifest, then for each scenario restores a real mysqldump of
 * that database, applies one change and runs --compare. The scratch database
 * is dropped at the end. Your real database is never touched.
 * Needs the DB user to be allowed to CREATE/DROP that database, and the mysql /
 * mysqldump clients (PATH, XAMPP's bin folder, or MYSQL_BIN / MYSQLDUMP_BIN).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/bootstrap.php';

use App\Core\Config;
use App\Utils\Token;

$scratch = getenv('QRMIG_DB') ?: 'holcim_qrmig_test';
if (!in_array('--confirm', $argv, true) || !str_contains($scratch, 'test') || !preg_match('/^[A-Za-z0-9_]+$/', $scratch)) {
    fwrite(STDERR, "Creates and drops the scratch database '{$scratch}' (name must contain 'test'). Run with --confirm.\n");
    exit(2);
}
$bin = static function (string $name, string $env): string {
    $explicit = getenv($env);
    if ($explicit) {
        return $explicit;
    }
    $xampp = "/Applications/XAMPP/xamppfiles/bin/{$name}";

    return trim((string) shell_exec('command -v ' . $name)) ?: (is_file($xampp) ? $xampp : $name);
};
$mysql = $bin('mysql', 'MYSQL_BIN');
$mysqldump = $bin('mysqldump', 'MYSQLDUMP_BIN');
$db = Config::get('database');
$creds = sprintf('-h %s -P %d -u %s', escapeshellarg((string) $db['host']), (int) $db['port'], escapeshellarg((string) $db['username']));
$envPrefix = 'MYSQL_PWD=' . escapeshellarg((string) $db['password']) . ' ';
$root = new PDO("mysql:host={$db['host']};port={$db['port']};charset=utf8mb4", (string) $db['username'], (string) $db['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$tmp = sys_get_temp_dir() . '/holcim-qrmig-' . bin2hex(random_bytes(4));
mkdir($tmp, 0700);
$dump = "{$tmp}/baseline.sql";

$passed = 0;
$failed = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    $ok ? $passed++ : $failed++;
    echo ($ok ? '  PASS ' : '  FAIL ') . $label . ($ok || $detail === '' ? '' : "\n       " . str_replace("\n", "\n       ", trim($detail))) . "\n";
}
$connect = static fn (): PDO => new PDO("mysql:host={$db['host']};port={$db['port']};dbname={$scratch};charset=utf8mb4", (string) $db['username'], (string) $db['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$cli = static function (string $args) use ($scratch): array {
    $out = [];
    exec('DB_DATABASE=' . escapeshellarg($scratch) . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(BACKEND_ROOT . '/cli/verify-qr-migration.php') . " {$args} 2>&1", $out, $code);

    return [$code, implode("\n", $out)];
};
$restore = static function () use ($root, $scratch, $mysql, $creds, $envPrefix, $dump): void {
    $root->exec("DROP DATABASE IF EXISTS `{$scratch}`");
    $root->exec("CREATE DATABASE `{$scratch}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    exec($envPrefix . escapeshellarg($mysql) . " {$creds} " . escapeshellarg($scratch) . ' < ' . escapeshellarg($dump) . ' 2>&1', $o, $code);
    if ($code !== 0) {
        throw new RuntimeException('restore failed: ' . implode("\n", $o));
    }
};
$checksums = static function (PDO $pdo): string {
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $rows = $pdo->query('CHECKSUM TABLE `' . implode('`, `', $tables) . '` EXTENDED')->fetchAll(PDO::FETCH_ASSOC);

    return md5(json_encode($rows));
};
$value = static fn (string $output, string $label): ?string => preg_match('/^' . preg_quote($label, '/') . '\s+(\S+)/m', $output, $m) ? $m[1] : null;

try {
    echo "QR migration verification tests (scratch database {$scratch})\n";
    $root->exec("DROP DATABASE IF EXISTS `{$scratch}`");
    $root->exec("CREATE DATABASE `{$scratch}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    exec('DB_DATABASE=' . escapeshellarg($scratch) . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(BACKEND_ROOT . '/cli/migrate.php') . ' 2>&1', $o, $code);
    if ($code !== 0) {
        throw new RuntimeException('migrate failed: ' . implode("\n", $o));
    }

    // Seed: 264 active attendees with QR (+1 archived without QR, which is not an error).
    $pdo = $connect();
    $pdo->exec("INSERT INTO events (name, event_date, status) VALUES ('QR Migration Test Event', '2030-10-01', 'active')");
    $eventId = (int) $pdo->lastInsertId();
    $insA = $pdo->prepare('INSERT INTO attendees (event_id, attendee_code, full_name, company, department, status) VALUES (?, ?, ?, ?, ?, ?)');
    $insQ = $pdo->prepare('INSERT INTO attendee_qr_codes (attendee_id, token) VALUES (?, ?)');
    $tokens = [];
    for ($i = 1; $i <= 264; $i++) {
        $code = sprintf('ATT-%04d', $i);
        $insA->execute([$eventId, $code, "Synthetic Person {$i}", $i % 9 === 0 ? null : 'Company ' . chr(65 + $i % 26), $i % 7 === 0 ? null : 'Cluster ' . ($i % 5), 'active']);
        $token = Token::random(24);
        if ($code === 'ATT-0011') {
            $token = substr_replace($token, '-', 5, 1); // has a '-' to flip to '_'
        }
        if ($code === 'ATT-0012') {
            $token = 'a' . substr($token, 1); // lower-case first char to flip case
        }
        $tokens[$code] = $token;
        $insQ->execute([(int) $pdo->lastInsertId(), $token]);
    }
    $insA->execute([$eventId, 'ATT-0265', 'Archived Person', null, null, 'archived']);
    unset($pdo);

    exec($envPrefix . escapeshellarg($mysqldump) . " {$creds} --single-transaction " . escapeshellarg($scratch) . ' > ' . escapeshellarg($dump) . ' 2>&1', $o2, $code);
    if ($code !== 0 || filesize($dump) < 1000) {
        throw new RuntimeException('mysqldump failed: ' . implode("\n", $o2));
    }

    // ---- Standalone run + manifest
    $pdo = $connect();
    $before = $checksums($pdo);
    [$code, $out] = $cli('--save --out=' . escapeshellarg("{$tmp}/manifests"));
    $files = glob("{$tmp}/manifests/qr-manifest-*.json") ?: [];
    $manifestPath = $files[0] ?? '';
    check('1. 264 attendees + 264 QR records -> PASS', $code === 0 && str_contains($out, 'RESULT: PASS')
        && $value($out, 'Attendees with QR:') === '264' && $value($out, 'QR records:') === '264' && $value($out, 'Missing QR (active attendees):') === '0', $out);
    check('Archived attendee without QR is a note, not an error', str_contains($out, 'archived without QR:') && str_contains($out, '1 (not an error)'));
    $manifest = json_decode((string) file_get_contents($manifestPath), true);
    check('Manifest saved with .sha256 and private folder', is_file($manifestPath) && is_file(preg_replace('/\.json$/', '.sha256', $manifestPath))
        && is_file("{$tmp}/manifests/.htaccess") && (fileperms($manifestPath) & 0077) === 0);
    check('Manifest: 264 records, required fields, sorted by event_id + attendee_code',
        count($manifest['records']) === 264 && array_keys($manifest['records'][0]) === ['event_id', 'attendee_id', 'attendee_code', 'full_name', 'company', 'department', 'token']
        && array_column($manifest['records'], 'attendee_code') === array_map(static fn ($i) => sprintf('ATT-%04d', $i), range(1, 264)));
    check('Manifest stores the exact tokens (not hashed or normalised)', array_column($manifest['records'], 'token', 'attendee_code') === $tokens);
    $canonical = '';
    foreach ($manifest['records'] as $r) {
        $canonical .= implode('|', array_map(static fn ($v) => $v === null ? '\\N' : str_replace(['\\', '|', "\n", "\r"], ['\\\\', '\\|', '\\n', '\\r'], (string) $v),
            [$r['event_id'], $r['attendee_id'], $r['attendee_code'], $r['full_name'], $r['company'], $r['department'], $r['token']])) . "\n";
    }
    check('Fingerprint = SHA-256 of the documented canonical format', hash('sha256', $canonical) === $manifest['fingerprint'] && str_contains($out, $manifest['fingerprint']));
    [, $out2] = $cli('');
    check('Deterministic: same database -> same fingerprint', str_contains($out2, $manifest['fingerprint']) && str_contains($out2, $manifest['identity_fingerprint']));
    [$code, $out] = $cli('--compare=' . escapeshellarg($manifestPath));
    check('Compare against the same database -> PASS', $code === 0 && str_contains($out, 'RESULT: PASS') && preg_match('/QR identity mappings preserved:\s+264\/264/', $out) === 1, $out);
    check('Read-only: every table checksum unchanged after the runs', $checksums($pdo) === $before);
    unset($pdo);

    // Scenario runner: restore the dump, apply SQL, compare.
    $scenario = static function (string $sql) use ($restore, $connect, $cli, $manifestPath): array {
        $restore();
        if ($sql !== '') {
            $pdo = $connect();
            foreach (array_filter(array_map('trim', explode(";\n", $sql))) as $statement) {
                $pdo->exec($statement);
            }
        }

        return $cli('--compare=' . escapeshellarg($manifestPath));
    };
    $id = static fn (string $code): string => "(SELECT id FROM (SELECT id FROM attendees WHERE attendee_code = '{$code}') x)";
    $newTok = Token::random(24);

    [$code, $out] = $scenario('');
    check('16. Exact dump/restore -> PASS (identity SHA-256 equal)', $code === 0 && str_contains($out, 'RESULT: PASS')
        && $value($out, 'Expected identity SHA-256:') === $value($out, 'Actual identity SHA-256:'), $out);

    [$code, $out] = $scenario('DELETE FROM attendee_qr_codes WHERE attendee_id = ' . $id('ATT-0002'));
    check('2. One missing QR -> FAIL (ATT-0002 listed)', $code === 1 && str_contains($out, 'RESULT: FAIL') && $value($out, 'Missing attendees:') === '1'
        && $value($out, 'Missing QR (active attendees):') === '1' && str_contains($out, 'ATT-0002'), $out);

    [$code, $out] = $scenario("ALTER TABLE attendee_qr_codes DROP INDEX uq_attendee_qr_codes_token;\nUPDATE attendee_qr_codes SET token = '{$tokens['ATT-0004']}' WHERE attendee_id = " . $id('ATT-0003'));
    check('3. One duplicate token -> FAIL', $code === 1 && $value($out, 'Duplicate tokens:') === '2' && str_contains($out, 'ATT-0003') && str_contains($out, 'ATT-0004'), $out);

    [$code, $out] = $scenario("SET FOREIGN_KEY_CHECKS = 0;\nINSERT INTO attendee_qr_codes (attendee_id, token) VALUES (999999, '{$newTok}')");
    check('4. One orphan QR -> FAIL', $code === 1 && $value($out, 'Orphan QR records:') === '1', $out);

    [$code, $out] = $scenario("UPDATE attendee_qr_codes SET token = '{$newTok}' WHERE attendee_id = " . $id('ATT-0005'));
    check('5. One changed token -> FAIL (ATT-0005)', $code === 1 && $value($out, 'Changed QR tokens:') === '1' && str_contains($out, 'Changed QR tokens: ATT-0005'), $out);
    check('   FAIL output lists codes only (no tokens, no names)', !str_contains($out, $newTok) && !str_contains($out, $tokens['ATT-0005']) && !str_contains($out, 'Synthetic Person'));

    [$code, $out] = $scenario("UPDATE attendees SET attendee_code = 'ATT-9006' WHERE attendee_code = 'ATT-0006'");
    check('6. One changed attendee code -> FAIL', $code === 1 && $value($out, 'Changed attendee codes:') === '1' && str_contains($out, 'ATT-0006 -> ATT-9006'), $out);

    [$code, $out] = $scenario("UPDATE attendees SET company = 'Renamed Co' WHERE attendee_code = 'ATT-0007'");
    check('7. One changed company -> reported (QR identity still PASS)', $code === 0 && $value($out, 'Company changed:') === '1' && str_contains($out, 'Company changed: ATT-0007'), $out);

    [$code, $out] = $scenario("UPDATE attendees SET department = 'Moved Cluster' WHERE attendee_code = 'ATT-0008'");
    check('8. One changed cluster -> reported (QR identity still PASS)', $code === 0 && $value($out, 'Cluster changed:') === '1' && str_contains($out, 'Cluster changed: ATT-0008'), $out);

    [$code, $out] = $scenario('UPDATE attendees SET id = id + 100000');
    check('9. All auto-increment attendee IDs changed, same code + token -> PASS, 264 ID changes reported', $code === 0 && str_contains($out, 'RESULT: PASS')
        && $value($out, 'Attendee IDs changed:') === '264' && str_contains($out, '264/264'), $out);

    $flipped = 'A' . substr($tokens['ATT-0012'], 1);
    [$code, $out] = $scenario("UPDATE attendee_qr_codes SET token = '{$flipped}' WHERE attendee_id = " . $id('ATT-0012'));
    check('10. Token case change (a -> A) -> FAIL', $code === 1 && str_contains($out, 'Changed QR tokens: ATT-0012'), $out);

    $underscore = substr_replace($tokens['ATT-0011'], '_', 5, 1);
    [$code, $out] = $scenario("UPDATE attendee_qr_codes SET token = '{$underscore}' WHERE attendee_id = " . $id('ATT-0011'));
    check("11. '-' changed to '_' -> FAIL", $code === 1 && str_contains($out, 'Changed QR tokens: ATT-0011'), $out);

    [$code, $out] = $scenario("UPDATE attendee_qr_codes SET token = CONCAT(token, ' ') WHERE attendee_id = " . $id('ATT-0013'));
    check('12. Extra whitespace in token -> FAIL', $code === 1 && str_contains($out, 'ATT-0013') && str_contains($out, 'RESULT: FAIL'), $out);

    [$code, $out] = $scenario("INSERT INTO events (name, event_date, status) VALUES ('Other', '2030-11-01', 'draft');\nUPDATE attendees SET event_id = (SELECT MAX(id) FROM events) WHERE attendee_code = 'ATT-0014'");
    check('13. Event ID mismatch -> FAIL', $code === 1 && $value($out, 'Event mismatches:') === '1' && str_contains($out, 'ATT-0014 (event'), $out);

    [$code, $out] = $scenario("UPDATE attendee_qr_codes SET token = '' WHERE attendee_id = " . $id('ATT-0015'));
    check('14. Empty token -> FAIL', $code === 1 && str_contains($out, 'Empty token: ATT-0015'), $out);

    [$code, $out] = $scenario("ALTER TABLE attendee_qr_codes ADD INDEX idx_tmp_attendee (attendee_id);\nALTER TABLE attendee_qr_codes DROP INDEX uq_attendee_qr_codes_attendee;\nINSERT INTO attendee_qr_codes (attendee_id, token) VALUES (" . $id('ATT-0016') . ", '{$newTok}')");
    check('15. Two QR records for one attendee -> FAIL', $code === 1 && $value($out, 'Attendees with more than one QR:') === '1' && str_contains($out, 'More than one QR: ATT-0016'), $out);

    // Manifest tampering is detected.
    $tampered = "{$tmp}/tampered.json";
    $m = $manifest;
    $m['records'][20]['token'] = $newTok;
    file_put_contents($tampered, json_encode($m, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    [$code, $out] = $scenario('');
    [$code, $out] = $cli('--compare=' . escapeshellarg($tampered));
    check('Edited manifest is detected -> FAIL', $code === 1 && str_contains($out, 'MISMATCH - records were changed'), $out);
    file_put_contents($manifestPath, str_replace('"ATT-0001"', '"ATT-0001" ', (string) file_get_contents($manifestPath)));
    [$code, $out] = $cli('--compare=' . escapeshellarg($manifestPath));
    check('Changed manifest file (vs its .sha256) is detected -> FAIL', $code === 1 && str_contains($out, 'MISMATCH - file was changed'), $out);
    [$code] = $cli('--dump=' . escapeshellarg($dump));
    check('--dump is refused with an explanation (no fragile SQL parser)', $code === 2);
} catch (Throwable $e) {
    check('test run completed', false, get_class($e) . ': ' . $e->getMessage());
} finally {
    $root->exec("DROP DATABASE IF EXISTS `{$scratch}`");
    exec('rm -rf ' . escapeshellarg($tmp));
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
