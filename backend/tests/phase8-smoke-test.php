<?php

declare(strict_types=1);

/**
 * Phase 8 multi-day smoke test: event days, day-specific registration,
 * Minor/Major pools, manual participants, draws, scanner operators,
 * permissions, password-reset sign-out and day-scoped reports.
 *
 *   HOLCIM_TEST_EMAIL=admin@example.com HOLCIM_TEST_PASSWORD='...' \
 *     php backend/tests/phase8-smoke-test.php http://localhost/Holcim/backend/api --write
 *
 * DEVELOPMENT DATABASE ONLY. It needs an admin account and --write, and it
 * writes real rows to the database in backend/.env (the same one the API
 * uses): a "Phase 8 Smoke ..." event with 2 days and 4 attendees, a second
 * draft event, scans, draws and four test accounts (random passwords, never
 * printed). While it runs, the currently active event is set to completed;
 * at the end it is set back to active, the smoke events are archived and the
 * test accounts are disabled. Refuses to run when APP_ENV=production.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/bootstrap.php';

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

final class ApiClient
{
    private string $cookieFile;
    private ?string $csrf = null;

    public function __construct(private readonly string $baseUrl)
    {
        $this->cookieFile = tempnam(sys_get_temp_dir(), 'holcim-p8-') ?: throw new RuntimeException('tempnam failed');
        $this->csrf = $this->request('GET', '/auth/session')['body']['data']['csrfToken'] ?? null;
    }

    public function __destruct()
    {
        @unlink($this->cookieFile);
    }

    /** @return array{status:int, body:array<string,mixed>|null, raw:string} */
    public function request(string $method, string $path, ?array $json = null): array
    {
        $headers = ['Accept: application/json'];
        if ($json !== null || in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            $headers[] = 'Content-Type: application/json';
            $json ??= [];
        }
        if ($this->csrf !== null) {
            $headers[] = 'X-CSRF-Token: ' . $this->csrf;
        }
        $handle = curl_init($this->baseUrl . $path);
        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_COOKIEJAR => $this->cookieFile,
            CURLOPT_COOKIEFILE => $this->cookieFile,
            CURLOPT_TIMEOUT => 20,
        ]);
        if ($json !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($json));
        }
        $raw = curl_exec($handle);
        if ($raw === false) {
            throw new RuntimeException('Request failed: ' . curl_error($handle));
        }
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $body = json_decode((string) $raw, true);

        return ['status' => $status, 'body' => is_array($body) ? $body : null, 'raw' => (string) $raw];
    }

    public function login(string $login, string $password): int
    {
        $response = $this->request('POST', '/auth/login', ['login' => $login, 'password' => $password]);
        if ($response['status'] === 200) {
            $this->csrf = $response['body']['data']['csrfToken'] ?? $this->csrf;
        }

        return $response['status'];
    }

    /** @return array<string, mixed> response data (empty on error) */
    public function data(string $method, string $path, ?array $json = null): array
    {
        return $this->request($method, $path, $json)['body']['data'] ?? [];
    }

    /** @return list<array<string, string>> CSV rows keyed by header */
    public function csv(string $path): array
    {
        $raw = $this->request('GET', $path)['raw'];
        $lines = preg_split('/\r\n|\n/', trim(preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? ''));
        $headers = str_getcsv((string) array_shift($lines), ',', '"', '');
        $rows = [];
        foreach ($lines as $line) {
            $cells = str_getcsv($line, ',', '"', '');
            if (count($cells) === count($headers)) {
                $rows[] = array_combine($headers, $cells);
            }
        }

        return $rows;
    }
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
/** @param list<array<string, mixed>> $items @return list<string> sorted codes */
function codes(array $items, string $key = 'attendeeCode'): array
{
    $codes = array_map(static fn (array $i): string => (string) $i[$key], $items);
    sort($codes);

    return $codes;
}

$pdo = Database::connection();
$stamp = date('His') . '-' . bin2hex(random_bytes(2));
$admin = new ApiClient($baseUrl);
if ($admin->login($adminEmail, $adminPassword) !== 200) {
    fwrite(STDERR, "Admin login failed.\n");
    exit(1);
}

$originalActive = $pdo->query("SELECT id FROM events WHERE status = 'active' LIMIT 1")->fetchColumn();
$originalActive = $originalActive !== false ? (int) $originalActive : null;
$eventId = null;
$otherEventId = null;
$testUserIds = [];

