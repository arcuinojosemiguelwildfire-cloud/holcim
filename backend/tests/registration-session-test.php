<?php

declare(strict_types=1);

/**
 * Phase 10 registration-session test (HTTP, end to end):
 * sessions per event day, scanning into the CURRENT session, the legacy Major
 * eligibility the API still derives from sessions marked counts_for_major
 * (Oct 14 5:00 PM yes, Oct 15 8:00 AM no, Oct 15 5:00 PM yes; shown nowhere
 * in the UI since the single Randomizer), one entry per attendee, admin
 * Manage / renew registration with history, role restrictions, day
 * separation, and that the removed Minor / Major draw and Randomizer Reset
 * APIs return 404. QR tokens and attendee records must not change.
 *
 *   HOLCIM_TEST_EMAIL=admin@example.com HOLCIM_TEST_PASSWORD='...' \
 *     php backend/tests/registration-session-test.php http://localhost/Holcim/backend/api --write
 *
 * DEVELOPMENT DATABASE ONLY (same rules as phase8-smoke-test.php): it creates
 * its own "Sessions Test ..." event (Oct 14 + Oct 15, attendees A-H) and test
 * accounts with random passwords. While it runs the active event is set to
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
use App\Models\User;
use App\Utils\Token;

if (!function_exists('curl_init')) {
    fwrite(STDERR, "The PHP curl extension is required.\n");
    exit(1);
}
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
if ($adminEmail === '' || $adminPassword === '') {
    fwrite(STDERR, "Set HOLCIM_TEST_EMAIL and HOLCIM_TEST_PASSWORD (an admin account).\n");
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

$pdo = Database::connection();
$stamp = date('His') . '-' . bin2hex(random_bytes(2));
$admin = new TestApiClient($baseUrl);
if ($admin->login($adminEmail, $adminPassword) !== 200) {
    fwrite(STDERR, "Admin login failed.\n");
    exit(1);
}
$originalActive = $pdo->query("SELECT id FROM events WHERE status = 'active' LIMIT 1")->fetchColumn();
$originalActive = $originalActive !== false ? (int) $originalActive : null;
$eventId = null;
$testUserIds = [];

/** @return array<string, string> attendee_id => token for the event (to prove nothing changed) */
$qrSnapshot = static function (int $eventId) use ($pdo): array {
    $rows = $pdo->query("SELECT q.attendee_id, q.token, a.attendee_code FROM attendee_qr_codes q JOIN attendees a ON a.id = q.attendee_id
                         WHERE a.event_id = {$eventId} ORDER BY q.attendee_id")->fetchAll();
    $out = [];
    foreach ($rows as $row) {
        $out[(string) $row['attendee_id']] = $row['attendee_code'] . '|' . $row['token'];
    }

    return $out;
};

try {
    section('Setup');
    if ($originalActive !== null) {
        $admin->request('PATCH', "/events/{$originalActive}/status", ['status' => 'completed']);
    }
    $created = $admin->request('POST', '/events', ['name' => "Sessions Test {$stamp}", 'event_date' => '2026-10-14', 'status' => 'draft', 'description' => '']);
    $eventId = (int) ($created['body']['data']['event']['id'] ?? 0);
    check('Admin creates the Oct 14 event (201)', $created['status'] === 201, $created['raw']);
    check('Activate event (200)', $admin->request('PATCH', "/events/{$eventId}/status", ['status' => 'active'])['status'] === 200);
    $days = $admin->data('GET', "/events/{$eventId}/days")['days'] ?? [];
    $day1 = (int) ($days[0]['id'] ?? 0);
    $added = $admin->request('POST', "/events/{$eventId}/days", ['event_date' => '2026-10-15', 'label' => '']);
    $day2 = (int) ($added['body']['data']['day']['id'] ?? 0);
    check('Admin adds Day 2 = Oct 15 (201)', $added['status'] === 201, $added['raw']);

    $s1 = $admin->data('GET', "/event-days/{$day1}/sessions")['sessions'] ?? [];
    $s2 = $admin->data('GET', "/event-days/{$day2}/sessions")['sessions'] ?? [];
    check('Each new day starts with ONE default session (current, counts for Major)', count($s1) === 1 && count($s2) === 1
        && $s1[0]['current'] && $s1[0]['countsForMajor'] && $s2[0]['current'] && $s2[0]['countsForMajor'], [$s1, $s2]);

    // Accounts: event operator, registration staff, scanner operator.
    $secret = [];
    foreach (['operator' => User::ROLE_EVENT_OPERATOR, 'staff' => User::ROLE_REGISTRATION_STAFF] as $key => $role) {
        $secret[$key] = bin2hex(random_bytes(12));
        $testUserIds[] = User::create("RS {$key} {$stamp}", "rs-{$key}-{$stamp}@example.invalid", password_hash($secret[$key], PASSWORD_DEFAULT), $role);
    }
    $operator = new TestApiClient($baseUrl);
    $staff = new TestApiClient($baseUrl);
    check('Event operator logs in', $operator->login("rs-operator-{$stamp}@example.invalid", $secret['operator']) === 200);
    check('Registration staff logs in', $staff->login("rs-staff-{$stamp}@example.invalid", $secret['staff']) === 200);
    $secret['scanner'] = bin2hex(random_bytes(8));
    $r = $admin->request('POST', '/settings/scanner-operators', ['name' => 'RS Gate', 'username' => "rs-gate-{$stamp}", 'password' => $secret['scanner'],
        'password_confirmation' => $secret['scanner'], 'status' => 'active']);
    $testUserIds[] = (int) ($r['body']['data']['operator']['id'] ?? 0);
    $scanner = new TestApiClient($baseUrl);
    check('Scanner operator logs in', $scanner->login("rs-gate-{$stamp}", $secret['scanner']) === 200);

    // ---------------------------------------------------- session configuration
    section('Session configuration (data, admin only)');
    $put = static fn (TestApiClient $c, int $id, array $body) => $c->request('PUT', "/registration-sessions/{$id}", $body);
    $r = $put($admin, (int) $s1[0]['id'], ['label' => 'Oct 14 - 5:00 PM', 'session_date' => '2026-10-14', 'start_time' => '17:00', 'counts_for_major' => true, 'sort_order' => 1]);
    check('Admin configures Oct 14 - 5:00 PM (17:00, counts for Major)', $r['status'] === 200 && ($r['body']['data']['session']['startTime'] ?? '') === '17:00', $r['raw']);
    $am = (int) $s2[0]['id'];
    $r = $put($admin, $am, ['label' => 'Oct 15 - 8:00 AM', 'session_date' => '2026-10-15', 'start_time' => '08:00', 'counts_for_major' => false, 'sort_order' => 1]);
    check('Admin configures Oct 15 - 8:00 AM (does NOT count for Major)', $r['status'] === 200 && ($r['body']['data']['session']['countsForMajor'] ?? true) === false, $r['raw']);
    $r = $admin->request('POST', "/event-days/{$day2}/sessions", ['label' => 'Oct 15 - 5:00 PM', 'session_date' => '2026-10-15', 'start_time' => '17:00', 'counts_for_major' => true, 'sort_order' => 2]);
    $pm = (int) ($r['body']['data']['session']['id'] ?? 0);
    check('Admin adds Oct 15 - 5:00 PM (201), not current yet', $r['status'] === 201 && ($r['body']['data']['session']['current'] ?? true) === false, $r['raw']);
    $r = $admin->request('POST', "/event-days/{$day2}/sessions", ['label' => 'oct 15 - 5:00 pm', 'start_time' => '18:00', 'counts_for_major' => true]);
    check('Duplicate session name on the same day rejected (422)', $r['status'] === 422, $r['raw']);
    $r = $admin->request('POST', "/event-days/{$day2}/sessions", ['label' => 'No time', 'counts_for_major' => true]);
    check('Start time is required (422)', $r['status'] === 422 && isset($r['body']['error']['details']['fields']['start_time']), $r['raw']);
    $r = $put($admin, $am, ['label' => 'Oct 15 - 8:00 AM', 'start_time' => '08:00', 'counts_for_major' => false, 'enabled' => false]);
    check('The current session cannot be disabled (409)', $r['status'] === 409, $r['raw']);
    foreach (['registration staff' => $staff, 'scanner operator' => $scanner, 'event operator' => $operator] as $who => $client) {
        $codes = [
            $client->request('GET', "/event-days/{$day2}/sessions")['status'],
            $client->request('POST', "/event-days/{$day2}/sessions", ['label' => 'X', 'start_time' => '09:00', 'counts_for_major' => true])['status'],
            $put($client, $pm, ['label' => 'Hack', 'start_time' => '17:00', 'counts_for_major' => true])['status'],
            $client->request('PATCH', "/registration-sessions/{$pm}/activate")['status'],
        ];
        check("{$who} cannot list / add / edit / switch sessions (403)", $codes === [403, 403, 403, 403], $codes);
    }

    // Attendees A-H, one QR each (one attendee record per person, used on both days).
    $ids = [];
    $tokens = [];
    foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'] as $key) {
        $ids[$key] = Attendee::create($eventId, "RS{$key}-{$stamp}", ['full_name' => "Session {$key}", 'company' => 'Session Co', 'department' => 'QA'], null);
        AttendeeQrCode::create($ids[$key], $tokens[$key] = Token::random(24));
    }
    $code = static fn (string $key): string => "RS{$key}-{$stamp}";
    $qrBefore = $qrSnapshot($eventId);
    $attendeeCount = static fn (): int => (int) $pdo->query("SELECT COUNT(*) FROM attendees WHERE event_id = {$eventId}")->fetchColumn();
    $attendeesBefore = $attendeeCount();
    $regRows = static fn (string $key, ?int $sessionId = null): int => (int) $pdo->query(
        "SELECT COUNT(*) FROM session_registrations WHERE attendee_id = {$ids[$key]}" . ($sessionId !== null ? " AND registration_session_id = {$sessionId}" : '')
    )->fetchColumn();
    $scan = static fn (string $key) => $scanner->request('POST', '/registration/scan', ['token' => $tokens[$key]]);
    // The Minor / Major screens and APIs were removed; the session-based eligibility
    // they used is still computed on the server (scan result, attendee history,
    // reports), so it is checked through the model directly for the CURRENT day.
    $currentDay = static fn (): int => (int) ($admin->data('GET', '/event-days/current')['eventDay']['id'] ?? 0);
    $pool = static function (string $type) use ($pdo, $currentDay): array {
        $ids = App\Models\RandomizerDraw::eligibleIds($currentDay(), $type);
        if ($ids === []) {
            return [];
        }

        return array_map('strval', $pdo->query('SELECT attendee_code FROM attendees WHERE id IN (' . implode(',', $ids) . ') ORDER BY attendee_code')->fetchAll(PDO::FETCH_COLUMN));
    };
    $eligibleCount = static fn (string $type): int => App\Models\RandomizerDraw::countEligible($currentDay(), $type);

    // ---------------------------------------------------- Test 1: Oct 14 5:00 PM
    section('Test 1 - Oct 14 5:00 PM registration');
    $summary = $scanner->data('GET', '/registration/summary');
    check('Registration desk shows the current session (October 14, 5:00 PM)', ($summary['registrationSession']['label'] ?? '') === 'Oct 14 - 5:00 PM'
        && ($summary['registrationSession']['dateLabel'] ?? '') === 'October 14' && ($summary['registrationSession']['timeLabel'] ?? '') === '5:00 PM', $summary['registrationSession'] ?? null);
    check('Top bar context includes the current session', ($scanner->data('GET', '/event-days/current')['registrationSession']['label'] ?? '') === 'Oct 14 - 5:00 PM');
    $r = $scan('A');
    $d = $r['body']['data'] ?? [];
    check('Scan A -> registered for Oct 14 - 5:00 PM', ($d['status'] ?? '') === 'registered' && ($d['registrationSession']['label'] ?? '') === 'Oct 14 - 5:00 PM', $r['raw']);
    check('Result: Major eligible YES, Minor eligible YES', ($d['majorEligible'] ?? null) === true && ($d['minorEligible'] ?? null) === true);
    check('Result exposes no internal ids or QR token', !str_contains($r['raw'], $tokens['A']) && !preg_match('/"(attendee_?[iI]d|qr_?[iI]d|token)"/', $r['raw']), $r['raw']);
    check('Minor pool (Oct 14) includes A', in_array($code('A'), $pool('minor'), true));
    check('Major pool (Oct 14) includes A', in_array($code('A'), $pool('major'), true));
    check('One registration row, one day check-in', $regRows('A') === 1
        && (int) $pdo->query("SELECT COUNT(*) FROM registration_scans WHERE attendee_id = {$ids['A']}")->fetchColumn() === 1);

    // ---------------------------------------------------- Oct 15
    check('Admin makes Oct 15 the current day (200)', $admin->request('PATCH', "/event-days/{$day2}/activate")['status'] === 200);
    check('Desk now shows Oct 15 - 8:00 AM', ($scanner->data('GET', '/registration/summary')['registrationSession']['label'] ?? '') === 'Oct 15 - 8:00 AM');

    section('Test 2 - Oct 15 8:00 AM registration');
    $r = $scan('B');
    $d = $r['body']['data'] ?? [];
    check('Scan B -> registered for Oct 15 - 8:00 AM', ($d['status'] ?? '') === 'registered' && ($d['registrationSession']['label'] ?? '') === 'Oct 15 - 8:00 AM', $r['raw']);
    check('Result: Major NOT eligible (8:00 AM does not count), Minor eligible', ($d['majorEligible'] ?? null) === false && ($d['minorEligible'] ?? null) === true);
    $scan('C');
    $scan('F');
    $scan('H');

    section('Test 8 - 8:00 AM-only attendees are not in the Oct 15 Major pool');
    $major = $pool('major');
    check('Major pool (Oct 15) excludes B, C, F, H (8:00 AM only) and A (Oct 14 only)', array_intersect([$code('A'), $code('B'), $code('C'), $code('F'), $code('H')], $major) === [], $major);
    check('Minor pool (Oct 15) includes B, C, F, H and not A (day separation)', array_diff([$code('B'), $code('C'), $code('F'), $code('H')], $pool('minor')) === []
        && !in_array($code('A'), $pool('minor'), true));
    $d = $admin->data('GET', '/dashboard/summary');
    check('Dashboard: registered today 4, Minor 4, Major 0', ($d['metrics']['registered']['value'] ?? -1) === 4
        && ($d['metrics']['minorEligible']['value'] ?? -1) === 4 && ($d['metrics']['majorEligible']['value'] ?? -1) === 0, $d['metrics'] ?? null);

    section('Admin switches to the 5:00 PM session');
    check('Admin cannot make a disabled session current (409)', (function () use ($admin, $put, $day2): bool {
        $r = $admin->request('POST', "/event-days/{$day2}/sessions", ['label' => 'Disabled one', 'start_time' => '20:00', 'counts_for_major' => true, 'enabled' => false]);
        $id = (int) ($r['body']['data']['session']['id'] ?? 0);

        return $r['status'] === 201 && $admin->request('PATCH', "/registration-sessions/{$id}/activate")['status'] === 409;
    })());
    $r = $admin->request('PATCH', "/registration-sessions/{$pm}/activate");
    check('Admin switches to Oct 15 - 5:00 PM (200)', $r['status'] === 200 && ($r['body']['data']['session']['current'] ?? false) === true, $r['raw']);
    check('Exactly one current session for Oct 15', (int) $pdo->query("SELECT COUNT(*) FROM registration_sessions WHERE event_day_id = {$day2} AND is_current = 1")->fetchColumn() === 1);
    check('Audit: registration_session.changed', (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE event_id = {$eventId} AND action = 'registration_session.changed'")->fetchColumn() >= 1);
    check('Switching kept the 8:00 AM registrations', $regRows('B', $am) === 1 && $regRows('C', $am) === 1);

    section('Test 3 - Oct 15 5:00 PM registration');
    $r = $scan('D');
    $d = $r['body']['data'] ?? [];
    check('Scan D -> registered for Oct 15 - 5:00 PM, Major eligible YES', ($d['status'] ?? '') === 'registered'
        && ($d['registrationSession']['label'] ?? '') === 'Oct 15 - 5:00 PM' && ($d['majorEligible'] ?? null) === true, $r['raw']);

    section('Test 4 / 10 - same attendee at 8:00 AM and 5:00 PM');
    $r = $scan('C');
    check('Scan C at 5:00 PM -> registered (a new session), Major eligible YES', ($r['body']['data']['status'] ?? '') === 'registered'
        && ($r['body']['data']['majorEligible'] ?? null) === true, $r['raw']);
    check('C: one attendee record, two registration rows (8:00 AM + 5:00 PM)', (int) $pdo->query("SELECT COUNT(*) FROM attendees WHERE attendee_code = " . $pdo->quote($code('C')))->fetchColumn() === 1
        && $regRows('C') === 2 && $regRows('C', $am) === 1 && $regRows('C', $pm) === 1);
    check('C keeps ONE day check-in for Oct 15', (int) $pdo->query("SELECT COUNT(*) FROM registration_scans WHERE attendee_id = {$ids['C']} AND event_day_id = {$day2}")->fetchColumn() === 1);
    $major = $pool('major');
    $minor = $pool('minor');
    check('Major pool lists C exactly once', count(array_keys($major, $code('C'), true)) === 1, $major);
    check('Minor pool lists C exactly once', count(array_keys($minor, $code('C'), true)) === 1, $minor);
    check('Major pool = {C, D} and the Major count matches (each attendee once)', (function () use ($major, $code, $eligibleCount): bool {
        $sorted = $major;
        sort($sorted);
        $want = [$code('C'), $code('D')];
        sort($want);

        return $sorted === $want && $eligibleCount('major') === 2;
    })(), $major);
    check('Minor count = 5 distinct attendees (B, C, D, F, H)', $eligibleCount('minor') === 5);

    section('Test 9 - 5:00 PM attendee is in the Major pool');
    check('D is in the Oct 15 Major pool', in_array($code('D'), $major, true));

    section('Test 5 - duplicate scan in the same session');
    $r = $scan('D');
    check('Scan D again at 5:00 PM -> already_registered', ($r['body']['data']['status'] ?? '') === 'already_registered' && ($r['body']['data']['registrationSession']['label'] ?? '') === 'Oct 15 - 5:00 PM', $r['raw']);
    check('Still one registration row for D in 5:00 PM', $regRows('D', $pm) === 1);
    $parallel = TestApiClient::parallel([[$scanner, 'POST', '/registration/scan', ['token' => $tokens['E']]], [$scanner, 'POST', '/registration/scan', ['token' => $tokens['E']]], [$staff, 'POST', '/registration/scan', ['token' => $tokens['E']]]]);
    $statuses = array_map(static fn (array $p): string => (string) ($p['body']['data']['status'] ?? $p['status']), $parallel);
    sort($statuses);
    check('Three simultaneous scans of E -> one registered, two already_registered, one row', $statuses === ['already_registered', 'already_registered', 'registered'] && $regRows('E', $pm) === 1, $statuses);
    $summary = $scanner->data('GET', '/registration/summary');
    check('Desk counts: this session 3 (C, D, E), today 6', ($summary['counts']['registered'] ?? -1) === 3 && ($summary['counts']['registeredToday'] ?? -1) === 6, $summary['counts'] ?? null);

    // ---------------------------------------------------- admin manage / renew
    section('Test 6 - admin grants Oct 15 5:00 PM to B (manual)');
    $reg = static fn (TestApiClient $c, string $key, array $sessions, ?string $reason = null, array $extra = []) =>
        $c->request('PUT', "/attendees/{$ids[$key]}/registrations", ['sessions' => $sessions, 'reason' => $reason] + $extra);
    $overview = $admin->data('GET', "/attendees/{$ids['B']}/registrations");
    $dayOf = static fn (array $o, int $dayId): array => array_values(array_filter($o['days'] ?? [], static fn (array $d): bool => $d['day']['id'] === $dayId))[0] ?? [];
    check('Overview before: B registered 8:00 AM only, Oct 15 Major NOT eligible', ($dayOf($overview, $day2)['majorEligible'] ?? null) === false
        && ($dayOf($overview, $day2)['registered'] ?? null) === true);
    check('Registration staff can VIEW the registration history (200)', $staff->request('GET', "/attendees/{$ids['B']}/registrations")['status'] === 200);
    foreach (['registration staff' => $staff, 'scanner operator' => $scanner, 'event operator' => $operator] as $who => $client) {
        check("{$who} cannot manage registrations (403)", $reg($client, 'B', [['session_id' => $pm, 'registered' => true]])['status'] === 403);
    }
    check('Scanner operator cannot view attendee registrations (403)', $scanner->request('GET', "/attendees/{$ids['B']}/registrations")['status'] === 403);
    $r = $reg($admin, 'B', [['session_id' => $pm, 'registered' => true], ['session_id' => $am, 'registered' => true]], 'Arrived for the evening program');
    $after = $r['body']['data'] ?? [];
    check('Admin saves: B registered for 5:00 PM (200, 1 change; 8:00 AM unchanged)', $r['status'] === 200 && count($after['applied'] ?? []) === 1, $r['raw']);
    check('Oct 15 Major eligibility of B becomes YES (source registration)', ($dayOf($after, $day2)['majorEligible'] ?? null) === true && ($dayOf($after, $day2)['majorSource'] ?? '') === 'registration');
    check('B: same attendee record, no duplicate, QR unchanged', $attendeeCount() === $attendeesBefore && $qrSnapshot($eventId) === $qrBefore && $regRows('B') === 2);
    check('B is now in the Major pool', in_array($code('B'), $pool('major'), true));
    check('Manual registration is source=manual and records the admin', (string) $pdo->query("SELECT CONCAT(source, '|', registered_by IS NOT NULL) FROM session_registrations WHERE attendee_id = {$ids['B']} AND registration_session_id = {$pm}")->fetchColumn() === 'manual|1');
    check('Audit: registration.manually_added + registration.eligibility_changed', (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE event_id = {$eventId} AND action = 'registration.manually_added'")->fetchColumn() === 1
        && (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE event_id = {$eventId} AND action = 'registration.eligibility_changed'")->fetchColumn() >= 1);
    $r = $reg($admin, 'F', [['session_id' => $am, 'registered' => true]], null, ['major_eligible' => true, 'majorEligible' => true]);
    check('A client-sent major_eligible=true is ignored (F stays NOT Major eligible)', $r['status'] === 200 && ($dayOf($r['body']['data'] ?? [], $day2)['majorEligible'] ?? null) === false
        && !in_array($code('F'), $pool('major'), true), $r['raw']);
    $other = $admin->request('POST', '/events', ['name' => "Sessions Test {$stamp} (other)", 'event_date' => '2031-01-01', 'status' => 'draft', 'description' => '']);
    $otherEvent = (int) ($other['body']['data']['event']['id'] ?? 0);
    $otherSession = (int) $pdo->query("SELECT id FROM registration_sessions WHERE event_id = {$otherEvent} LIMIT 1")->fetchColumn();
    check('A session of another event is rejected (422)', $reg($admin, 'B', [['session_id' => $otherSession, 'registered' => true]])['status'] === 422);
    $admin->request('PATCH', "/events/{$otherEvent}/status", ['status' => 'archived']);

    section('Test 7 - admin removes the manual registration (history kept)');
    $r = $reg($admin, 'B', [['session_id' => $pm, 'registered' => false]]);
    check('Removal without a reason is rejected (422) and changes nothing', $r['status'] === 422 && $regRows('B', $pm) === 1
        && (string) $pdo->query("SELECT status FROM session_registrations WHERE attendee_id = {$ids['B']} AND registration_session_id = {$pm}")->fetchColumn() === 'active', $r['raw']);
    $r = $reg($admin, 'B', [['session_id' => $pm, 'registered' => false]], 'Left before the program');
    $after = $r['body']['data'] ?? [];
    check('Admin removes B 5:00 PM (200): Major eligibility back to NO', $r['status'] === 200 && ($dayOf($after, $day2)['majorEligible'] ?? null) === false, $r['raw']);
    check('Row kept as status=removed with who/when/why (not deleted)', (string) $pdo->query("SELECT CONCAT(status, '|', removed_by IS NOT NULL, '|', removal_reason) FROM session_registrations
        WHERE attendee_id = {$ids['B']} AND registration_session_id = {$pm}")->fetchColumn() === 'removed|1|Left before the program');
    check('B attendee + QR unchanged; 8:00 AM registration kept', $attendeeCount() === $attendeesBefore && $qrSnapshot($eventId) === $qrBefore && $regRows('B', $am) === 1);
    check('B no longer in the Major pool, still in the Minor pool', !in_array($code('B'), $pool('major'), true) && in_array($code('B'), $pool('minor'), true));
    $hist = array_column($after['history'] ?? [], 'action');
    check('History lists scanned, manually_added and removed', in_array('scanned', $hist, true) && in_array('manually_added', $hist, true) && in_array('removed', $hist, true), $hist);
    $r = $scan('B');
    check('B scanning again in 5:00 PM is refused (422 REGISTRATION_REMOVED, ask an admin)', $r['status'] === 422 && ($r['body']['error']['code'] ?? '') === 'REGISTRATION_REMOVED', $r['raw']);
    check('...and logged as registration_removed in the scan log', (int) $pdo->query("SELECT COUNT(*) FROM scan_logs WHERE attendee_id = {$ids['B']} AND result = 'registration_removed' AND registration_session_id = {$pm}")->fetchColumn() === 1);
    $r = $reg($admin, 'B', [['session_id' => $pm, 'registered' => true]], 'Came back');
    check('Admin restores B 5:00 PM (renew): Major YES again, still one row', $r['status'] === 200 && ($dayOf($r['body']['data'] ?? [], $day2)['majorEligible'] ?? null) === true
        && $regRows('B', $pm) === 1 && in_array('restored', array_column($r['body']['data']['history'] ?? [], 'action'), true), $r['raw']);
    check('Audit: registration.manually_removed recorded', (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE event_id = {$eventId} AND action = 'registration.manually_removed'")->fetchColumn() === 1);
    $r = $admin->request('PATCH', "/attendees/{$ids['G']}/status", ['status' => 'archived']);
    check('Archived attendee cannot be given a registration (422)', $reg($admin, 'G', [['session_id' => $pm, 'registered' => true]])['status'] === 422);

    // ---------------------------------------------------- Test 11 - randomizer rules
    section('Test 11 - old Minor / Major draw and reset APIs are gone; eligibility still derived from sessions');
    foreach ([['POST', '/randomizers/major/draw'], ['POST', '/randomizers/minor/draw'], ['GET', '/randomizers/major/participants'], ['POST', '/settings/randomizer-reset']] as [$m, $p]) {
        check("Removed: {$m} {$p} (404)", $operator->request($m, $p)['status'] === 404 && $admin->request($m, $p)['status'] === 404);
    }
    check('No draw rows were created by registration or sessions', (int) $pdo->query("SELECT COUNT(*) FROM randomizer_draws WHERE event_id = {$eventId}")->fetchColumn() === 0);
    check('Major (session-based) still excludes the 8:00 AM-only attendees F and H', array_intersect([$code('F'), $code('H')], $pool('major')) === []);
    check('Oct 14 pools are separate (A still the only Oct 14 registration)', (int) $pdo->query("SELECT COUNT(DISTINCT attendee_id) FROM session_registrations WHERE event_day_id = {$day1} AND status = 'active'")->fetchColumn() === 1);

    // ---------------------------------------------------- reports + invariants
    section('Reports and invariants');
    $el = $admin->csv('/reports/eligibility.csv?scope=day');
    $byCode = [];
    foreach ($el as $row) {
        $byCode[$row['Attendee Code']] = $row;
    }
    check('Eligibility report: F (8:00 AM only) Minor Yes / Major No; C Major Yes', ($byCode[$code('F')]['Minor Eligible'] ?? '') === 'Yes' && ($byCode[$code('F')]['Major Eligible'] ?? '') === 'No'
        && ($byCode[$code('C')]['Major Eligible'] ?? '') === 'Yes' && str_contains($byCode[$code('C')]['Registration Sessions'] ?? '', 'Oct 15 - 8:00 AM; Oct 15 - 5:00 PM'), $byCode[$code('C')] ?? null);
    check('Registration report lists the sessions per attendee', str_contains(implode("\n", array_column($admin->csv('/reports/registration.csv?scope=day'), 'Registration Sessions')), 'Oct 15 - 5:00 PM (manual)'));
    check('No QR token changed for any attendee of the event', $qrSnapshot($eventId) === $qrBefore);
    check('No attendee record was created by registration', $attendeeCount() === $attendeesBefore);
    check('No duplicate (attendee, session) rows', (int) $pdo->query("SELECT COUNT(*) FROM (SELECT attendee_id, registration_session_id FROM session_registrations WHERE event_id = {$eventId}
        GROUP BY attendee_id, registration_session_id HAVING COUNT(*) > 1) x")->fetchColumn() === 0);
    check('Every registration change is in the history table', (int) $pdo->query("SELECT COUNT(*) FROM session_registration_changes WHERE event_id = {$eventId}")->fetchColumn()
        >= (int) $pdo->query("SELECT COUNT(*) FROM session_registrations WHERE event_id = {$eventId}")->fetchColumn());
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
