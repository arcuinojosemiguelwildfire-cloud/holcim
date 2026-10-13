<?php

declare(strict_types=1);

/**
 * Randomizer (= Pre-Drawn Winner Reveal) test (HTTP, end to end;
 * migrations 035 + 036). Session pool (selection per event day, persistence,
 * de-duplication, archived / removed / other-day / other-event exclusions,
 * NO_POOL for the presentation), assignments (validation, uniqueness,
 * roles; winners need NOT be registered or in the pool - an attendee with no
 * scan / registration, one registered only outside the selected sessions and
 * one registered only on another day are assigned and revealed),
 * reveals (correct winner, unassigned letter, repeat, concurrent
 * duplicates), selection changes never blocking or changing assignments
 * (inPool is information only),
 * voids and re-reveal, the report, audit labels, that the legacy Minor /
 * Major APIs are gone, and that every attendee / QR / registration record
 * stays unchanged.
 *
 *   HOLCIM_TEST_EMAIL=admin@example.com HOLCIM_TEST_PASSWORD='...' \
 *     php backend/tests/predrawn-test.php http://localhost/Holcim/backend/api --write
 *
 * DEVELOPMENT DATABASE ONLY (same rules as registration-session-test.php): it
 * creates its own "Predrawn Test ..." event with fictional attendees and test
 * accounts (random passwords). While it runs the active event is set to
 * completed; afterwards it is restored, the test event archived and the test
 * accounts disabled. Refuses to run without --write or with APP_ENV=production.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/support/TestApiClient.php';

use App\Core\Database;
use App\Core\Env;
use App\Models\Attendee;
use App\Models\AttendeeQrCode;
use App\Models\RegistrationSession;
use App\Models\SessionRegistration;
use App\Models\User;
use App\Utils\SpreadsheetReader;
use App\Utils\Token;

$positional = array_values(array_filter(array_slice($argv, 1), static fn ($a) => !str_starts_with($a, '--')));
$baseUrl = rtrim($positional[0] ?? 'http://localhost/Holcim/backend/api', '/');
$adminEmail = getenv('HOLCIM_TEST_EMAIL') ?: '';
$adminPassword = getenv('HOLCIM_TEST_PASSWORD') ?: '';
if (!in_array('--write', $argv, true)) {
    fwrite(STDERR, "This test writes to the database. Re-run with --write on a DEVELOPMENT database.\n");
    exit(1);
}
if (Env::get('APP_ENV') === 'production') {
    fwrite(STDERR, "Refusing to run with APP_ENV=production.\n");
    exit(1);
}
if (!function_exists('curl_init') || $adminEmail === '' || $adminPassword === '') {
    fwrite(STDERR, "Needs the curl extension and HOLCIM_TEST_EMAIL / HOLCIM_TEST_PASSWORD (an admin account).\n");
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
function sorted(array $values): array
{
    sort($values);

    return $values;
}
function section(string $title): void
{
    echo "\n{$title}\n";
}

$pdo = Database::connection();
$stamp = date('His') . '-' . bin2hex(random_bytes(2));
$login = static function (string $email, string $password) use ($baseUrl): TestApiClient {
    $client = new TestApiClient($baseUrl);
    if ($client->login($email, $password) !== 200) {
        fwrite(STDERR, "Login failed for a test account.\n");
        exit(1);
    }

    return $client;
};
$admin = $login($adminEmail, $adminPassword);
$originalActive = $pdo->query("SELECT id FROM events WHERE status = 'active' LIMIT 1")->fetchColumn();
$originalActive = $originalActive !== false ? (int) $originalActive : null;
$eventId = null;
$testUserIds = [];

$xlsxRows = static function (TestApiClient $c, string $path): array {
    $r = $c->request('GET', $path);
    $file = tempnam(sys_get_temp_dir(), 'pdt-');
    file_put_contents($file, $r['raw']);
    try {
        return SpreadsheetReader::read($file, 'xlsx');
    } catch (Throwable) {
        return ['headers' => [], 'rows' => []];
    } finally {
        @unlink($file);
    }
};

try {
    section('Setup');
    if ($originalActive !== null) {
        $admin->request('PATCH', "/events/{$originalActive}/status", ['status' => 'completed']);
    }
    $created = $admin->request('POST', '/events', ['name' => "Predrawn Test {$stamp}", 'event_date' => '2026-10-20', 'status' => 'draft', 'description' => '']);
    $eventId = (int) ($created['body']['data']['event']['id'] ?? 0);
    check('Admin creates and activates the test event', $created['status'] === 201
        && $admin->request('PATCH', "/events/{$eventId}/status", ['status' => 'active'])['status'] === 200, $created['raw']);
    $dayId = (int) (($admin->data('GET', "/events/{$eventId}/days")['days'] ?? [])[0]['id'] ?? 0);

    $people = ['anna' => 'Anna Reyes', 'juan' => 'Juan Dela Cruz', 'makiling' => 'Makiling Santos', 'dina' => 'Dina Uy', 'eli' => 'Eli Cruz', 'old' => 'Archived Person',
        'fay' => 'Fay Unselected', 'gus' => 'Gus Removed', 'hal' => 'Hal Otherday', 'ivy' => 'Ivy Noscan'];
    $ids = [];
    foreach ($people as $key => $name) {
        $ids[$key] = Attendee::create($eventId, 'PDT-' . strtoupper($key) . "-{$stamp}", ['full_name' => $name, 'company' => 'Test Co', 'department' => 'QA'], null);
        AttendeeQrCode::create($ids[$key], Token::random(24));
    }
    Attendee::setStatus($ids['old'], Attendee::STATUS_ARCHIVED);
    $otherEventAttendee = (int) $pdo->query("SELECT id FROM attendees WHERE event_id <> {$eventId} LIMIT 1")->fetchColumn();

    // Registration sessions: Day 1 = S1 (default) + S2 + S3, Day 2 = D2 (default).
    $s1 = (int) (($admin->data('GET', "/event-days/{$dayId}/sessions")['sessions'] ?? [])[0]['id'] ?? 0);
    $mk = static fn (string $label, string $time): int => (int) ($admin->request('POST', "/event-days/{$dayId}/sessions",
        ['label' => $label, 'session_date' => '2026-10-20', 'start_time' => $time, 'counts_for_major' => false])['body']['data']['session']['id'] ?? 0);
    $s2 = $mk('PDT Afternoon', '13:00');
    $s3 = $mk('PDT Evening', '18:00');
    $added = $admin->request('POST', "/events/{$eventId}/days", ['event_date' => '2026-10-21', 'label' => '']);
    $day2 = (int) ($added['body']['data']['day']['id'] ?? 0);
    $d2 = (int) (($admin->data('GET', "/event-days/{$day2}/sessions")['sessions'] ?? [])[0]['id'] ?? 0);
    check('Sessions S1, S2, S3 (Day 1) and D2 (Day 2) exist', $s1 > 0 && $s2 > 0 && $s3 > 0 && $d2 > 0 && count(array_unique([$s1, $s2, $s3, $d2])) === 4, [$s1, $s2, $s3, $d2]);
    // Anna in S1 AND S2 (must appear once); Fay only in S3 (not selected); Gus removed
    // from S1; Hal only on Day 2; the archived person registered in S1.
    $reg = static fn (string $key, int $day, int $session): int => SessionRegistration::create($eventId, $day, $session, $ids[$key], 'manual', null);
    foreach ([['anna', $s1], ['anna', $s2], ['juan', $s1], ['makiling', $s2], ['dina', $s1], ['eli', $s2], ['old', $s1], ['fay', $s3]] as [$key, $session]) {
        $reg($key, $dayId, $session);
    }
    SessionRegistration::remove($reg('gus', $dayId, $s1), null, 'Predrawn test removal');
    $reg('hal', $day2, $d2);
    $poolNames = ['Anna Reyes', 'Juan Dela Cruz', 'Makiling Santos', 'Dina Uy', 'Eli Cruz'];

    foreach (['operator' => User::ROLE_EVENT_OPERATOR, 'staff' => User::ROLE_REGISTRATION_STAFF] as $key => $role) {
        $secret = bin2hex(random_bytes(12));
        $testUserIds[] = User::create("PD {$key} {$stamp}", "pd-{$key}-{$stamp}@example.invalid", password_hash($secret, PASSWORD_DEFAULT), $role);
        ${$key} = $login("pd-{$key}-{$stamp}@example.invalid", $secret);
    }
    $scannerSecret = bin2hex(random_bytes(8));
    $r = $admin->request('POST', '/settings/scanner-operators', ['name' => 'PD Gate', 'username' => "pd-gate-{$stamp}", 'password' => $scannerSecret,
        'password_confirmation' => $scannerSecret, 'status' => 'active']);
    $testUserIds[] = (int) ($r['body']['data']['operator']['id'] ?? 0);
    $scanner = $login("pd-gate-{$stamp}", $scannerSecret);
    $anonymous = new TestApiClient($baseUrl);

    // Snapshots: nothing outside predrawn_winners may change.
    $snapshot = static fn (): string => md5(json_encode([
        $pdo->query("SELECT id, attendee_code, full_name, company, department, status, updated_at FROM attendees WHERE event_id = {$eventId} ORDER BY id")->fetchAll(),
        $pdo->query("SELECT q.attendee_id, q.token FROM attendee_qr_codes q JOIN attendees a ON a.id = q.attendee_id WHERE a.event_id = {$eventId} ORDER BY q.attendee_id")->fetchAll(),
        $pdo->query("SELECT id, attendee_id, event_day_id, registration_session_id, status, source, registered_at, removed_at FROM session_registrations WHERE event_id = {$eventId} ORDER BY id")->fetchAll(),
        $pdo->query("SELECT COUNT(*) FROM session_registration_changes WHERE event_id = {$eventId}")->fetchColumn(),
        $pdo->query("SELECT COUNT(*) FROM registration_scans WHERE event_id = {$eventId}")->fetchColumn(),
        $pdo->query("SELECT COUNT(*) FROM scan_logs WHERE event_id = {$eventId}")->fetchColumn(),
        $pdo->query("SELECT COUNT(*) FROM minor_manual_entries WHERE event_id = {$eventId}")->fetchColumn(),
        $pdo->query("SELECT COUNT(*) FROM major_eligibility WHERE event_id = {$eventId}")->fetchColumn(),
    ]));
    $before = $snapshot();
    $drawsBefore = (int) $pdo->query("SELECT COUNT(*) FROM randomizer_draws WHERE event_id = {$eventId}")->fetchColumn();
    $poolRowsBefore = (int) $pdo->query('SELECT COUNT(*) FROM randomizer_pool_sessions')->fetchColumn();
    $winnersBefore = $xlsxRows($admin, '/reports/winners.xlsx?scope=day');

    section('Permissions');
    check('Anonymous: list 401, reveal 401', $anonymous->request('GET', '/predrawn')['status'] === 401
        && $anonymous->request('POST', '/predrawn/reveal', ['letter' => 'A'])['status'] === 401);
    foreach (['registration staff' => $staff, 'scanner operator' => $scanner] as $who => $client) {
        $codes = [
            $client->request('GET', '/predrawn')['status'],
            $client->request('POST', '/predrawn/reveal', ['letter' => 'A'])['status'],
            $client->request('POST', '/predrawn/assignments', ['letter' => 'A', 'attendee_id' => $ids['anna']])['status'],
            $client->request('GET', '/reports/predrawn-winners.xlsx')['status'],
            $client->request('PUT', '/predrawn/pool', ['session_ids' => [$s1]])['status'],
            $client->request('GET', '/predrawn/pool/candidates?search=Anna')['status'],
        ];
        check("{$who}: list / reveal / assign / report / pool / candidates all 403", $codes === [403, 403, 403, 403, 403, 403], $codes);
    }
    check('Event operator cannot change the pool sessions or search candidates (403)', $operator->request('PUT', '/predrawn/pool', ['session_ids' => [$s1]])['status'] === 403
        && $operator->request('GET', '/predrawn/pool/candidates?search=Anna')['status'] === 403);
    check('Event operator can view (200) but not assign (403)', $operator->request('GET', '/predrawn')['status'] === 200
        && $operator->request('POST', '/predrawn/assignments', ['letter' => 'A', 'attendee_id' => $ids['anna']])['status'] === 403);
    $old = $admin->swapCsrf(null);
    $r = $admin->request('POST', '/predrawn/assignments', ['letter' => 'A', 'attendee_id' => $ids['anna']]);
    $admin->swapCsrf($old);
    check('Admin without CSRF token -> 403 CSRF_TOKEN_MISMATCH', $r['status'] === 403 && ($r['body']['error']['code'] ?? '') === 'CSRF_TOKEN_MISMATCH', $r['raw']);
    $old = $admin->swapCsrf(null);
    $r = $admin->request('PUT', '/predrawn/pool', ['session_ids' => [$s1]]);
    $admin->swapCsrf($old);
    check('Pool change without CSRF token -> 403', $r['status'] === 403, $r['raw']);
    check('Nothing assigned or selected by refused requests', ($admin->data('GET', '/predrawn')['assignments'] ?? null) === []
        && (int) $pdo->query('SELECT COUNT(*) FROM randomizer_pool_sessions')->fetchColumn() === $poolRowsBefore);

    section('Randomizer pool: no sessions selected');
    $pool = $operator->data('GET', '/predrawn')['pool'] ?? [];
    check('Overview: not configured, size 0, the 3 sessions of Day 1 listed (Day 2 not)', ($pool['configured'] ?? null) === false && ($pool['size'] ?? null) === 0
        && sorted(array_column($pool['sessions'] ?? [], 'id')) === sorted([$s1, $s2, $s3]) && ($pool['selectedSessionIds'] ?? null) === [], $pool);
    $byId = array_column($pool['sessions'] ?? [], null, 'id');
    check('Session list shows date, time and active registrations of active attendees (S1: Anna, Juan, Dina = 3; archived and removed not counted)',
        ($byId[$s2]['sessionDate'] ?? '') === '2026-10-20' && ($byId[$s2]['startTime'] ?? '') === '13:00' && ($byId[$s1]['registered'] ?? null) === 3
        && ($byId[$s2]['registered'] ?? null) === 3 && ($byId[$s3]['registered'] ?? null) === 1, $byId);
    $codes = [
        $operator->request('GET', '/predrawn/roll-names'),
        $operator->request('POST', '/predrawn/reveal', ['letter' => 'A']),
    ];
    check('Presentation blocked: roll names / reveal -> 409 NO_POOL', array_map(static fn (array $r): string => $r['status'] . ($r['body']['error']['code'] ?? ''), $codes)
        === array_fill(0, 2, '409NO_POOL'), array_column($codes, 'raw'));
    $items = $admin->data('GET', '/predrawn/pool/candidates?search=Ivy')['items'] ?? [];
    check('Candidates work without sessions: Ivy (never scanned) found, inPool false', array_column($items, 'fullName') === ['Ivy Noscan'] && ($items[0]['inPool'] ?? null) === false, $items);
    $r = $admin->request('POST', '/predrawn/assignments', ['letter' => 'Z', 'attendee_id' => $ids['ivy'], 'prize_label' => 'Bike']);
    $z = $r['body']['data']['assignment'] ?? [];
    check('Z = Ivy (no scan, no registration) assigned before any session is selected (201, inPool false)', $r['status'] === 201
        && ($z['attendee']['fullName'] ?? '') === 'Ivy Noscan' && ($z['inPool'] ?? null) === false, $r['raw']);
    check('Assigning Ivy created no registration or scan', (int) $pdo->query("SELECT COUNT(*) FROM session_registrations WHERE attendee_id = {$ids['ivy']}")->fetchColumn() === 0
        && (int) $pdo->query("SELECT COUNT(*) FROM registration_scans WHERE attendee_id = {$ids['ivy']}")->fetchColumn() === 0);
    $r = $operator->request('POST', '/predrawn/reveal', ['letter' => 'Z']);
    check('Reveal Z with no sessions selected -> 409 NO_POOL (presentation not started), nothing recorded', $r['status'] === 409 && ($r['body']['error']['code'] ?? '') === 'NO_POOL'
        && (int) $pdo->query("SELECT COUNT(*) FROM predrawn_winners WHERE event_id = {$eventId} AND revealed_at IS NOT NULL")->fetchColumn() === 0, $r['raw']);

    section('Randomizer pool: selecting sessions');
    $put = static fn (array $body) => $admin->request('PUT', '/predrawn/pool', $body);
    foreach (['a Day 2 session' => [$s1, $d2], 'an unknown session' => [999999999], 'a non-number' => ['x']] as $label => $sessionIds) {
        $r = $put(['session_ids' => $sessionIds]);
        check("Rejected: {$label} (422 on session_ids)", $r['status'] === 422 && isset($r['body']['error']['details']['fields']['session_ids']), $r['raw']);
    }
    check('Rejected: session_ids missing (422)', $put([])['status'] === 422);
    check('Nothing saved by rejected selections', (int) $pdo->query("SELECT COUNT(*) FROM randomizer_pool_sessions WHERE event_id = {$eventId}")->fetchColumn() === 0);
    $r = $put(['session_ids' => [$s2, (string) $s1, $s2]]);
    $pool = $r['body']['data']['pool'] ?? [];
    check('Admin selects S1 + S2 (200): configured, ids de-duplicated and sorted; Z (Ivy) listed as not registered, saved anyway', $r['status'] === 200 && ($pool['configured'] ?? null) === true
        && ($pool['selectedSessionIds'] ?? null) === sorted([$s1, $s2]) && array_column($r['body']['data']['outOfPool'] ?? [], 'letter') === ['Z'], $r['raw']);
    check('Pool size 5: Anna counted once; archived, removed (Gus), unselected (Fay), other day (Hal) excluded', ($pool['size'] ?? null) === 5, $pool);
    $fresh = $login($adminEmail, $adminPassword);
    check('Selection persists for a new login session', ($fresh->data('GET', '/predrawn')['pool']['selectedSessionIds'] ?? null) === sorted([$s1, $s2]));
    $found = static fn (string $q): array => array_column($admin->data('GET', '/predrawn/pool/candidates?search=' . rawurlencode($q))['items'] ?? [], 'inPool', 'fullName');
    check('Candidates "Cruz" -> Juan Dela Cruz and Eli Cruz, both marked in pool', $found('Cruz') == ['Juan Dela Cruz' => true, 'Eli Cruz' => true], $found('Cruz'));
    check('Candidates "Anna" -> exactly one Anna (two sessions, one entry)', $found('Anna') === ['Anna Reyes' => true]);
    check('Candidates include active attendees outside the pool, marked inPool false: Fay (S3 only), Gus (removed), Hal (other day), Ivy (never scanned)',
        $found('Fay') === ['Fay Unselected' => false] && $found('Gus') === ['Gus Removed' => false] && $found('Hal') === ['Hal Otherday' => false]
        && $found('Ivy') === ['Ivy Noscan' => false]);
    $otherCode = (string) $pdo->query("SELECT attendee_code FROM attendees WHERE id = {$otherEventAttendee}")->fetchColumn();
    check('Candidates exclude archived attendees and attendees of other events', $found('Archived') === [] && $found('PDT-OLD') === []
        && ($otherCode === '' || !in_array($otherEventAttendee, array_column($admin->data('GET', '/predrawn/pool/candidates?search=' . rawurlencode($otherCode))['items'] ?? [], 'id'), true)));
    $names = $operator->data('GET', '/predrawn/roll-names')['names'] ?? [];
    check('Roll names come only from the pool, each name once', $names !== [] && array_diff($names, $poolNames) === [] && count($names) === count(array_unique($names)), $names);
    $audit = $pdo->query("SELECT metadata FROM audit_logs WHERE event_id = {$eventId} AND action = 'randomizer.pool_sessions_changed' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    $meta = json_decode((string) end($audit), true) ?: [];
    check('Audit randomizer.pool_sessions_changed with before / after', count($audit) === 1 && ($meta['before'] ?? null) === [] && ($meta['after'] ?? null) === sorted([$s1, $s2]), $audit);

    section('Assignment validation');
    $assign = static fn (array $body) => $admin->request('POST', '/predrawn/assignments', $body);
    $bad = [
        'empty letter' => [['letter' => '', 'attendee_id' => $ids['anna']], 'letter'],
        'two letters' => [['letter' => 'AB', 'attendee_id' => $ids['anna']], 'letter'],
        'digit' => [['letter' => '1', 'attendee_id' => $ids['anna']], 'letter'],
        'accented letter' => [['letter' => 'Ñ', 'attendee_id' => $ids['anna']], 'letter'],
        'no attendee' => [['letter' => 'A'], 'attendee_id'],
        'unknown attendee' => [['letter' => 'A', 'attendee_id' => 999999999], 'attendee_id'],
        'attendee of another event' => [['letter' => 'A', 'attendee_id' => $otherEventAttendee], 'attendee_id'],
        'archived attendee' => [['letter' => 'A', 'attendee_id' => $ids['old']], 'attendee_id'],
        'prize over 150 characters' => [['letter' => 'A', 'attendee_id' => $ids['anna'], 'prize_label' => str_repeat('x', 151)], 'prize_label'],
    ];
    foreach ($bad as $label => [$body, $field]) {
        $r = $assign($body);
        check("Rejected: {$label} (422 on {$field})", $r['status'] === 422 && isset($r['body']['error']['details']['fields'][$field]), $r['raw']);
    }

    section('Assignments A = Anna, B = Juan, C = Makiling');
    $r = $assign(['letter' => 'A', 'attendee_id' => $ids['anna'], 'prize_label' => '  Grand   Prize ']);
    $a = $r['body']['data']['assignment'] ?? [];
    check('A = Anna (201), prize trimmed, status assigned, category Pre-Drawn Winner', $r['status'] === 201 && ($a['letter'] ?? '') === 'A'
        && ($a['attendee']['fullName'] ?? '') === 'Anna Reyes' && ($a['prizeLabel'] ?? '') === 'Grand Prize' && ($a['status'] ?? '') === 'assigned'
        && ($a['category'] ?? '') === 'Pre-Drawn Winner', $r['raw']);
    $b = $assign(['letter' => 'b', 'attendee_id' => (string) $ids['juan']])['body']['data']['assignment'] ?? [];
    check('b (lower case) = Juan -> stored as B, no prize', ($b['letter'] ?? '') === 'B' && array_key_exists('prizeLabel', $b) && $b['prizeLabel'] === null, $b);
    $c = $assign(['letter' => 'C', 'attendee_id' => $ids['makiling'], 'prize_label' => 'TV'])['body']['data']['assignment'] ?? [];
    check('C = Makiling', ($c['attendee']['fullName'] ?? '') === 'Makiling Santos');
    $r = $assign(['letter' => 'A', 'attendee_id' => $ids['dina']]);
    check('Letter A again -> 409 LETTER_TAKEN', $r['status'] === 409 && ($r['body']['error']['code'] ?? '') === 'LETTER_TAKEN', $r['raw']);
    $r = $assign(['letter' => 'D', 'attendee_id' => $ids['anna']]);
    check('Anna on a second letter -> 409 ATTENDEE_ASSIGNED', $r['status'] === 409 && ($r['body']['error']['code'] ?? '') === 'ATTENDEE_ASSIGNED', $r['raw']);
    $e = $assign(['letter' => 'E', 'attendee_id' => $ids['eli']])['body']['data']['assignment'] ?? [];
    $r = $admin->request('PUT', '/predrawn/assignments/' . ($e['id'] ?? 0), ['letter' => 'D', 'attendee_id' => $ids['dina'], 'prize_label' => 'Phone']);
    check('Edit an unrevealed assignment: E/Eli -> D/Dina with prize', $r['status'] === 200 && ($r['body']['data']['assignment']['letter'] ?? '') === 'D'
        && ($r['body']['data']['assignment']['attendee']['fullName'] ?? '') === 'Dina Uy', $r['raw']);
    $r = $admin->request('PUT', '/predrawn/assignments/' . ($e['id'] ?? 0), ['letter' => 'B', 'attendee_id' => $ids['dina']]);
    check('Edit onto a taken letter -> 409', $r['status'] === 409, $r['raw']);
    check('Remove an unrevealed assignment (D) -> 200', $admin->request('DELETE', '/predrawn/assignments/' . ($e['id'] ?? 0))['status'] === 200);
    $list = $operator->data('GET', '/predrawn');
    check('Operator sees A, B, C, Z in letter order (review list); Z (Ivy) marked not in pool', array_column($list['assignments'] ?? [], 'letter') === ['A', 'B', 'C', 'Z']
        && ($list['category'] ?? '') === 'Pre-Drawn Winner' && array_column($list['assignments'] ?? [], 'inPool') === [true, true, true, false], $list);
    $r = $admin->request('PUT', '/predrawn/assignments/' . ($c['id'] ?? 0), ['letter' => 'C', 'attendee_id' => $ids['old']]);
    check('Edit C to the archived attendee -> 422', $r['status'] === 422 && isset($r['body']['error']['details']['fields']['attendee_id']), $r['raw']);
    $r = $admin->request('PUT', '/predrawn/assignments/' . ($c['id'] ?? 0), ['letter' => 'C', 'attendee_id' => $otherEventAttendee]);
    check('Edit C to an attendee of another event -> 422', $r['status'] === 422 && isset($r['body']['error']['details']['fields']['attendee_id']), $r['raw']);
    $x = $assign(['letter' => 'X', 'attendee_id' => $ids['hal']]);
    $y = $assign(['letter' => 'Y', 'attendee_id' => $ids['fay'], 'prize_label' => 'Watch']);
    check('X = Hal (registered on Day 2 only) and Y = Fay (registered only in unselected S3) assigned (201, inPool false)',
        $x['status'] === 201 && $y['status'] === 201 && ($x['body']['data']['assignment']['inPool'] ?? null) === false
        && ($y['body']['data']['assignment']['inPool'] ?? null) === false, [$x['raw'], $y['raw']]);
    check('Hal on a second letter -> 409 ATTENDEE_ASSIGNED; letter Y again -> 409 LETTER_TAKEN',
        ($assign(['letter' => 'W', 'attendee_id' => $ids['hal']])['body']['error']['code'] ?? '') === 'ATTENDEE_ASSIGNED'
        && ($assign(['letter' => 'Y', 'attendee_id' => $ids['gus']])['body']['error']['code'] ?? '') === 'LETTER_TAKEN');

    section('Reveal');
    $r = $operator->request('POST', '/predrawn/reveal', ['letter' => 'Q']);
    check('Unassigned letter Q -> 404 NOT_ASSIGNED, nothing recorded', $r['status'] === 404 && ($r['body']['error']['code'] ?? '') === 'NOT_ASSIGNED'
        && (int) $pdo->query("SELECT COUNT(*) FROM predrawn_winners WHERE event_id = {$eventId} AND revealed_at IS NOT NULL")->fetchColumn() === 0, $r['raw']);
    check('Invalid letter "1" -> 422', $operator->request('POST', '/predrawn/reveal', ['letter' => '1'])['status'] === 422);
    $r = $operator->request('POST', '/predrawn/reveal', ['letter' => 'a']);
    check('Operator presses a -> Anna revealed with her prize', $r['status'] === 200 && ($r['body']['data']['winner'] ?? null) === ['fullName' => 'Anna Reyes', 'prizeLabel' => 'Grand Prize']
        && ($r['body']['data']['reveal']['status'] ?? '') === 'revealed', $r['raw']);
    $r = $admin->request('POST', '/predrawn/reveal', ['letter' => 'A']);
    check('A again -> 409 ALREADY_REVEALED', $r['status'] === 409 && ($r['body']['error']['code'] ?? '') === 'ALREADY_REVEALED', $r['raw']);
    $aId = (int) ($a['id'] ?? 0);
    check('A revealed: edit (409) and remove (409) refused', $admin->request('PUT', "/predrawn/assignments/{$aId}", ['letter' => 'A', 'attendee_id' => $ids['anna']])['status'] === 409
        && $admin->request('DELETE', "/predrawn/assignments/{$aId}")['status'] === 409);

    // Concurrent reveals of B from five separate sessions: exactly one is recorded.
    $sessions = [$admin, $operator, $login($adminEmail, $adminPassword), $login($adminEmail, $adminPassword), $login($adminEmail, $adminPassword)];
    $results = TestApiClient::parallel(array_map(static fn (TestApiClient $s) => [$s, 'POST', '/predrawn/reveal', ['letter' => 'B']], $sessions));
    $codes = array_map(static fn (array $x): int => $x['status'], $results);
    sort($codes);
    check('Five simultaneous reveals of B -> one 200, four 409', $codes === [200, 409, 409, 409, 409], $codes);
    check('Exactly one revealed row for B', (int) $pdo->query("SELECT COUNT(*) FROM predrawn_winners WHERE event_day_id = {$dayId} AND letter = 'B' AND revealed_at IS NOT NULL")->fetchColumn() === 1);
    // Key repeat: the same letter sent again and again by one session.
    $repeat = array_map(static fn (): int => $admin->request('POST', '/predrawn/reveal', ['letter' => 'B'])['status'], range(1, 5));
    check('Repeated B presses -> all 409, still one reveal', $repeat === [409, 409, 409, 409, 409], $repeat);

    section('Void and re-reveal');
    $bId = (int) ($b['id'] ?? 0);
    check('Event operator cannot void (403)', $operator->request('POST', "/predrawn/assignments/{$bId}/void", ['reason' => 'x'])['status'] === 403);
    check('Unrevealed C cannot be voided (409)', $admin->request('POST', '/predrawn/assignments/' . ($c['id'] ?? 0) . '/void', [])['status'] === 409);
    $r = $admin->request('POST', "/predrawn/assignments/{$bId}/void", ['reason' => 'Wrong letter pressed']);
    check('Admin voids B (200), status voided, reason kept', $r['status'] === 200 && ($r['body']['data']['assignment']['status'] ?? '') === 'voided'
        && ($r['body']['data']['assignment']['voidReason'] ?? '') === 'Wrong letter pressed', $r['raw']);
    check('Void again -> 409', $admin->request('POST', "/predrawn/assignments/{$bId}/void", [])['status'] === 409);
    check('After the void, B has no live assignment -> reveal B is 404', $operator->request('POST', '/predrawn/reveal', ['letter' => 'B'])['status'] === 404);
    $r = $assign(['letter' => 'B', 'attendee_id' => $ids['juan']]);
    check('Admin re-assigns B = Juan explicitly (201)', $r['status'] === 201, $r['raw']);
    $r = $operator->request('POST', '/predrawn/reveal', ['letter' => 'B']);
    check('B revealed again -> Juan', $r['status'] === 200 && ($r['body']['data']['winner']['fullName'] ?? '') === 'Juan Dela Cruz', $r['raw']);
    $rowsB = $pdo->query("SELECT voided_at IS NULL AS live, revealed_at IS NOT NULL AS revealed FROM predrawn_winners WHERE event_day_id = {$dayId} AND letter = 'B' ORDER BY id")->fetchAll(PDO::FETCH_NUM);
    check('History for B: the voided reveal is kept + one live reveal (never two live)', array_map(static fn ($x) => array_map('intval', $x), $rowsB) === [[0, 1], [1, 1]], $rowsB);
    $overview = $admin->data('GET', '/predrawn');
    check('Overview: A, B, C, X, Y, Z live; voided B listed separately', array_column($overview['assignments'] ?? [], 'letter') === ['A', 'B', 'C', 'X', 'Y', 'Z']
        && array_column($overview['voided'] ?? [], 'letter') === ['B'], $overview);

    section('Selection change never blocks or changes assignments');
    $assignedBefore = $pdo->query("SELECT id, letter, attendee_id, prize_label, revealed_at, voided_at FROM predrawn_winners WHERE event_id = {$eventId} ORDER BY id")->fetchAll();
    $r = $put(['session_ids' => [$s1]]);
    $notRegistered = array_column($r['body']['data']['outOfPool'] ?? [], 'letter');
    sort($notRegistered);
    check('S1 only -> 200 (no confirmation needed); C, X, Y, Z reported as not registered (information only)', $r['status'] === 200
        && $notRegistered === ['C', 'X', 'Y', 'Z'] && ($r['body']['data']['pool']['size'] ?? null) === 3, $r['raw']);
    check('Selection change left every pre-drawn row untouched (ids, letters, attendees, prizes, reveals, voids)',
        $pdo->query("SELECT id, letter, attendee_id, prize_label, revealed_at, voided_at FROM predrawn_winners WHERE event_id = {$eventId} ORDER BY id")->fetchAll() === $assignedBefore);
    $list = $admin->data('GET', '/predrawn');
    $flags = array_column(array_column($list['assignments'] ?? [], null, 'letter'), 'inPool', 'letter');
    check('Overview: C inPool false but still assigned (information only)', ($flags['C'] ?? null) === false
        && (array_column($list['assignments'] ?? [], 'status', 'letter')['C'] ?? '') === 'assigned', $flags);
    $r = $assign(['letter' => 'E', 'attendee_id' => $ids['eli']]);
    check('Eli (S2 only, now outside the selection) can still be assigned E (201)', $r['status'] === 201, $r['raw']);
    $r = $operator->request('POST', '/predrawn/reveal', ['letter' => 'Z']);
    check('Reveal Z -> Ivy (never scanned / registered) with prize Bike', $r['status'] === 200 && ($r['body']['data']['winner'] ?? null) === ['fullName' => 'Ivy Noscan', 'prizeLabel' => 'Bike'], $r['raw']);
    $r = $operator->request('POST', '/predrawn/reveal', ['letter' => 'X']);
    check('Reveal X -> Hal (registered on another day only)', $r['status'] === 200 && ($r['body']['data']['winner']['fullName'] ?? '') === 'Hal Otherday', $r['raw']);
    $r = $operator->request('POST', '/predrawn/reveal', ['letter' => 'Y']);
    check('Reveal Y -> Fay (registered only outside the selected sessions)', $r['status'] === 200 && ($r['body']['data']['winner'] ?? null) === ['fullName' => 'Fay Unselected', 'prizeLabel' => 'Watch'], $r['raw']);
    check('Ivy still has no registration or scan after her reveal', (int) $pdo->query("SELECT COUNT(*) FROM session_registrations WHERE attendee_id = {$ids['ivy']}")->fetchColumn() === 0
        && (int) $pdo->query("SELECT COUNT(*) FROM registration_scans WHERE attendee_id = {$ids['ivy']}")->fetchColumn() === 0);
    $r = $put(['session_ids' => [$s1, $s2]]);
    check('Admin re-selects S1 + S2 (200)', $r['status'] === 200 && ($r['body']['data']['pool']['size'] ?? null) === 5, $r['raw']);
    $r = $admin->request('POST', '/predrawn/reveal', ['letter' => 'C']);
    check('Admin reveals C -> Makiling with prize TV', ($r['body']['data']['winner'] ?? null) === ['fullName' => 'Makiling Santos', 'prizeLabel' => 'TV'], $r['raw']);

    section('Records, report and audit');
    $names = $operator->data('GET', '/predrawn/roll-names')['names'] ?? [];
    check('Roll names: a sample of the pool only (max 40)', $names !== [] && count($names) <= 40 && array_diff($names, $poolNames) === [], $names);
    $sheet = $xlsxRows($admin, '/reports/predrawn-winners.xlsx?scope=day');
    $byStatus = array_count_values(array_map(static fn (array $row): string => $row['cells'][8] ?? '', $sheet['rows']));
    check('Randomizer Winners (Pre-Drawn).xlsx: header + every row labelled "Pre-Drawn Winner"', ($sheet['headers'][0] ?? '') === 'Category'
        && count($sheet['rows']) === 8 && array_unique(array_map(static fn (array $row): string => $row['cells'][0], $sheet['rows'])) === ['Pre-Drawn Winner'], $sheet);
    check('Report statuses: 6 REVEALED + 1 VOIDED + 1 ASSIGNED', $byStatus == ['REVEALED' => 6, 'VOIDED' => 1, 'ASSIGNED' => 1], $byStatus);
    $winnersAfter = $xlsxRows($admin, '/reports/winners.xlsx?scope=day');
    check('Legacy Winners.xlsx (Minor / Major history) unchanged: same columns, no pre-drawn rows', $winnersAfter['headers'] === $winnersBefore['headers'] && count($winnersAfter['rows']) === count($winnersBefore['rows']));
    $audit = $pdo->query("SELECT action, description FROM audit_logs WHERE event_id = {$eventId} AND action LIKE 'predrawn.%' ORDER BY id")->fetchAll();
    $actions = array_count_values(array_column($audit, 'action'));
    check('Audit: assigned (x9) / updated / removed / revealed (x7) / voided recorded', ($actions['predrawn.revealed'] ?? 0) === 7 && ($actions['predrawn.reveal_voided'] ?? 0) === 1
        && ($actions['predrawn.assignment_removed'] ?? 0) === 1 && ($actions['predrawn.assignment_updated'] ?? 0) === 1 && ($actions['predrawn.assigned'] ?? 0) === 9, $actions);
    $reveals = array_filter($audit, static fn (array $x): bool => $x['action'] === 'predrawn.revealed');
    check('Every reveal audit says "Pre-Drawn Winner" and "not a random draw"', array_filter($reveals, static fn (array $x): bool => !str_contains($x['description'], 'Pre-Drawn Winner') || !str_contains($x['description'], 'not a random draw')) === []);
    check('No randomizer_draws row was written', (int) $pdo->query("SELECT COUNT(*) FROM randomizer_draws WHERE event_id = {$eventId}")->fetchColumn() === $drawsBefore);
    check('Attendees, QR tokens, registrations, scans and manual pools unchanged by pool changes / reveals', $snapshot() === $before);
    check('Removed registration (Gus) still removed', $pdo->query("SELECT status FROM session_registrations WHERE attendee_id = {$ids['gus']}")->fetchColumn() === 'removed');

    section('Legacy Minor / Major Randomizer APIs removed');
    $legacy = [
        ['GET', '/randomizers/minor'], ['POST', '/randomizers/minor/draw'], ['GET', '/randomizers/major'], ['POST', '/randomizers/major/draw'],
        ['GET', '/randomizers/minor/candidates?search=Anna'], ['POST', '/randomizers/minor/participants'], ['POST', '/randomizers/draws/1/void'],
        ['GET', '/settings/randomizer-reset'], ['POST', '/settings/randomizer-reset'],
    ];
    $codes = array_map(static fn (array $x): int => $admin->request($x[0], $x[1], $x[0] === 'POST' ? [] : null)['status'], $legacy);
    check('Every legacy route -> 404 (admin)', array_unique($codes) === [404], $codes);
    check('Still no randomizer_draws rows for this event', (int) $pdo->query("SELECT COUNT(*) FROM randomizer_draws WHERE event_id = {$eventId}")->fetchColumn() === $drawsBefore);
} finally {
    section('Cleanup');
    if ($eventId) {
        $admin->request('PATCH', "/events/{$eventId}/status", ['status' => 'archived']);
    }
    if ($originalActive !== null) {
        $restored = $admin->request('PATCH', "/events/{$originalActive}/status", ['status' => 'active'])['status'] === 200;
        echo $restored ? "  Restored the previously active event.\n" : "  WARNING: could not restore event #{$originalActive} to active.\n";
    }
    foreach (array_filter($testUserIds) as $userId) {
        User::setStatus((int) $userId, User::STATUS_INACTIVE);
    }
    echo "  Test event archived; test accounts disabled.\n";
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
