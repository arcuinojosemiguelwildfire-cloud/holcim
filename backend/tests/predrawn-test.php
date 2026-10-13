<?php

declare(strict_types=1);

/**
 * Pre-Drawn Winner Reveal test (HTTP, end to end; migration 035).
 * Assignments (validation, uniqueness, roles), reveals (correct winner,
 * unassigned letter, repeat, concurrent duplicates), voids and re-reveal,
 * the report, audit labels, and that the Minor / Major Randomizer, its
 * history and every attendee / QR / registration record stay unchanged.
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

    $people = ['anna' => 'Anna Reyes', 'juan' => 'Juan Dela Cruz', 'makiling' => 'Makiling Santos', 'dina' => 'Dina Uy', 'eli' => 'Eli Cruz', 'old' => 'Archived Person'];
    $ids = [];
    foreach ($people as $key => $name) {
        $ids[$key] = Attendee::create($eventId, 'PDT-' . strtoupper($key) . "-{$stamp}", ['full_name' => $name, 'company' => 'Test Co', 'department' => 'QA'], null);
        AttendeeQrCode::create($ids[$key], Token::random(24));
    }
    Attendee::setStatus($ids['old'], Attendee::STATUS_ARCHIVED);
    $otherEventAttendee = (int) $pdo->query("SELECT id FROM attendees WHERE event_id <> {$eventId} LIMIT 1")->fetchColumn();

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
        $pdo->query("SELECT COUNT(*) FROM session_registrations WHERE event_id = {$eventId}")->fetchColumn(),
        $pdo->query("SELECT COUNT(*) FROM registration_scans WHERE event_id = {$eventId}")->fetchColumn(),
        $pdo->query("SELECT COUNT(*) FROM minor_manual_entries WHERE event_id = {$eventId}")->fetchColumn(),
        $pdo->query("SELECT COUNT(*) FROM major_eligibility WHERE event_id = {$eventId}")->fetchColumn(),
    ]));
    $before = $snapshot();
    $drawsBefore = (int) $pdo->query("SELECT COUNT(*) FROM randomizer_draws WHERE event_id = {$eventId}")->fetchColumn();
    $minorBefore = $operator->data('GET', '/randomizers/minor')['eligibleCount'] ?? null;
    $majorBefore = $operator->data('GET', '/randomizers/major')['eligibleCount'] ?? null;
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
        ];
        check("{$who}: list / reveal / assign / report all 403", $codes === [403, 403, 403, 403], $codes);
    }
    check('Event operator can view (200) but not assign (403)', $operator->request('GET', '/predrawn')['status'] === 200
        && $operator->request('POST', '/predrawn/assignments', ['letter' => 'A', 'attendee_id' => $ids['anna']])['status'] === 403);
    $old = $admin->swapCsrf(null);
    $r = $admin->request('POST', '/predrawn/assignments', ['letter' => 'A', 'attendee_id' => $ids['anna']]);
    $admin->swapCsrf($old);
    check('Admin without CSRF token -> 403 CSRF_TOKEN_MISMATCH', $r['status'] === 403 && ($r['body']['error']['code'] ?? '') === 'CSRF_TOKEN_MISMATCH', $r['raw']);
    check('Nothing assigned by refused requests', ($admin->data('GET', '/predrawn')['assignments'] ?? null) === []);

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
    check('Operator sees A, B, C in letter order (review list)', array_column($list['assignments'] ?? [], 'letter') === ['A', 'B', 'C']
        && ($list['category'] ?? '') === 'Pre-Drawn Winner', $list);

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
    check('Overview: A, B, C live; voided B listed separately', array_column($overview['assignments'] ?? [], 'letter') === ['A', 'B', 'C']
        && array_column($overview['voided'] ?? [], 'letter') === ['B'], $overview);
    $r = $admin->request('POST', '/predrawn/reveal', ['letter' => 'C']);
    check('Admin reveals C -> Makiling with prize TV', ($r['body']['data']['winner'] ?? null) === ['fullName' => 'Makiling Santos', 'prizeLabel' => 'TV'], $r['raw']);

    section('Records, report and audit');
    $names = $operator->data('GET', '/predrawn/roll-names')['names'] ?? [];
    check('Roll names: a sample of this event\'s active attendees (archived excluded)', $names !== [] && count($names) <= 40
        && array_diff($names, array_values(array_diff($people, ['Archived Person']))) === [], $names);
    $sheet = $xlsxRows($admin, '/reports/predrawn-winners.xlsx?scope=day');
    $byStatus = array_count_values(array_map(static fn (array $row): string => $row['cells'][8] ?? '', $sheet['rows']));
    check('Pre-Drawn Winners.xlsx: header + every row labelled "Pre-Drawn Winner"', ($sheet['headers'][0] ?? '') === 'Category'
        && count($sheet['rows']) === 4 && array_unique(array_map(static fn (array $row): string => $row['cells'][0], $sheet['rows'])) === ['Pre-Drawn Winner'], $sheet);
    check('Report statuses: 3 REVEALED + 1 VOIDED', $byStatus == ['REVEALED' => 3, 'VOIDED' => 1], $byStatus);
    $winnersAfter = $xlsxRows($admin, '/reports/winners.xlsx?scope=day');
    check('Winners.xlsx (Minor / Major) unchanged: same columns, no pre-drawn rows', $winnersAfter['headers'] === $winnersBefore['headers'] && count($winnersAfter['rows']) === count($winnersBefore['rows']));
    $audit = $pdo->query("SELECT action, description FROM audit_logs WHERE event_id = {$eventId} AND action LIKE 'predrawn.%' ORDER BY id")->fetchAll();
    $actions = array_count_values(array_column($audit, 'action'));
    check('Audit: assigned / updated / removed / revealed (x4) / voided recorded', ($actions['predrawn.revealed'] ?? 0) === 4 && ($actions['predrawn.reveal_voided'] ?? 0) === 1
        && ($actions['predrawn.assignment_removed'] ?? 0) === 1 && ($actions['predrawn.assignment_updated'] ?? 0) === 1 && ($actions['predrawn.assigned'] ?? 0) === 5, $actions);
    $reveals = array_filter($audit, static fn (array $x): bool => $x['action'] === 'predrawn.revealed');
    check('Every reveal audit says "Pre-Drawn Winner" and "not a random draw"', array_filter($reveals, static fn (array $x): bool => !str_contains($x['description'], 'Pre-Drawn Winner') || !str_contains($x['description'], 'not a random draw')) === []);
    check('No randomizer_draws row was written', (int) $pdo->query("SELECT COUNT(*) FROM randomizer_draws WHERE event_id = {$eventId}")->fetchColumn() === $drawsBefore);
    check('Minor / Major eligible counts unchanged', ($operator->data('GET', '/randomizers/minor')['eligibleCount'] ?? null) === $minorBefore
        && ($operator->data('GET', '/randomizers/major')['eligibleCount'] ?? null) === $majorBefore);
    check('Attendees, QR tokens, registrations and manual pools unchanged', $snapshot() === $before);

    section('Normal Minor draw still works (and is independent)');
    $r = $operator->request('POST', '/randomizers/minor/participants', ['attendee_id' => $ids['anna'], 'reason' => 'Predrawn test']);
    check('Anna (a pre-drawn winner) can still be added to the Minor pool (201)', $r['status'] === 201, $r['raw']);
    $r = $operator->request('POST', '/randomizers/minor/draw');
    check('Minor random draw -> Anna (only eligible), recorded as a Minor draw', $r['status'] === 200 && ($r['body']['data']['winner']['fullName'] ?? '') === 'Anna Reyes', $r['raw']);
    $drawId = (int) ($r['body']['data']['drawId'] ?? 0);
    check('Minor draw void still works', $operator->request('POST', "/randomizers/draws/{$drawId}/void", ['reason' => 'test'])['status'] === 200);
    check('Pre-drawn rows untouched by the Minor draw (4 rows, 3 live revealed)', (int) $pdo->query("SELECT COUNT(*) FROM predrawn_winners WHERE event_id = {$eventId}")->fetchColumn() === 4
        && (int) $pdo->query("SELECT COUNT(*) FROM predrawn_winners WHERE event_id = {$eventId} AND voided_at IS NULL AND revealed_at IS NOT NULL")->fetchColumn() === 3);
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