try {
    // ---------------------------------------------------------------- setup
    section('Setup');
    if ($originalActive !== null) {
        $admin->request('PATCH', "/events/{$originalActive}/status", ['status' => 'completed']);
    }
    $created = $admin->request('POST', '/events', ['name' => "Phase 8 Smoke {$stamp}", 'event_date' => '2030-03-01', 'status' => 'draft', 'description' => '']);
    check('Admin creates event (201)', $created['status'] === 201, $created['raw']);
    $eventId = (int) ($created['body']['data']['event']['id'] ?? 0);
    $days = $admin->data('GET', "/events/{$eventId}/days")['days'] ?? [];
    check('New event gets Day 1 automatically', count($days) === 1 && $days[0]['dayNumber'] === 1, $days);
    $day1 = (int) ($days[0]['id'] ?? 0);
    check('Activate event (200)', $admin->request('PATCH', "/events/{$eventId}/status", ['status' => 'active'])['status'] === 200);
    $current = $admin->data('GET', '/event-days/current');
    check('Activating the event makes Day 1 current', ($current['eventDay']['id'] ?? null) === $day1, $current);
    $added = $admin->request('POST', "/events/{$eventId}/days", ['event_date' => '2030-03-02', 'label' => '']);
    check('Admin adds Day 2 (201)', $added['status'] === 201, $added['raw']);
    $day2 = (int) ($added['body']['data']['day']['id'] ?? 0);

    // Attendees A, B, C (active, with QR), D (archived); X in another event.
    $tokens = [];
    $ids = [];
    foreach (['A' => 'Alpha Tester', 'B' => 'Bravo Tester', 'C' => 'Charlie Tester', 'D' => 'Delta Tester'] as $key => $name) {
        $ids[$key] = Attendee::create($eventId, "P8{$key}-{$stamp}", ['full_name' => $name, 'department' => 'QA', 'email' => strtolower($key) . "-{$stamp}@example.invalid"], null);
        AttendeeQrCode::create($ids[$key], $tokens[$key] = Token::random(24));
    }
    Attendee::setStatus($ids['D'], Attendee::STATUS_ARCHIVED);
    $codeOf = static fn (string $key): string => "P8{$key}-{$stamp}";
    $other = $admin->request('POST', '/events', ['name' => "Phase 8 Smoke {$stamp} (other)", 'event_date' => '2030-04-01', 'status' => 'draft', 'description' => '']);
    $otherEventId = (int) ($other['body']['data']['event']['id'] ?? 0);
    $ids['X'] = Attendee::create($otherEventId, "P8X-{$stamp}", ['full_name' => 'Xray Tester', 'department' => 'QA'], null);
    AttendeeQrCode::create($ids['X'], $tokens['X'] = Token::random(24));

    // Accounts: event operator + registration staff (direct, random passwords).
    $secret = [];
    foreach (['operator' => User::ROLE_EVENT_OPERATOR, 'staff' => User::ROLE_REGISTRATION_STAFF] as $key => $role) {
        $secret[$key] = bin2hex(random_bytes(12));
        $testUserIds[] = User::create("P8 {$key} {$stamp}", "p8-{$key}-{$stamp}@example.invalid", password_hash($secret[$key], PASSWORD_DEFAULT), $role);
    }
    $operator = new ApiClient($baseUrl);
    $staff = new ApiClient($baseUrl);
    check('Event operator logs in', $operator->login("p8-operator-{$stamp}@example.invalid", $secret['operator']) === 200);
    check('Registration staff logs in', $staff->login("p8-staff-{$stamp}@example.invalid", $secret['staff']) === 200);

    // ---------------------------------------------------- scanner operators
    section('Scanner Operator management');
    $sc1User = "p8-scan1-{$stamp}";
    $sc2User = "p8-scan2-{$stamp}";
    $secret['sc1'] = bin2hex(random_bytes(8));
    $secret['sc2'] = bin2hex(random_bytes(8));
    $make = static fn (string $name, string $username, string $pw, string $confirm) => $admin->request('POST', '/settings/scanner-operators', [
        'name' => $name, 'username' => $username, 'password' => $pw, 'password_confirmation' => $confirm, 'status' => 'active',
    ]);
    $r = $make('Gate 1', $sc1User, $secret['sc1'], $secret['sc1']);
    check('Admin creates Scanner Operator 1 (201)', $r['status'] === 201, $r['raw']);
    check('Response never contains a password or hash', !preg_match('/password|\$2y\$|\$argon/i', $r['raw']));
    $sc1Id = (int) ($r['body']['data']['operator']['id'] ?? 0);
    $r = $make('Gate 2', $sc2User, $secret['sc2'], $secret['sc2']);
    $sc2Id = (int) ($r['body']['data']['operator']['id'] ?? 0);
    $testUserIds[] = $sc1Id;
    $testUserIds[] = $sc2Id;
    check('Admin creates Scanner Operator 2 (201)', $r['status'] === 201);
    check('Duplicate username rejected (422)', $make('Dup', $sc1User, 'abcdefgh1', 'abcdefgh1')['status'] === 422);
    $r = $make('Bad', "p8-bad-{$stamp}", 'abcdefgh1', 'abcdefgh2');
    check('Password confirmation mismatch rejected (422)', $r['status'] === 422 && isset($r['body']['error']['details']['fields']['password_confirmation']));
    check('Short password rejected (422)', $make('Bad', "p8-bad-{$stamp}", 'short', 'short')['status'] === 422);
    $hash = (string) $pdo->query("SELECT password_hash FROM users WHERE id = {$sc1Id}")->fetchColumn();
    check('Password stored hashed', password_verify($secret['sc1'], $hash) && $hash !== $secret['sc1']);
    check('Registration staff cannot list scanner operators (403)', $staff->request('GET', '/settings/scanner-operators')['status'] === 403);
    $sc1 = new ApiClient($baseUrl);
    $sc2 = new ApiClient($baseUrl);
    check('Scanner Operator 1 logs in with username', $sc1->login($sc1User, $secret['sc1']) === 200);
    check('Scanner Operator 2 logs in with username', $sc2->login($sc2User, $secret['sc2']) === 200);
    check('Session role is scanner_operator', ($sc1->data('GET', '/auth/me')['user']['role'] ?? null) === 'scanner_operator');

    // ---------------------------------------------------------------- Day 1
    section('Day 1: registration and scanner counts');
    $scan = static fn (ApiClient $c, string $token) => $c->request('POST', '/registration/scan', ['token' => $token]);
    check('Day 1: scan A -> registered', ($scan($sc1, $tokens['A'])['body']['data']['status'] ?? null) === 'registered');
    check('Day 1: scan B -> registered', ($scan($sc1, $tokens['B'])['body']['data']['status'] ?? null) === 'registered');
    check('Day 1: scan A again -> already_registered', ($scan($sc1, $tokens['A'])['body']['data']['status'] ?? null) === 'already_registered');
    $r = $scan($sc1, 'NotARealTokenValue1234');
    check('Unknown QR -> 422 INVALID_QR', $r['status'] === 422 && ($r['body']['error']['code'] ?? '') === 'INVALID_QR');
    $r = $scan($sc1, $tokens['D']);
    check('Archived attendee -> 422 ATTENDEE_INACTIVE', $r['status'] === 422 && ($r['body']['error']['code'] ?? '') === 'ATTENDEE_INACTIVE');
    $r = $scan($sc1, $tokens['X']);
    check('Other event QR -> 422 WRONG_EVENT', $r['status'] === 422 && ($r['body']['error']['code'] ?? '') === 'WRONG_EVENT');
    $s = $sc1->data('GET', '/registration/summary');
    check('Day 1 summary is for Day 1', ($s['eventDay']['id'] ?? null) === $day1);
    check('Day 1: Registered = 2, Remaining = 1 (of 3 active)', ($s['counts'] ?? null) === ['total' => 3, 'registered' => 2, 'remaining' => 1], $s['counts'] ?? null);
    check('Day 1: Personal Scans = 2, General Scans = 2', ($s['scanCounts'] ?? null) === ['mine' => 2, 'all' => 2], $s['scanCounts'] ?? null);
    $page = static fn (string $view, string $result) => $sc1->data('GET', "/registration/scans?view={$view}&result={$result}");
    check('My Scans (all results) = 6 attempts', ($page('mine', 'all')['pagination']['total'] ?? null) === 6);
    check('Filter Successful = 2', ($page('mine', 'successful')['pagination']['total'] ?? null) === 2);
    check('Filter Already registered = 1', ($page('mine', 'already_registered')['pagination']['total'] ?? null) === 1);
    check('Filter Invalid = 3', ($page('mine', 'invalid')['pagination']['total'] ?? null) === 3);
    check('All Scans view = 6 (only scanner so far)', ($page('all', 'all')['pagination']['total'] ?? null) === 6);
    $lookup = $sc1->data('GET', '/registration/attendees?search=' . urlencode("-{$stamp}"))['items'] ?? [];
    $byCode = array_column($lookup, null, 'attendeeCode');
    check('Lookup: A registered today, C not', ($byCode[$codeOf('A')]['registeredToday'] ?? null) === true && ($byCode[$codeOf('C')]['registeredToday'] ?? null) === false);
    check('Lookup returns no email addresses', !str_contains(json_encode($lookup), '@example.invalid'));

    section('Day 1: Minor pool and manual add');
    $pool = static fn (string $type) => $operator->data('GET', "/randomizers/{$type}/participants");
    $p = $pool('minor');
    check('Day 1 Minor pool = A, B (registered)', codes($p['items'] ?? []) === [$codeOf('A'), $codeOf('B')], codes($p['items'] ?? []));
    check('C is not Minor eligible yet', !in_array($codeOf('C'), codes($p['items'] ?? []), true));
    $cand = array_column($operator->data('GET', '/randomizers/minor/candidates?search=' . urlencode("-{$stamp}"))['items'] ?? [], null, 'attendeeCode');
    check('Candidates: A already eligible, C not, archived D not listed', ($cand[$codeOf('A')]['alreadyEligible'] ?? null) === true
        && ($cand[$codeOf('C')]['alreadyEligible'] ?? null) === false && !isset($cand[$codeOf('D')]));
    $add = static fn (ApiClient $c, string $type, int $id) => $c->request('POST', "/randomizers/{$type}/participants", ['attendee_id' => $id, 'reason' => 'Smoke test']);
    $r = $add($operator, 'minor', $ids['C']);
    check('Manual add C to Minor (201, source manual)', $r['status'] === 201 && ($r['body']['data']['source'] ?? null) === 'manual', $r['raw']);
    $r = $add($operator, 'minor', $ids['C']);
    check('Manual add C again -> 409 ALREADY_ELIGIBLE', $r['status'] === 409 && ($r['body']['error']['code'] ?? '') === 'ALREADY_ELIGIBLE');
    check('Registered A -> 409 already eligible', $add($operator, 'minor', $ids['A'])['status'] === 409);
    check('Archived D -> 422', $add($operator, 'minor', $ids['D'])['status'] === 422);
    check('Other-event attendee -> 404', $add($operator, 'minor', $ids['X'])['status'] === 404);
    check('Registration staff cannot add participants (403)', $add($staff, 'minor', $ids['C'])['status'] === 403);
    check('Manual add created no check-in for C', (int) $pdo->query("SELECT COUNT(*) FROM registration_scans WHERE attendee_id = {$ids['C']}")->fetchColumn() === 0);
    $p = $pool('minor');
    check('Day 1 Minor pool = A, B, C', codes($p['items'] ?? []) === [$codeOf('A'), $codeOf('B'), $codeOf('C')]);
    $sources = array_column($p['items'] ?? [], 'source', 'attendeeCode');
    check('Sources: A/B registration, C manual', ($sources[$codeOf('C')] ?? '') === 'manual' && ($sources[$codeOf('A')] ?? '') === 'registration');
    check('Registration count still 2 after manual add', ($sc1->data('GET', '/registration/summary')['counts']['registered'] ?? null) === 2);

    section('Day 1: Major and draws');
    check('Manual add B to Major (201)', $add($operator, 'major', $ids['B'])['status'] === 201);
    check('Major source stored as manual', $pdo->query("SELECT source FROM major_eligibility WHERE event_day_id = {$day1} AND attendee_id = {$ids['B']}")->fetchColumn() === 'manual');
    check('Day 1 Major pool = B', codes($pool('major')['items'] ?? []) === [$codeOf('B')]);
    $day1Draws = ['minor' => [], 'major' => []];
    $drawOk = ['minor' => true, 'major' => true];
    $allowedDay1 = ['minor' => [$codeOf('A'), $codeOf('B'), $codeOf('C')], 'major' => [$codeOf('B')]];
    foreach (['minor', 'major'] as $type) {
        for ($i = 0; $i < 4; $i++) {
            $d = $operator->data('POST', "/randomizers/{$type}/draw");
            $day1Draws[$type][] = (int) ($d['drawId'] ?? 0);
            $drawOk[$type] = $drawOk[$type] && in_array($d['winner']['attendeeCode'] ?? '', $allowedDay1[$type], true);
        }
    }
    check('Day 1 Minor draws (4) only pick A/B/C', $drawOk['minor']);
    check('Day 1 Major draws (4) only pick B', $drawOk['major']);
    check('Void a Day 1 draw (200)', $operator->request('POST', "/randomizers/draws/{$day1Draws['minor'][0]}/void", ['reason' => 'Smoke'])['status'] === 200);

    // ---------------------------------------------------------------- Day 2
    section('Day 2: switch and isolation');
    check('Staff cannot change the current day (403)', $staff->request('PATCH', "/event-days/{$day2}/activate")['status'] === 403);
    check('Admin sets Day 2 as current (200)', $admin->request('PATCH', "/event-days/{$day2}/activate")['status'] === 200);
    $s = $sc1->data('GET', '/registration/summary');
    check('Day 2: Registered = 0', ($s['counts']['registered'] ?? null) === 0 && ($s['eventDay']['id'] ?? null) === $day2);
    check('Day 2: Personal Scans = 0, General Scans = 0', ($s['scanCounts'] ?? null) === ['mine' => 0, 'all' => 0], $s['scanCounts'] ?? null);
    check('Day 2: My Scans list empty', ($page('mine', 'all')['pagination']['total'] ?? null) === 0);
    check('Day 2 Minor pool empty', ($pool('minor')['eligibleCount'] ?? null) === 0);
    check('Day 2 Major pool empty (Day 1 Major does not carry over)', ($pool('major')['eligibleCount'] ?? null) === 0);
    check('Day 2 recent winners empty', ($operator->data('GET', '/randomizers/minor')['recentWinners'] ?? null) === []);
    check('Day 1 draw cannot be voided on Day 2 (404)', $operator->request('POST', "/randomizers/draws/{$day1Draws['minor'][1]}/void")['status'] === 404);
    check('Day 2: same QR registers A again', ($scan($sc1, $tokens['A'])['body']['data']['status'] ?? null) === 'registered');
    $s = $sc1->data('GET', '/registration/summary');
    check('Day 2 after 1 scan: Personal 1, General 1', ($s['scanCounts'] ?? null) === ['mine' => 1, 'all' => 1], $s['scanCounts'] ?? null);
    check('Day 2: Scanner 2 registers C', ($scan($sc2, $tokens['C'])['body']['data']['status'] ?? null) === 'registered');
    $s = $sc1->data('GET', '/registration/summary');
    check('Day 2: Scanner 1 Personal 1, General 2', ($s['scanCounts'] ?? null) === ['mine' => 1, 'all' => 2], $s['scanCounts'] ?? null);
    check('Day 2: Scanner 2 Personal 1', ($sc2->data('GET', '/registration/summary')['scanCounts']['mine'] ?? null) === 1);
    $lookup = array_column($sc1->data('GET', '/registration/attendees?search=' . urlencode("-{$stamp}"))['items'] ?? [], null, 'attendeeCode');
    check('Day 2 lookup: A and C registered, B not', ($lookup[$codeOf('A')]['registeredToday'] ?? null) === true
        && ($lookup[$codeOf('C')]['registeredToday'] ?? null) === true && ($lookup[$codeOf('B')]['registeredToday'] ?? null) === false);
    $p = $pool('minor');
    check('Day 2 Minor pool = A, C (registration only)', codes($p['items'] ?? []) === [$codeOf('A'), $codeOf('C')]);
    check('Day 2: C source is registration (Day 1 manual entry not reused)', (array_column($p['items'] ?? [], 'source', 'attendeeCode')[$codeOf('C')] ?? '') === 'registration');
    check('Day 2: manual add A to Major (201)', $add($operator, 'major', $ids['A'])['status'] === 201);
    check('Day 2 Major pool = A only (not Day 1\'s B)', codes($pool('major')['items'] ?? []) === [$codeOf('A')]);
    check('Day 2 Major row stored for Day 2 only', (int) $pdo->query("SELECT COUNT(*) FROM major_eligibility WHERE attendee_id = {$ids['A']} AND event_day_id = {$day1}")->fetchColumn() === 0);
    $allowedDay2 = ['minor' => [$codeOf('A'), $codeOf('C')], 'major' => [$codeOf('A')]];
    $day2Draws = ['minor' => [], 'major' => []];
    $drawOk = ['minor' => true, 'major' => true];
    foreach (['minor', 'major'] as $type) {
        for ($i = 0; $i < 4; $i++) {
            $d = $operator->data('POST', "/randomizers/{$type}/draw");
            $day2Draws[$type][] = (int) ($d['drawId'] ?? 0);
            $drawOk[$type] = $drawOk[$type] && in_array($d['winner']['attendeeCode'] ?? '', $allowedDay2[$type], true);
        }
    }
    check('Day 2 Minor draws (4) only pick A/C (never B)', $drawOk['minor']);
    check('Day 2 Major draws (4) only pick A (never B)', $drawOk['major']);
    check('Day 2 draws stored with Day 2', (int) $pdo->query('SELECT COUNT(*) FROM randomizer_draws WHERE event_day_id = ' . $day2 . ' AND id IN (' . implode(',', array_merge(...array_values($day2Draws))) . ')')->fetchColumn() === 8);

    section('Day 2: day-scoped reports');
    $reg = $admin->csv('/reports/registration.csv?scope=day');
    $regStatus = array_column(array_filter($reg, static fn ($r) => str_contains($r['Attendee Code'] ?? '', $stamp)), 'Registration Status', 'Attendee Code');
    check('Registration (day): every row is Day 2', $reg !== [] && count(array_unique(array_column($reg, 'Event Day'))) === 1 && str_starts_with($reg[0]['Event Day'], 'Day 2 '));
    check('Registration (day): A, C registered; B not', ($regStatus[$codeOf('A')] ?? '') === 'Registered' && ($regStatus[$codeOf('C')] ?? '') === 'Registered' && ($regStatus[$codeOf('B')] ?? '') === 'Not registered');
    $maj = array_column($admin->csv('/reports/major-eligibility.csv?scope=day'), null, 'Attendee Code');
    check('Major (day): A Yes / Manual, B No', ($maj[$codeOf('A')]['Major Eligible'] ?? '') === 'Yes' && ($maj[$codeOf('A')]['Eligibility Source'] ?? '') === 'Manual' && ($maj[$codeOf('B')]['Major Eligible'] ?? '') === 'No');
    $dr = $admin->csv('/reports/draws.csv?scope=day');
    check('Draws (day): 8 rows, all Day 2', count($dr) === 8 && count(array_unique(array_column($dr, 'Event Day'))) === 1);

    // ------------------------------------------------------- back to Day 1
    section('Back to Day 1: nothing overwritten');
    check('Admin sets Day 1 as current again (200)', $admin->request('PATCH', "/event-days/{$day1}/activate")['status'] === 200);
    $list = array_column($admin->data('GET', "/events/{$eventId}/days")['days'] ?? [], 'status', 'dayNumber');
    check('Day statuses: Day 1 active, Day 2 upcoming', $list === [1 => 'active', 2 => 'upcoming'], $list);
    $s = $sc1->data('GET', '/registration/summary');
    check('Day 1: Registered = 2 (A, B)', ($s['counts']['registered'] ?? null) === 2);
    check('Day 1: Personal Scans = 2, General Scans = 2', ($s['scanCounts'] ?? null) === ['mine' => 2, 'all' => 2], $s['scanCounts'] ?? null);
    check('Day 1 scan log intact (6 attempts)', ($page('mine', 'all')['pagination']['total'] ?? null) === 6);
    $p = $pool('minor');
    check('Day 1 Minor pool = A, B, C', codes($p['items'] ?? []) === [$codeOf('A'), $codeOf('B'), $codeOf('C')]);
    check('Day 1: C still manual', (array_column($p['items'] ?? [], 'source', 'attendeeCode')[$codeOf('C')] ?? '') === 'manual');
    check('Day 1 Major pool = B', codes($pool('major')['items'] ?? []) === [$codeOf('B')]);
    $metrics = $admin->data('GET', '/dashboard/summary')['metrics'] ?? [];
    check('Dashboard (Day 1): registered 2, Minor 3, Major 1', ($metrics['registered']['value'] ?? null) === 2
        && ($metrics['minorEligible']['value'] ?? null) === 3 && ($metrics['majorEligible']['value'] ?? null) === 1, $metrics);
    $recent = $operator->data('GET', '/randomizers/minor')['recentWinners'] ?? [];
    check('Day 1 Minor history = 4 draws, first one VOID', count($recent) === 4 && ($recent[3]['status'] ?? '') === 'void' && ($recent[3]['drawId'] ?? 0) === $day1Draws['minor'][0]);
    check('Day 1 Major history = 4 draws', count($operator->data('GET', '/randomizers/major')['recentWinners'] ?? []) === 4);
    $reg = $admin->csv('/reports/registration.csv?scope=day');
    $regStatus = array_column(array_filter($reg, static fn ($r) => str_contains($r['Attendee Code'] ?? '', $stamp)), 'Registration Status', 'Attendee Code');
    check('Registration (day 1): A, B registered; C not; Day 1 only', ($regStatus[$codeOf('A')] ?? '') === 'Registered' && ($regStatus[$codeOf('B')] ?? '') === 'Registered'
        && ($regStatus[$codeOf('C')] ?? '') === 'Not registered' && count(array_unique(array_column($reg, 'Event Day'))) === 1 && str_starts_with($reg[0]['Event Day'], 'Day 1 '));
    $maj = array_column($admin->csv('/reports/major-eligibility.csv?scope=day'), null, 'Attendee Code');
    check('Major (day 1): B Yes, A No', ($maj[$codeOf('B')]['Major Eligible'] ?? '') === 'Yes' && ($maj[$codeOf('A')]['Major Eligible'] ?? '') === 'No');
    $dr = $admin->csv('/reports/draws.csv?scope=day');
    check('Draws (day 1): 8 rows incl. 1 VOID', count($dr) === 8 && count(array_filter($dr, static fn ($r) => $r['Status'] === 'VOID')) === 1);
    $all = $admin->csv('/reports/registration.csv?scope=all');
    check('Registration (all days): 2 rows per attendee with Event Day', count(array_filter($all, static fn ($r) => ($r['Attendee Code'] ?? '') === $codeOf('A'))) === 2
        && count(array_unique(array_column($all, 'Event Day'))) === 2);
    check('Draws (all days): 16 rows over 2 days', count($admin->csv('/reports/draws.csv?scope=all')) === 16);
    check('Major (all days): A Yes on Day 2 only', count(array_filter($admin->csv('/reports/major-eligibility.csv?scope=all'),
        static fn ($r) => $r['Attendee Code'] === $codeOf('A') && $r['Major Eligible'] === 'Yes' && str_starts_with($r['Event Day'], 'Day 2 '))) === 1);

    section('Back to Day 2: still intact');
    $admin->request('PATCH', "/event-days/{$day2}/activate");
    $s = $sc1->data('GET', '/registration/summary');
    check('Day 2: Registered = 2 (A, C), Personal 1, General 2', ($s['counts']['registered'] ?? null) === 2 && ($s['scanCounts'] ?? null) === ['mine' => 1, 'all' => 2]);
    check('Day 2 Minor pool = A, C', codes($pool('minor')['items'] ?? []) === [$codeOf('A'), $codeOf('C')]);
    check('Day 2 Major pool = A (manual)', codes($pool('major')['items'] ?? []) === [$codeOf('A')]);
    check('Day 2 Minor history = 4 draws', count($operator->data('GET', '/randomizers/minor')['recentWinners'] ?? []) === 4);
    // An attendee both added manually and registered the same day counts once.
    check('Day 2: manual add B to Minor (201)', $add($operator, 'minor', $ids['B'])['status'] === 201);
    check('Day 2: B then registers at the entrance', ($scan($admin, $tokens['B'])['body']['data']['status'] ?? null) === 'registered');
    $metrics = $admin->data('GET', '/dashboard/summary')['metrics'] ?? [];
    check('Dashboard (Day 2): registered 3, Minor 3 (B counted once), Major 1', ($metrics['registered']['value'] ?? null) === 3
        && ($metrics['minorEligible']['value'] ?? null) === 3 && ($metrics['majorEligible']['value'] ?? null) === 1, $metrics);
    check('Day 2 Minor pool lists B once (source registration)', codes($pool('minor')['items'] ?? []) === [$codeOf('A'), $codeOf('B'), $codeOf('C')]);

    // ---------------------------------------------------------- permissions
    section('Scanner Operator permissions');
    $allowed = [
        ['GET', '/registration/summary'], ['GET', '/registration/scans?view=all'], ['GET', '/registration/attendees?search=P8'], ['GET', '/event-days/current'],
    ];
    foreach ($allowed as [$m, $p2]) {
        check("Scanner can {$m} {$p2}", $sc1->request($m, $p2)['status'] === 200);
    }
    $forbidden = [
        ['GET', '/settings/scanner-operators'], ['POST', '/settings/scanner-operators'], ['PATCH', "/settings/scanner-operators/{$sc2Id}"],
        ['POST', "/settings/scanner-operators/{$sc2Id}/password"], ['GET', "/events/{$eventId}/days"], ['POST', "/events/{$eventId}/days"],
        ['PUT', "/event-days/{$day1}"], ['PATCH', "/event-days/{$day1}/activate"], ['GET', '/events'], ['POST', '/events'],
        ['PATCH', "/events/{$eventId}/status"], ['GET', '/attendees'], ['PUT', "/attendees/{$ids['A']}"], ['PATCH', "/attendees/{$ids['A']}/status"],
        ['POST', '/attendees/import/parse'], ['POST', '/attendees/import/preview'], ['POST', '/attendees/import'],
        ['POST', '/major-eligibility/import/parse'], ['POST', '/major-eligibility/import/preview'], ['POST', '/major-eligibility/import'],
        ['GET', '/major-eligibility'], ['GET', '/reports/registration.csv'], ['GET', '/reports/major-eligibility.csv'], ['GET', '/reports/draws.csv'],
        ['GET', '/randomizers/minor'], ['POST', '/randomizers/minor/draw'], ['GET', '/randomizers/major'], ['POST', '/randomizers/major/draw'],
        ['POST', '/randomizers/minor/participants'], ['POST', '/randomizers/major/participants'], ['GET', '/randomizers/minor/candidates'],
        ['POST', "/randomizers/draws/{$day2Draws['minor'][0]}/void"], ['GET', '/qr-codes'], ['POST', '/qr-codes/generate-missing'], ['GET', '/dashboard/summary'],
    ];
    foreach ($forbidden as [$m, $p2]) {
        $code = $sc1->request($m, $p2)['status'];
        check("Scanner blocked: {$m} {$p2} (403)", $code === 403, (string) $code);
    }

    // ------------------------------------------------------- password reset
    section('Password reset and disable sign-out');
    check('Scanner 1 session active before reset', $sc1->request('GET', '/registration/summary')['status'] === 200);
    $newPassword = bin2hex(random_bytes(8));
    $r = $admin->request('POST', "/settings/scanner-operators/{$sc1Id}/password", ['password' => $newPassword, 'password_confirmation' => $newPassword]);
    check('Admin resets Scanner 1 password (200)', $r['status'] === 200);
    check('Existing Scanner 1 session rejected (401)', $sc1->request('GET', '/registration/summary')['status'] === 401);
    check('Existing Scanner 1 session cannot scan (401)', $scan($sc1, $tokens['B'])['status'] === 401);
    check('Old password rejected (401)', (new ApiClient($baseUrl))->login($sc1User, $secret['sc1']) === 401);
    check('New password works (200)', (new ApiClient($baseUrl))->login($sc1User, $newPassword) === 200);
    check('Scanner 2 session unaffected', $sc2->request('GET', '/registration/summary')['status'] === 200);
    check('Admin session unaffected', $admin->request('GET', '/settings/scanner-operators')['status'] === 200);
    check('Admin cannot reset an admin via scanner settings (404)', $admin->request('PATCH', '/settings/scanner-operators/' . (int) $admin->data('GET', '/auth/me')['user']['id'], ['status' => 'inactive'])['status'] === 404);
    check('Admin disables Scanner 2 (200)', $admin->request('PATCH', "/settings/scanner-operators/{$sc2Id}", ['status' => 'inactive'])['status'] === 200);
    check('Disabled Scanner 2 session rejected (401)', $sc2->request('GET', '/registration/summary')['status'] === 401);
    check('Disabled Scanner 2 cannot log in (401)', (new ApiClient($baseUrl))->login($sc2User, $secret['sc2']) === 401);
} finally {
    section('Cleanup');
    foreach (array_filter([$eventId, $otherEventId]) as $id) {
        $admin->request('PATCH', "/events/{$id}/status", ['status' => 'archived']);
    }
    if ($originalActive !== null) {
        $restored = $admin->request('PATCH', "/events/{$originalActive}/status", ['status' => 'active'])['status'] === 200;
        echo $restored ? "  Restored the previously active event.\n" : "  WARNING: could not restore event #{$originalActive} to active.\n";
    }
    foreach (array_filter($testUserIds) as $userId) {
        User::setStatus((int) $userId, User::STATUS_INACTIVE);
    }
    echo "  Smoke events archived; test accounts disabled.\n";
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
