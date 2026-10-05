<?php

declare(strict_types=1);

/**
 * Local -> Online QR migration verification. READ-ONLY: it never writes to
 * the database (it reads inside a READ ONLY transaction that is rolled back).
 *
 *   php backend/cli/verify-qr-migration.php                 summary + SHA-256 of the QR dataset
 *   php backend/cli/verify-qr-migration.php --save          ...and save the manifest (+ .sha256)
 *   php backend/cli/verify-qr-migration.php --save --out=DIR
 *   php backend/cli/verify-qr-migration.php --compare=FILE  compare this database with a saved manifest
 *   php backend/cli/verify-qr-migration.php --event=ID      limit to one event (default: all events)
 *
 * A printed QR contains only attendee_qr_codes.token - no localhost, APP_URL,
 * domain or http(s) URL. After a move it keeps working ONLY if the same
 * token is still attached to the same attendee (event_id + attendee_code).
 * The manifest contains names and QR tokens: keep it private. The default
 * folder database/qr-migration/ is blocked from the web and ignored by git.
 *
 * Exit code: 0 = PASS, 1 = FAIL, 2 = usage / read error.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Services\QrMigrationVerifier as V;

$options = getopt('', ['save', 'out:', 'compare:', 'event:', 'dump:', 'help']);
if (isset($options['help'])) {
    echo "Usage: php backend/cli/verify-qr-migration.php [--save [--out=DIR]] [--compare=FILE] [--event=ID]\n";
    exit(0);
}
if (isset($options['dump'])) {
    fwrite(STDERR, "--dump is not supported: parsing raw SQL dumps reliably is not practical.\n"
        . "Restore the dump into a database (e.g. a scratch database) and run --compare against it.\n");
    exit(2);
}
$eventId = isset($options['event']) && ctype_digit((string) $options['event']) ? (int) $options['event'] : null;
$database = (string) Config::get('database.database', '');

$manifest = null;
if (isset($options['compare'])) {
    $path = (string) $options['compare'];
    $raw = is_file($path) ? file_get_contents($path) : false;
    $manifest = $raw !== false ? json_decode($raw, true) : null;
    if (!is_array($manifest)) {
        fwrite(STDERR, "Cannot read manifest: {$path}\n");
        exit(2);
    }
    $eventId = $manifest['event_scope'] ?? null; // compare the same scope that was saved
}

try {
    $snap = V::snapshot(Database::connection(), $eventId === null ? null : (int) $eventId);
} catch (\Throwable $e) {
    fwrite(STDERR, 'Cannot read the database (' . get_class($e) . '): ' . $e->getMessage() . "\n");
    exit(2);
}

$issues = $snap['issues'];
$issueCount = V::issueCount($issues);
$w = static fn (string $label, mixed $value) => printf("%-34s %s\n", $label, $value);

echo "QR Migration Verification\n-------------------------\n";
$w('Database:', $database . ($eventId !== null ? " (event {$eventId} only)" : ' (all events)'));
echo "\n";

if ($manifest === null) {
    $w('Attendees:', $snap['attendee_count']);
    $w('Attendees with QR:', $snap['attendees_with_qr']);
    $w('QR records:', $snap['qr_count']);
    $w('Missing QR (active attendees):', count($issues['missing_qr']));
    $w('Duplicate tokens:', count($issues['duplicate_tokens']));
    $w('Attendees with more than one QR:', count($issues['duplicate_attendee_qr']));
    $w('Orphan QR records:', count($issues['orphan_qr']));
    $w('Empty tokens:', count($issues['empty_token']));
    $w('Invalid token format:', count($issues['invalid_token_format']));
    $w('Missing attendee codes:', count($issues['missing_attendee_code']));
    $w('Attendee event missing:', count($issues['event_missing']));
    $w('Scans linked to another QR:', $issues['scan_qr_mismatch']);
    if ($snap['warnings']['non_standard_token_length'] !== []) {
        $w('Note - tokens not 32 chars:', count($snap['warnings']['non_standard_token_length']) . ' (still scannable)');
    }
    if ($snap['warnings']['archived_without_qr'] > 0) {
        $w('Note - archived without QR:', $snap['warnings']['archived_without_qr'] . ' (not an error)');
    }
    echo "\nQR Dataset SHA-256:\n{$snap['fingerprint']}\nQR Identity SHA-256 (event_id|attendee_code|token):\n{$snap['identity_fingerprint']}\n";
    foreach (['missing_qr' => 'Missing QR', 'duplicate_tokens' => 'Duplicate tokens', 'duplicate_attendee_qr' => 'More than one QR',
        'empty_token' => 'Empty token', 'invalid_token_format' => 'Invalid token format', 'event_missing' => 'Event missing'] as $k => $label) {
        if ($issues[$k] !== []) {
            echo "  {$label}: " . V::listed($issues[$k]) . "\n";
        }
    }
    if ($issues['orphan_qr'] !== []) {
        echo '  Orphan QR record ids: ' . V::listed($issues['orphan_qr']) . "\n";
    }

    if (isset($options['save'])) {
        $dir = rtrim((string) ($options['out'] ?? PROJECT_ROOT . '/database/qr-migration'), '/');
        if (!is_dir($dir) && !mkdir($dir, 0700, true)) {
            fwrite(STDERR, "Cannot create folder {$dir}\n");
            exit(2);
        }
        if (!is_file($dir . '/.htaccess')) {
            file_put_contents($dir . '/.htaccess', "Require all denied\n");
        }
        $name = 'qr-manifest-' . date('Ymd-His');
        $json = json_encode(V::manifest($snap, $database), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        file_put_contents("{$dir}/{$name}.json", $json);
        file_put_contents("{$dir}/{$name}.sha256", hash('sha256', $json) . "  {$name}.json\n");
        @chmod("{$dir}/{$name}.json", 0600);
        @chmod("{$dir}/{$name}.sha256", 0600);
        echo "\nSaved manifest (contains names and QR tokens - keep private):\n  {$dir}/{$name}.json\n  {$dir}/{$name}.sha256\n";
    }

    echo "\nRESULT: " . ($issueCount === 0 ? 'PASS' : 'FAIL') . "\n";
    exit($issueCount === 0 ? 0 : 1);
}

// ---- Compare mode
$path = (string) $options['compare'];
$fileOk = null;
$sidecar = preg_replace('/\.json$/', '', $path) . '.sha256';
if (is_file($sidecar)) {
    $expectedHash = strtok((string) file_get_contents($sidecar), " \n");
    $fileOk = hash_equals((string) $expectedHash, hash_file('sha256', $path));
}
$intact = V::manifestIsIntact($manifest);
$c = V::compare($manifest, $snap);
$pass = $c['pass'] && $intact && $fileOk !== false;

$w('Manifest:', basename($path) . ' (from ' . ($manifest['database'] ?? '?') . ', ' . ($manifest['generated_at'] ?? '?') . ')');
$w('Manifest file checksum (.sha256):', $fileOk === null ? 'not found (skipped)' : ($fileOk ? 'OK' : 'MISMATCH - file was changed'));
$w('Manifest content fingerprint:', $intact ? 'OK' : 'MISMATCH - records were changed');
echo "\n";
$w('Expected records:', $c['expected_count']);
$w('Actual records:', $c['actual_count']);
echo "\n";
$w('Matching QR tokens:', count($c['matching']));
$w('Changed QR tokens:', count($c['changed_tokens']));
$w('Missing attendees:', count($c['missing']));
$w('Unexpected attendees:', count($c['unexpected']));
$w('Changed attendee codes:', count($c['changed_codes']));
$w('Changed attendee/token relationships:', count($c['relationship_changes']));
$w('Event mismatches:', count($c['event_mismatch']));
$w('Duplicate tokens:', count($issues['duplicate_tokens']));
$w('Attendees with more than one QR:', count($issues['duplicate_attendee_qr']));
$w('Orphan QR records:', count($issues['orphan_qr']));
$w('Missing QR (active attendees):', count($issues['missing_qr']));
$w('Empty / invalid tokens:', count($issues['empty_token']) + count($issues['invalid_token_format']));
echo "\n";
$w('QR identity mappings preserved:', count($c['matching']) . '/' . $c['expected_count']);
$w('Attendee IDs changed:', count($c['id_changes']) . ' (reported only; not a failure)');
foreach (['full_name' => 'Name', 'company' => 'Company', 'department' => 'Cluster'] as $field => $label) {
    $w("{$label} changed:", count($c['detail_changes'][$field] ?? []) . ' (reported only; does not affect the QR)');
}
$w('Expected identity SHA-256:', $manifest['identity_fingerprint'] ?? '?');
$w('Actual identity SHA-256:', $snap['identity_fingerprint']);

$lists = [
    'changed_tokens' => 'Changed QR tokens', 'missing' => 'Missing attendees', 'unexpected' => 'Unexpected attendees',
    'changed_codes' => 'Changed attendee codes', 'relationship_changes' => 'Token moved to another attendee',
    'event_mismatch' => 'Event mismatch', 'id_changes' => 'Attendee ID changed',
];
$printed = false;
foreach ($lists as $k => $label) {
    if ($c[$k] !== []) {
        echo ($printed ? '' : "\nAffected attendee codes:\n") . "  {$label}: " . V::listed($c[$k]) . "\n";
        $printed = true;
    }
}
foreach (['full_name' => 'Name changed', 'company' => 'Company changed', 'department' => 'Cluster changed'] as $field => $label) {
    if (($c['detail_changes'][$field] ?? []) !== []) {
        echo ($printed ? '' : "\nAffected attendee codes:\n") . "  {$label}: " . V::listed($c['detail_changes'][$field]) . "\n";
        $printed = true;
    }
}
foreach (['duplicate_tokens' => 'Duplicate tokens', 'duplicate_attendee_qr' => 'More than one QR', 'missing_qr' => 'Missing QR',
    'empty_token' => 'Empty token', 'invalid_token_format' => 'Invalid token format', 'event_missing' => 'Event missing'] as $k => $label) {
    if ($issues[$k] !== []) {
        echo ($printed ? '' : "\nAffected attendee codes:\n") . "  {$label}: " . V::listed($issues[$k]) . "\n";
        $printed = true;
    }
}

echo "\nRESULT: " . ($pass ? 'PASS' : 'FAIL') . "\n";
if ($pass) {
    echo "Every printed QR token resolves to the same attendee in this database.\n";
}
exit($pass ? 0 : 1);
