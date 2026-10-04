<?php

declare(strict_types=1);

/**
 * Phase 8 / 9.2 multi-day smoke test: event days, day-specific registration,
 * Minor/Major pools from registration, no-repeat winners per randomizer,
 * voids, manual participants, Excel exports, scanner operators,
 * permissions, password-reset sign-out and day-scoped reports.
 *
 *   HOLCIM_TEST_EMAIL=admin@example.com HOLCIM_TEST_PASSWORD='...' \
 *     php backend/tests/phase8-smoke-test.php http://localhost/Holcim/backend/api --write
 *
 * DEVELOPMENT DATABASE ONLY. It needs an admin account and --write, and it
 * writes real rows to the database in backend/.env (the same one the API
 * uses): a "Phase 8 Smoke ..." event with 2 days and 5 attendees, a second
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
        $handle = $this->handle($method, $path, $json);
        $raw = curl_exec($handle);
        if ($raw === false) {
            throw new RuntimeException('Request failed: ' . curl_error($handle));
        }
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $body = json_decode((string) $raw, true);

        return ['status' => $status, 'body' => is_array($body) ? $body : null, 'raw' => (string) $raw];
    }

    /**
     * Sends requests at the same moment (curl_multi), each with its own
     * client's session; optional 4th element = JSON body. @param list<array{0: ApiClient, 1: string, 2: string, 3?: array<string, mixed>}> $requests
     * @return list<array{status:int, body:array<string,mixed>|null, raw:string}>
     */
    public static function parallel(array $requests): array
    {
        $multi = curl_multi_init();
        $handles = [];
        foreach ($requests as $spec) {
            [$client, $method, $path] = $spec;
            $handle = $client->handle($method, $path, $spec[3] ?? []);
            curl_multi_add_handle($multi, $handle);
            $handles[] = $handle;
        }
        do {
            curl_multi_exec($multi, $running);
            curl_multi_select($multi);
        } while ($running > 0);
        $results = [];
        foreach ($handles as $handle) {
            $raw = (string) curl_multi_getcontent($handle);
            $body = json_decode($raw, true);
            $results[] = ['status' => (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), 'body' => is_array($body) ? $body : null, 'raw' => $raw];
            curl_multi_remove_handle($multi, $handle);
        }
        curl_multi_close($multi);

        return $results;
    }

    /** Swap the CSRF token (null = send none); returns the previous one. */
    public function swapCsrf(?string $token): ?string
    {
        [$previous, $this->csrf] = [$this->csrf, $token];

        return $previous;
    }

    public function login(string $login, string $password): int
    {
        $response = $this->request('POST', '/auth/login', ['login' => $login, 'password' => $password]);
        if ($response['status'] === 200) {
            $this->csrf = $response['body']['data']['csrfToken'] ?? $this->csrf;
        }

        return $response['status'];
    }

    /** @return \CurlHandle a configured request (not sent) */
    public function handle(string $method, string $path, ?array $json): \CurlHandle
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

        return $handle;
    }

    /** multipart/form-data upload of one file as "file"; returns the raw response. */
    public function upload(string $path, string $file, string $filename): string
    {
        $handle = curl_init($this->baseUrl . $path);
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'X-CSRF-Token: ' . $this->csrf],
            CURLOPT_COOKIEJAR => $this->cookieFile,
            CURLOPT_COOKIEFILE => $this->cookieFile,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_POSTFIELDS => ['file' => new \CURLFile($file, 'application/octet-stream', $filename)],
        ]);

        return (string) curl_exec($handle);
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
    foreach (['A' => 'Alpha Tester', 'B' => 'Bravo Tester', 'C' => 'Charlie Tester', 'D' => 'Delta Tester', 'E' => 'Echo Tester'] as $key => $name) {
        $ids[$key] = Attendee::create($eventId, "P8{$key}-{$stamp}", ['full_name' => $name, 'company' => 'Smoke Co', 'department' => 'QA', 'email' => strtolower($key) . "-{$stamp}@example.invalid"], null);
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
    check('Day 1: Registered = 2, Remaining = 2 (of 4 active)', ($s['counts'] ?? null) === ['total' => 4, 'registered' => 2, 'remaining' => 2], $s['counts'] ?? null);
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

    section('Day 1: Phase 9.2 raffle rules (registration -> Minor + Major, no repeat winners)');
    $poolCodes = static fn (string $type): array => codes($pool($type)['items'] ?? []);
    $count = static fn (string $type): ?int => $pool($type)['eligibleCount'] ?? null;
    check('Day 1 Major pool = A, B (registered; no import or form needed)', $poolCodes('major') === [$codeOf('A'), $codeOf('B')], $poolCodes('major'));
    check('Manual Minor add (C) does not enter the Major pool', !in_array($codeOf('C'), $poolCodes('major'), true));
    $draw = static fn (ApiClient $c, string $type): array => $c->request('POST', "/randomizers/{$type}/draw");
    $day1Draws = ['minor' => [], 'major' => []];
    $winners = ['minor' => [], 'major' => []];
    $poolSizes = [];
    for ($i = 0; $i < 3; $i++) {
        $d = $draw($operator, 'minor')['body']['data'] ?? [];
        $day1Draws['minor'][] = (int) ($d['drawId'] ?? 0);
        $winners['minor'][] = $d['winner']['attendeeCode'] ?? '';
        $poolSizes[] = $count('minor');
    }
    $sortedMinor = $winners['minor'];
    sort($sortedMinor);
    check('3 Minor draws pick A, B, C once each (no repeat winner)', $sortedMinor === [$codeOf('A'), $codeOf('B'), $codeOf('C')], $winners['minor']);
    check('Minor pool shrinks 2 -> 1 -> 0 after each win', $poolSizes === [2, 1, 0], $poolSizes);
    $r = $draw($operator, 'minor');
    check('4th Minor draw -> 409 NO_ELIGIBLE_ATTENDEES (server-side exclusion)', $r['status'] === 409 && ($r['body']['error']['code'] ?? '') === 'NO_ELIGIBLE_ATTENDEES');
    check('Minor winners stay in the Major pool (A, B)', $poolCodes('major') === [$codeOf('A'), $codeOf('B')]);
    for ($i = 0; $i < 2; $i++) {
        $d = $draw($operator, 'major')['body']['data'] ?? [];
        $day1Draws['major'][] = (int) ($d['drawId'] ?? 0);
        $winners['major'][] = $d['winner']['attendeeCode'] ?? '';
    }
    $sortedMajor = $winners['major'];
    sort($sortedMajor);
    check('2 Major draws pick A and B once each (Minor winners can win Major)', $sortedMajor === [$codeOf('A'), $codeOf('B')], $winners['major']);
    check('3rd Major draw -> 409 (A and B already won Major)', $draw($operator, 'major')['status'] === 409);
    check('Major wins do not change the Minor pool (still 0)', $count('minor') === 0);
    $cand = array_column($operator->data('GET', '/randomizers/minor/candidates?search=' . urlencode("-{$stamp}"))['items'] ?? [], null, 'attendeeCode');
    check('Candidates show alreadyWon for Minor winners', ($cand[$codeOf('A')]['alreadyWon'] ?? null) === true && ($cand[$codeOf('E')]['alreadyWon'] ?? null) === false);
    $r = $add($operator, 'minor', $ids['A']);
    check('Manual add of a Minor winner -> 409 ALREADY_WON', $r['status'] === 409 && ($r['body']['error']['code'] ?? '') === 'ALREADY_WON');
    $voidedCode = $winners['minor'][0];
    check('Void the first Minor draw (200)', $operator->request('POST', "/randomizers/draws/{$day1Draws['minor'][0]}/void", ['reason' => 'Smoke'])['status'] === 200);
    check('Void returns that attendee to the Minor pool only', $poolCodes('minor') === [$voidedCode] && $count('major') === 0);
    $d = $draw($operator, 'minor')['body']['data'] ?? [];
    $day1Draws['minor'][] = (int) ($d['drawId'] ?? 0);
    check('Voided attendee can win Minor again', ($d['winner']['attendeeCode'] ?? '') === $voidedCode);
    check('Manual add E to Major (201)', $add($operator, 'major', $ids['E'])['status'] === 201);
    check('Major source stored as manual', $pdo->query("SELECT source FROM major_eligibility WHERE event_day_id = {$day1} AND attendee_id = {$ids['E']}")->fetchColumn() === 'manual');
    check('Manual Major add: E in Major pool, not in Minor pool', $poolCodes('major') === [$codeOf('E')] && $poolCodes('minor') === []);
    check('No attendee has two valid wins of the same randomizer on Day 1', (int) $pdo->query(
        "SELECT COUNT(*) FROM (SELECT attendee_id, randomizer_type FROM randomizer_draws WHERE event_day_id = {$day1} AND voided_at IS NULL
         GROUP BY attendee_id, randomizer_type HAVING COUNT(*) > 1) x"
    )->fetchColumn() === 0);

    // ---------------------------------------------------------------- Day 2
    section('Day 2: switch and isolation');
    check('Staff cannot change the current day (403)', $staff->request('PATCH', "/event-days/{$day2}/activate")['status'] === 403);
    check('Admin sets Day 2 as current (200)', $admin->request('PATCH', "/event-days/{$day2}/activate")['status'] === 200);
    $s = $sc1->data('GET', '/registration/summary');
    check('Day 2: Registered = 0', ($s['counts']['registered'] ?? null) === 0 && ($s['eventDay']['id'] ?? null) === $day2);
    check('Day 2: Personal Scans = 0, General Scans = 0', ($s['scanCounts'] ?? null) === ['mine' => 0, 'all' => 0], $s['scanCounts'] ?? null);
    check('Day 2: My Scans list empty', ($page('mine', 'all')['pagination']['total'] ?? null) === 0);
    check('Day 2 Minor pool empty', $count('minor') === 0);
    check('Day 2 Major pool empty (Day 1 eligibility does not carry over)', $count('major') === 0);
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
    check('Day 2 Minor pool = A, C (Day 1 Minor winners not excluded on Day 2)', codes($p['items'] ?? []) === [$codeOf('A'), $codeOf('C')]);
    check('Day 2: C source is registration (Day 1 manual entry not reused)', (array_column($p['items'] ?? [], 'source', 'attendeeCode')[$codeOf('C')] ?? '') === 'registration');
    check('Day 2 Major pool = A, C (Day 1 Major winner A eligible again)', $poolCodes('major') === [$codeOf('A'), $codeOf('C')]);
    check('Day 2: no Major row for E (Day 1 manual add stays on Day 1)', (int) $pdo->query("SELECT COUNT(*) FROM major_eligibility WHERE attendee_id = {$ids['E']} AND event_day_id = {$day2}")->fetchColumn() === 0);
    // Two Major draws at the same moment from two sessions must not pick the same winner.
    $parallel = ApiClient::parallel([[$operator, 'POST', '/randomizers/major/draw'], [$admin, 'POST', '/randomizers/major/draw']]);
    $pw = array_map(static fn (array $r): string => $r['body']['data']['winner']['attendeeCode'] ?? '', $parallel);
    sort($pw);
    check('Simultaneous Major draws give two different winners (A, C)', $pw === [$codeOf('A'), $codeOf('C')], $pw);
    $day2Draws = ['minor' => [], 'major' => array_map(static fn (array $r): int => (int) ($r['body']['data']['drawId'] ?? 0), $parallel)];
    check('3rd Day 2 Major draw -> 409', $draw($operator, 'major')['status'] === 409);
    $d = $draw($operator, 'minor')['body']['data'] ?? [];
    $day2Draws['minor'][] = (int) ($d['drawId'] ?? 0);
    check('Day 2 Minor draw picks A or C (A can win Minor again on a new day)', in_array($d['winner']['attendeeCode'] ?? '', [$codeOf('A'), $codeOf('C')], true));
    check('Day 2 draws stored with Day 2', (int) $pdo->query('SELECT COUNT(*) FROM randomizer_draws WHERE event_day_id = ' . $day2 . ' AND id IN (' . implode(',', array_merge(...array_values($day2Draws))) . ')')->fetchColumn() === 3);

    section('Day 2: day-scoped reports');
    $reg = $admin->csv('/reports/registration.csv?scope=day');
    $regStatus = array_column(array_filter($reg, static fn ($r) => str_contains($r['Attendee Code'] ?? '', $stamp)), 'Registration Status', 'Attendee Code');
    check('Registration (day): every row is Day 2', $reg !== [] && count(array_unique(array_column($reg, 'Event Day'))) === 1 && str_starts_with($reg[0]['Event Day'], 'Day 2 '));
    check('Registration (day): A, C registered; B not; Cluster column', ($regStatus[$codeOf('A')] ?? '') === 'Registered' && ($regStatus[$codeOf('C')] ?? '') === 'Registered'
        && ($regStatus[$codeOf('B')] ?? '') === 'Not registered' && array_key_exists('Cluster', $reg[0]));
    $el = array_column($admin->csv('/reports/eligibility.csv?scope=day'), null, 'Attendee Code');
    check('Eligibility (day): A Minor+Major Yes via Registration, B No', ($el[$codeOf('A')]['Minor Eligible'] ?? '') === 'Yes' && ($el[$codeOf('A')]['Major Eligible'] ?? '') === 'Yes'
        && ($el[$codeOf('A')]['Major Source'] ?? '') === 'Registration' && ($el[$codeOf('A')]['Won Major'] ?? '') === 'Yes' && ($el[$codeOf('B')]['Major Eligible'] ?? '') === 'No');
    $dr = $admin->csv('/reports/draws.csv?scope=day');
    check('Draws (day): 3 rows, all Day 2', count($dr) === 3 && count(array_unique(array_column($dr, 'Event Day'))) === 1);

    // ------------------------------------------------------- back to Day 1
    section('Back to Day 1: nothing overwritten');
    check('Admin sets Day 1 as current again (200)', $admin->request('PATCH', "/event-days/{$day1}/activate")['status'] === 200);
    $list = array_column($admin->data('GET', "/events/{$eventId}/days")['days'] ?? [], 'status', 'dayNumber');
    check('Day statuses: Day 1 active, Day 2 upcoming', $list === [1 => 'active', 2 => 'upcoming'], $list);
    $s = $sc1->data('GET', '/registration/summary');
    check('Day 1: Registered = 2 (A, B)', ($s['counts']['registered'] ?? null) === 2);
    check('Day 1: Personal Scans = 2, General Scans = 2', ($s['scanCounts'] ?? null) === ['mine' => 2, 'all' => 2], $s['scanCounts'] ?? null);
    check('Day 1 scan log intact (6 attempts)', ($page('mine', 'all')['pagination']['total'] ?? null) === 6);
    check('Day 1 Minor pool still empty (all of A, B, C won Minor)', $count('minor') === 0);
    check('Day 1 Major pool = E (manual)', $poolCodes('major') === [$codeOf('E')]);
    $metrics = $admin->data('GET', '/dashboard/summary')['metrics'] ?? [];
    check('Dashboard (Day 1): registered 2, Minor 0, Major 1', ($metrics['registered']['value'] ?? null) === 2
        && ($metrics['minorEligible']['value'] ?? null) === 0 && ($metrics['majorEligible']['value'] ?? null) === 1, $metrics);
    $recent = $operator->data('GET', '/randomizers/minor')['recentWinners'] ?? [];
    check('Day 1 Minor history = 4 draws, the first one VOID', count($recent) === 4 && ($recent[3]['status'] ?? '') === 'void' && ($recent[3]['drawId'] ?? 0) === $day1Draws['minor'][0]);
    check('Day 1 Major history = 2 draws', count($operator->data('GET', '/randomizers/major')['recentWinners'] ?? []) === 2);
    $reg = $admin->csv('/reports/registration.csv?scope=day');
    $regStatus = array_column(array_filter($reg, static fn ($r) => str_contains($r['Attendee Code'] ?? '', $stamp)), 'Registration Status', 'Attendee Code');
    check('Registration (day 1): A, B registered; C not; Day 1 only', ($regStatus[$codeOf('A')] ?? '') === 'Registered' && ($regStatus[$codeOf('B')] ?? '') === 'Registered'
        && ($regStatus[$codeOf('C')] ?? '') === 'Not registered' && count(array_unique(array_column($reg, 'Event Day'))) === 1 && str_starts_with($reg[0]['Event Day'], 'Day 1 '));
    $el = array_column($admin->csv('/reports/eligibility.csv?scope=day'), null, 'Attendee Code');
    check('Eligibility (day 1): C Minor via Manual (not Major), E Major via Manual (not Minor)', ($el[$codeOf('C')]['Minor Source'] ?? '') === 'Manual'
        && ($el[$codeOf('C')]['Major Eligible'] ?? '') === 'No' && ($el[$codeOf('E')]['Major Source'] ?? '') === 'Manual' && ($el[$codeOf('E')]['Minor Eligible'] ?? '') === 'No');
    $dr = $admin->csv('/reports/draws.csv?scope=day');
    check('Draws (day 1): 6 rows incl. 1 VOID', count($dr) === 6 && count(array_filter($dr, static fn ($r) => $r['Status'] === 'VOID')) === 1);
    $all = $admin->csv('/reports/registration.csv?scope=all');
    check('Registration (all days): 2 rows per attendee with Event Day', count(array_filter($all, static fn ($r) => ($r['Attendee Code'] ?? '') === $codeOf('A'))) === 2
        && count(array_unique(array_column($all, 'Event Day'))) === 2);
    check('Draws (all days): 9 rows over 2 days', count($admin->csv('/reports/draws.csv?scope=all')) === 9);

    section('Excel exports');
    $sheet = static function (ApiClient $c, string $path): array {
        $r = $c->request('GET', $path);
        $file = tempnam(sys_get_temp_dir(), 'p92-');
        file_put_contents($file, $r['raw']);
        $zip = new ZipArchive();
        $rows = [];
        if ($zip->open($file) === true) {
            $xml = simplexml_load_string((string) $zip->getFromName('xl/worksheets/sheet1.xml'));
            foreach ($xml->sheetData->row ?? [] as $row) {
                $cells = [];
                foreach ($row->c as $c) {
                    preg_match('/^([A-Z]+)/', (string) $c['r'], $m);
                    $cells[$m[1]] = (string) $c->is->t;
                }
                $rows[] = $cells;
            }
            $zip->close();
        }
        @unlink($file);
        $headers = array_shift($rows) ?? [];

        return ['status' => $r['status'], 'raw' => $r['raw'], 'headers' => array_values($headers),
            'rows' => array_map(static fn (array $cells): array => array_combine(array_values($headers), array_map(static fn ($col) => $cells[$col] ?? '', array_keys($headers))), $rows)];
    };
    $x1 = $sheet($admin, "/reports/day-attendees.xlsx?day_id={$day1}");
    check('Day 1 Attendees.xlsx: headers', $x1['headers'] === ['Attendee Code', 'Full Name', 'Company', 'Cluster', 'Employee ID', 'Email', 'Registration Status', 'Registered At'], $x1['headers']);
    $st1 = array_column($x1['rows'], 'Registration Status', 'Attendee Code');
    check('Day 1 Attendees.xlsx: 4 active attendees, A/B registered, C/E not, D (archived) absent', count($x1['rows']) === 4
        && ($st1[$codeOf('A')] ?? '') === 'Registered' && ($st1[$codeOf('E')] ?? '') === 'Not registered' && !isset($st1[$codeOf('D')]), $st1);
    $x2 = $sheet($admin, "/reports/day-attendees.xlsx?day_id={$day2}");
    $st2 = array_column($x2['rows'], 'Registration Status', 'Attendee Code');
    check('Day 2 Attendees.xlsx: A/C registered, B not', ($st2[$codeOf('A')] ?? '') === 'Registered' && ($st2[$codeOf('C')] ?? '') === 'Registered' && ($st2[$codeOf('B')] ?? '') === 'Not registered');
    check('Day attendees files contain no QR tokens', !str_contains($x1['raw'] . $x2['raw'], $tokens['A']) && !str_contains($x1['raw'], $tokens['C']));
    check('Other event day id -> 404', $admin->request('GET', '/reports/day-attendees.xlsx?day_id=999999')['status'] === 404);
    $w = $sheet($admin, '/reports/winners.xlsx?scope=all');
    check('Winners.xlsx: headers (Phase 9.4 appends Exclusion Reset At / By)', $w['headers'] === ['Event Day', 'Randomizer', 'Attendee Code', 'Full Name', 'Company', 'Cluster', 'Drawn At', 'Drawn By', 'Status', 'Voided At', 'Voided By', 'Void Reason', 'Exclusion Reset At', 'Exclusion Reset By'], $w['headers']);
    check('Winners.xlsx: 9 draws, 1 VOID', count($w['rows']) === 9 && count(array_filter($w['rows'], static fn ($r) => $r['Status'] === 'VOID')) === 1);
    $valid = array_filter($w['rows'], static fn ($r) => $r['Status'] === 'VALID');
    $keys = array_map(static fn ($r) => $r['Event Day'] . '|' . $r['Randomizer'] . '|' . $r['Attendee Code'], $valid);
    check('Winners.xlsx: nobody valid twice in the same day + randomizer', count($keys) === count(array_unique($keys)));
    $both = array_filter($valid, static fn ($r) => str_starts_with($r['Event Day'], 'Day 1 ') && $r['Attendee Code'] === $codeOf('A'));
    check('Winners.xlsx: A appears once as Minor and once as Major on Day 1', count($both) === 2 && count(array_unique(array_column($both, 'Randomizer'))) === 2);
    check('Winners.xlsx: Company and Cluster filled', ($valid[array_key_first($valid)]['Company'] ?? '') === 'Smoke Co' && ($valid[array_key_first($valid)]['Cluster'] ?? '') === 'QA');

    section('Back to Day 2: still intact');
    $admin->request('PATCH', "/event-days/{$day2}/activate");
    $s = $sc1->data('GET', '/registration/summary');
    check('Day 2: Registered = 2 (A, C), Personal 1, General 2', ($s['counts']['registered'] ?? null) === 2 && ($s['scanCounts'] ?? null) === ['mine' => 1, 'all' => 2]);
    check('Day 2 Minor pool = A, C minus the Day 2 Minor winner', $count('minor') === 1);
    check('Day 2 Major pool empty (A and C won Major)', $count('major') === 0);
    check('Day 2 Minor history = 1 draw', count($operator->data('GET', '/randomizers/minor')['recentWinners'] ?? []) === 1);
    // An attendee both added manually and registered the same day counts once.
    check('Day 2: manual add B to Minor (201)', $add($operator, 'minor', $ids['B'])['status'] === 201);
    check('Day 2: B then registers at the entrance', ($scan($admin, $tokens['B'])['body']['data']['status'] ?? null) === 'registered');
    $metrics = $admin->data('GET', '/dashboard/summary')['metrics'] ?? [];
    check('Dashboard (Day 2): registered 3, Minor 2, Major 1 (B, via registration)', ($metrics['registered']['value'] ?? null) === 3
        && ($metrics['minorEligible']['value'] ?? null) === 2 && ($metrics['majorEligible']['value'] ?? null) === 1, $metrics);
    check('Day 2 Minor pool lists B once (source registration)', count(array_filter($pool('minor')['items'] ?? [], static fn ($i) => $i['attendeeCode'] === $codeOf('B'))) === 1
        && (array_column($pool('minor')['items'] ?? [], 'source', 'attendeeCode')[$codeOf('B')] ?? '') === 'registration');

    section('Removed Major QR / form / import');
    foreach ([['GET', '/major-form'], ['GET', '/major-form/info'], ['GET', '/major-eligibility'], ['POST', '/major-eligibility/import/parse'], ['GET', '/reports/major-eligibility.csv']] as [$m, $p2]) {
        check("{$m} {$p2} no longer exists (404)", $admin->request($m, $p2)['status'] === 404);
    }

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
        ['GET', '/reports/registration.csv'], ['GET', '/reports/eligibility.csv'], ['GET', '/reports/draws.csv'],
        ['GET', '/reports/day-attendees.xlsx'], ['GET', '/reports/winners.xlsx'], ['POST', '/attendees'],
        ['GET', '/settings/system-reset'], ['POST', '/settings/system-reset'],
        ['GET', '/settings/randomizer-reset'], ['POST', '/settings/randomizer-reset'],
        ['GET', '/randomizers/minor'], ['POST', '/randomizers/minor/draw'], ['GET', '/randomizers/major'], ['POST', '/randomizers/major/draw'],
        ['POST', '/randomizers/minor/participants'], ['POST', '/randomizers/major/participants'], ['GET', '/randomizers/minor/candidates'],
        ['POST', "/randomizers/draws/{$day2Draws['minor'][0]}/void"], ['GET', '/qr-codes'], ['POST', '/qr-codes/generate-missing'], ['GET', '/dashboard/summary'],
    ];
    foreach ($forbidden as [$m, $p2]) {
        $code = $sc1->request($m, $p2)['status'];
        check("Scanner blocked: {$m} {$p2} (403)", $code === 403, (string) $code);
    }

    // ------------------------------------------------------- password reset
    section('External Attendees import (synthetic workbook)');
    // Decoy sheets before/after; only "External Attendees" must be read.
    $book = (new App\Utils\XlsxWriter())
        ->addSheet('Summary', ['Cluster', 'Name 1', 'Attendee 1'], [['DECOY', 'Decoy Co', "Decoy {$stamp}"]])
        ->addSheet('External Attendees', ['Cluster', 'Name 1', 'Attendee 1', 'Attendee 2', 'Attendee 3', 'Attendee 4', 'Attendee 5', 'Attendee 6', 'Attendee 7'], [
            ['North Luzon', "AB Casiano {$stamp}", "Sfg {$stamp}", "Dsf {$stamp}", "Sgs {$stamp}", "Gds {$stamp}", "Dfs {$stamp}", "Dsg {$stamp}"],
            ['South Luzon', "XYZ & Sons, Inc. (Ph) — \"Main\" {$stamp}", '', "Pedro {$stamp}", '', "Maria {$stamp}"],
            ['Visayas', "Café Ñiño {$stamp}", "Juan {$stamp}"],
            ['Mindanao', "Delta {$stamp}", "Juan {$stamp}"],
            ['NCR', "Empty Co {$stamp}", '', '', ''],
            ['North Luzon', "AB Casiano {$stamp}", '', '', '', '', '', '', "Dsf {$stamp}"],
        ])
        ->addSheet('Internal Attendees', ['Cluster', 'Name 1', 'Attendee 1'], [['X', 'Internal Co', "Internal {$stamp}"]]);
    $bookFile = tempnam(sys_get_temp_dir(), 'p92x-') . '.xlsx';
    file_put_contents($bookFile, $book->toString());
    $parsedRaw = $admin->upload('/attendees/import/parse', $bookFile, 'external.xlsx');
    @unlink($bookFile);
    $parsed = json_decode($parsedRaw, true)['data'] ?? [];
    check('Parse reads only the "External Attendees" sheet', ($parsed['sheet'] ?? '') === 'External Attendees' && ($parsed['layout'] ?? '') === 'external_attendees', $parsedRaw);
    check('Expanded to 11 attendee rows (Name 1 never an attendee, blanks skipped, Attendee 7 read)', ($parsed['totalRows'] ?? 0) === 11 && ($parsed['attendeeColumns'] ?? 0) === 7);
    $names = array_map(static fn (array $r): string => $r['cells'][0], $parsed['rows'] ?? []);
    check('No company, decoy or internal names imported as attendees', !array_filter($names, static fn ($n) => str_contains($n, 'Casiano') || str_contains($n, 'Decoy') || str_contains($n, 'Internal')));
    $pedro = array_values(array_filter($parsed['rows'] ?? [], static fn ($r) => $r['cells'][0] === "Pedro {$stamp}"))[0]['cells'] ?? [];
    check('Blank Attendee 1 + filled Attendee 2: Pedro gets the row Company and Cluster', $pedro === ["Pedro {$stamp}", "XYZ & Sons, Inc. (Ph) — \"Main\" {$stamp}", 'South Luzon'], $pedro);
    $importPayload = ['filename' => 'external.xlsx', 'headers' => $parsed['headers'] ?? [], 'rows' => $parsed['rows'] ?? [], 'mapping' => $parsed['suggestedMapping'] ?? []];
    $preview = $admin->data('POST', '/attendees/import/preview', $importPayload);
    check('Preview: 10 new, 1 duplicate in file (same name + company + cluster)', ($preview['summary']['valid'] ?? null) === 10 && ($preview['summary']['duplicatesInFile'] ?? null) === 1, $preview['summary'] ?? null);
    $committed = $admin->request('POST', '/attendees/import', $importPayload);
    check('Import commits 10 attendees', $committed['status'] === 201 && ($committed['body']['data']['summary']['valid'] ?? null) === 10, $committed['raw']);
    $juans = $pdo->query("SELECT company, department FROM attendees WHERE event_id = {$eventId} AND full_name = 'Juan {$stamp}' ORDER BY company")->fetchAll();
    check('Same name in two companies -> two attendees with their own Company/Cluster', count($juans) === 2
        && $juans[0]['company'] === "Café Ñiño {$stamp}" && $juans[0]['department'] === 'Visayas' && $juans[1]['company'] === "Delta {$stamp}" && $juans[1]['department'] === 'Mindanao', $juans);
    check('Company stored separately from Cluster', (int) $pdo->query("SELECT COUNT(*) FROM attendees WHERE event_id = {$eventId} AND company = 'AB Casiano {$stamp}' AND department = 'North Luzon'")->fetchColumn() === 6);

    section('Manual Add Attendee (Day 2 is current)');
    $addAttendee = static fn (ApiClient $c, array $body) => $c->request('POST', '/attendees', $body);
    $maxBefore = Attendee::maxCodeNumber($eventId);
    $r = $addAttendee($admin, ['full_name' => "  Manual  Person {$stamp} ", 'company' => 'Manual Co', 'department' => 'North Luzon',
        'external_identifier' => "EMP-{$stamp}", 'email' => "Manual-{$stamp}@Example.invalid", 'attendee_code' => 'HACK-1', 'status' => 'archived']);
    check('Admin adds an attendee (201)', $r['status'] === 201, $r['raw']);
    $m = $r['body']['data']['attendee'] ?? [];
    $mQr = $r['body']['data']['qr'] ?? [];
    $mId = (int) ($m['id'] ?? 0);
    check('Code generated as the next ATT-#### (client code ignored)', ($m['attendeeCode'] ?? '') === Attendee::formatCode($maxBefore + 1), $m['attendeeCode'] ?? null);
    check('Fields stored (name trimmed, email lower-cased, Company/Cluster separate)', ($m['fullName'] ?? '') === "Manual Person {$stamp}"
        && ($m['company'] ?? '') === 'Manual Co' && ($m['department'] ?? '') === 'North Luzon' && ($m['email'] ?? '') === "manual-{$stamp}@example.invalid"
        && ($m['externalIdentifier'] ?? '') === "EMP-{$stamp}", $m);
    check('New attendee is active, not archived', ($m['status'] ?? '') === 'active' && array_key_exists('archivedAt', $m) && $m['archivedAt'] === null);
    check('QR generated with the existing QR system', ($mQr['status'] ?? '') === 'generated' && is_string($mQr['qrPayload'] ?? null)
        && (int) $pdo->query("SELECT COUNT(*) FROM attendee_qr_codes WHERE attendee_id = {$mId}")->fetchColumn() === 1);
    $mToken = (string) $pdo->query("SELECT token FROM attendee_qr_codes WHERE attendee_id = {$mId}")->fetchColumn();
    check('QR payload holds only the opaque token (no attendee code, name or id)', str_ends_with((string) ($mQr['qrPayload'] ?? ''), $mToken)
        && !str_contains((string) $mQr['qrPayload'], (string) $m['attendeeCode']) && !str_contains((string) $mQr['qrPayload'], 'Manual'));
    check('Adding does not register the attendee (no registration scan)', (int) $pdo->query("SELECT COUNT(*) FROM registration_scans WHERE attendee_id = {$mId}")->fetchColumn() === 0);
    check('Adding does not make them Minor or Major eligible', !in_array($m['attendeeCode'], $poolCodes('minor'), true) && !in_array($m['attendeeCode'], $poolCodes('major'), true));
    check('Audit log records attendee.created', (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'attendee.created' AND event_id = {$eventId}")->fetchColumn() === 1);

    $r = $addAttendee($operator, ['full_name' => "Operator Added {$stamp}"]);
    $opA = $r['body']['data']['attendee'] ?? [];
    check('Event Operator adds an attendee with Full Name only (201; Company/Cluster/Employee ID/Email optional)', $r['status'] === 201
        && array_intersect_key($opA, array_flip(['company', 'department', 'email', 'externalIdentifier'])) === ['company' => null, 'department' => null, 'email' => null, 'externalIdentifier' => null], $r['raw']);
    $opCode = (string) ($r['body']['data']['attendee']['attendeeCode'] ?? '');
    check('Codes are sequential and never reused', $opCode === Attendee::formatCode($maxBefore + 2), $opCode);
    $r = $addAttendee($staff, ['full_name' => "Staff Added {$stamp}"]);
    check('Registration Staff cannot add attendees (403)', $r['status'] === 403);
    check('Scanner Operator cannot add attendees (403)', $addAttendee($sc1, ['full_name' => "Scanner Added {$stamp}"])['status'] === 403);
    $r = $addAttendee($admin, ['full_name' => '   ', 'company' => 'X']);
    check('Full Name required (422 with field error)', $r['status'] === 422 && isset($r['body']['error']['details']['fields']['full_name']));
    check('Invalid email rejected (422)', $addAttendee($admin, ['full_name' => "Bad Email {$stamp}", 'email' => 'not-an-email'])['status'] === 422);

    $r = $addAttendee($admin, ['full_name' => "Someone Else {$stamp}", 'external_identifier' => "emp-{$stamp}"]);
    check('Duplicate Employee ID -> 409 "An attendee with this Employee ID already exists."', $r['status'] === 409
        && str_starts_with($r['body']['error']['message'] ?? '', 'An attendee with this Employee ID already exists.')
        && isset($r['body']['error']['details']['fields']['external_identifier']), $r['raw']);
    $r = $addAttendee($operator, ['full_name' => "Someone Else {$stamp}", 'email' => "MANUAL-{$stamp}@example.invalid"]);
    check('Duplicate email (any case) -> 409 "An attendee with this email already exists."', $r['status'] === 409
        && str_starts_with($r['body']['error']['message'] ?? '', 'An attendee with this email already exists.'), $r['raw']);
    $r = $addAttendee($admin, ['full_name' => "manual person   {$stamp}", 'company' => ' MANUAL CO', 'department' => 'north luzon']);
    check('Exact Full Name + Company + Cluster duplicate -> 409 (case/space-insensitive)', $r['status'] === 409 && ($r['body']['error']['code'] ?? '') === 'DUPLICATE_ATTENDEE', $r['raw']);
    $r = $addAttendee($admin, ['full_name' => "Juan {$stamp}", 'company' => "Café Ñiño {$stamp}", 'department' => 'Visayas']);
    check('Duplicate of an imported attendee (name + company + cluster) -> 409', $r['status'] === 409);
    $r = $addAttendee($admin, ['full_name' => "Manual Person {$stamp}", 'company' => 'Other Co', 'department' => 'South Luzon']);
    check('Same name in a different Company/Cluster is allowed (201)', $r['status'] === 201, $r['raw']);
    $r = $addAttendee($operator, ['full_name' => "Juan {$stamp}", 'company' => "Café Ñiño {$stamp}", 'department' => 'Mindanao']);
    check('Same name + company in a different Cluster is allowed (201)', $r['status'] === 201, $r['raw']);
    Attendee::setStatus((int) ($r['body']['data']['attendee']['id'] ?? 0), Attendee::STATUS_ARCHIVED);
    $r = $addAttendee($admin, ['full_name' => "Juan {$stamp}", 'company' => "Café Ñiño {$stamp}", 'department' => 'Mindanao']);
    check('Duplicate of an ARCHIVED attendee is also blocked (409, says archived)', $r['status'] === 409 && str_contains($r['body']['error']['message'] ?? '', 'archived'), $r['raw']);
    check('No attendee was created by any rejected request', (int) $pdo->query("SELECT COUNT(*) FROM attendees WHERE event_id = {$eventId} AND full_name IN ('Someone Else {$stamp}', 'Staff Added {$stamp}', 'Scanner Added {$stamp}', 'Bad Email {$stamp}')")->fetchColumn() === 0);

    $q = $operator->request('GET', "/attendees/{$mId}/qr");
    check('Event Operator can view the new QR (200)', $q['status'] === 200 && ($q['body']['data']['qr']['qrPayload'] ?? null) === $mQr['qrPayload'], $q['raw']);
    check('Event Operator can load the print sheet for it (200)', ($operator->request('GET', "/qr-codes/print?ids={$mId}")['body']['data']['items'][0]['id'] ?? 0) === $mId);
    check('Event Operator cannot print the whole QR list (403)', $operator->request('GET', '/qr-codes/print')['status'] === 403);
    check('Event Operator cannot list QR codes (403)', $operator->request('GET', '/qr-codes')['status'] === 403);
    check('Event Operator still cannot regenerate QR (403)', $operator->request('POST', "/attendees/{$mId}/qr/regenerate")['status'] === 403);
    $scanned = $scan($sc1, (string) $mQr['qrPayload']);
    check('Scanning the new QR payload (as the keyboard-wedge scanner sends it) registers on Day 2', ($scanned['body']['data']['status'] ?? null) === 'registered', $scanned['raw']);
    check('Scan result shows Company and Cluster', ($scanned['body']['data']['attendee']['company'] ?? '') === 'Manual Co');
    check('After the scan: in the Day 2 Minor AND Major pools (source registration)', in_array($m['attendeeCode'], $poolCodes('minor'), true) && in_array($m['attendeeCode'], $poolCodes('major'), true));
    $minorWins = [];
    while (($d = $draw($operator, 'minor'))['status'] === 201 || $d['status'] === 200) {
        $minorWins[] = $d['body']['data']['winner']['attendeeCode'] ?? '';
        if (count($minorWins) > 10) { break; }
    }
    check('Minor: the new attendee wins exactly once, then leaves the Minor pool', count(array_keys($minorWins, $m['attendeeCode'], true)) === 1 && !in_array($m['attendeeCode'], $poolCodes('minor'), true), $minorWins);
    check('Minor win does not remove them from the Major pool', in_array($m['attendeeCode'], $poolCodes('major'), true));
    $majorWins = [];
    while (($d = $draw($operator, 'major'))['status'] === 201 || $d['status'] === 200) {
        $majorWins[] = $d['body']['data']['winner']['attendeeCode'] ?? '';
        if (count($majorWins) > 10) { break; }
    }
    check('Major: the new attendee also wins Major once (independent randomizers)', count(array_keys($majorWins, $m['attendeeCode'], true)) === 1, $majorWins);
    $x1 = $sheet($admin, "/reports/day-attendees.xlsx?day_id={$day1}");
    $x2 = $sheet($admin, "/reports/day-attendees.xlsx?day_id={$day2}");
    $mRow1 = array_column($x1['rows'], null, 'Attendee Code')[$m['attendeeCode']] ?? [];
    $mRow2 = array_column($x2['rows'], null, 'Attendee Code')[$m['attendeeCode']] ?? [];
    check('Day 1 Attendees.xlsx lists the new attendee as Not registered (Day 2 scan does not register Day 1)', ($mRow1['Registration Status'] ?? '') === 'Not registered', $mRow1);
    check('Day 2 Attendees.xlsx: Registered, with Company, Cluster, Employee ID, Email', ($mRow2['Registration Status'] ?? '') === 'Registered'
        && ($mRow2['Company'] ?? '') === 'Manual Co' && ($mRow2['Cluster'] ?? '') === 'North Luzon' && ($mRow2['Employee ID'] ?? '') === "EMP-{$stamp}"
        && ($mRow2['Email'] ?? '') === "manual-{$stamp}@example.invalid" && ($mRow2['Registered At'] ?? '') !== '', $mRow2);
    check('Day attendee exports still have no QR tokens', !str_contains($x1['raw'] . $x2['raw'], $mToken));
    $list = array_column($operator->data('GET', '/attendees?search=' . urlencode("Manual Person {$stamp}"))['items'] ?? [], null, 'attendeeCode');
    check('Attendee list returns the new attendees with Company/Cluster/Employee ID/Email (no token)', isset($list[$m['attendeeCode']])
        && ($list[$m['attendeeCode']]['externalIdentifier'] ?? '') === "EMP-{$stamp}" && !str_contains(json_encode($list), $mToken));

    section('System Reset endpoint (validation and permissions only - never executed here)');
    $eventsBefore = (int) $pdo->query('SELECT COUNT(*) FROM events')->fetchColumn();
    $attendeesBefore = (int) $pdo->query('SELECT COUNT(*) FROM attendees')->fetchColumn();
    $summary = $admin->request('GET', '/settings/system-reset');
    check('Admin GET summary (200) with counts and the phrase', $summary['status'] === 200 && ($summary['body']['data']['confirmationPhrase'] ?? '') === 'RESET EVENT DATA'
        && ($summary['body']['data']['counts']['attendees'] ?? -1) === $attendeesBefore, $summary['raw']);
    check('GET with reset-looking query string changes nothing', $admin->request('GET', '/settings/system-reset?confirmation=RESET+EVENT+DATA&password=x')['status'] === 200
        && (int) $pdo->query('SELECT COUNT(*) FROM events')->fetchColumn() === $eventsBefore);
    foreach (['Event Operator' => $operator, 'Registration Staff' => $staff] as $label => $client) {
        check("{$label} cannot view the reset page (403)", $client->request('GET', '/settings/system-reset')['status'] === 403);
        check("{$label} cannot reset even with the right phrase (403)", $client->request('POST', '/settings/system-reset', ['confirmation' => 'RESET EVENT DATA', 'password' => $adminPassword])['status'] === 403);
    }
    $r = $admin->request('POST', '/settings/system-reset', ['confirmation' => 'reset event data', 'password' => $adminPassword]);
    check('Wrong phrase (lower case) -> 422 on confirmation', $r['status'] === 422 && isset($r['body']['error']['details']['fields']['confirmation']), $r['raw']);
    $r = $admin->request('POST', '/settings/system-reset', ['confirmation' => 'RESET EVENT DATA', 'password' => $adminPassword . '-wrong']);
    check('Right phrase + wrong password -> 422 on password', $r['status'] === 422 && isset($r['body']['error']['details']['fields']['password'])
        && !isset($r['body']['error']['details']['fields']['confirmation']), $r['raw']);
    check('Missing password -> 422', $admin->request('POST', '/settings/system-reset', ['confirmation' => 'RESET EVENT DATA'])['status'] === 422);
    $savedCsrf = $admin->swapCsrf(null);
    $r = $admin->request('POST', '/settings/system-reset', ['confirmation' => 'RESET EVENT DATA', 'password' => $adminPassword]);
    $admin->swapCsrf($savedCsrf);
    check('POST without the CSRF token is rejected (403/419)', in_array($r['status'], [403, 419], true), (string) $r['status']);
    check('Refused attempts deleted nothing', (int) $pdo->query('SELECT COUNT(*) FROM events')->fetchColumn() === $eventsBefore
        && (int) $pdo->query('SELECT COUNT(*) FROM attendees')->fetchColumn() === $attendeesBefore);
    check('Refused attempts are audited (system.reset_refused)', (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'system.reset_refused' AND created_at >= NOW() - INTERVAL 5 MINUTE")->fetchColumn() >= 3);
    check('Reset errors never echo SQL details', !preg_match('/SQLSTATE|PDO|DELETE FROM/i', $r['raw'] . $summary['raw']));

    section('Randomizer Reset (Phase 9.4): lift winner exclusions, keep history');
    // Snapshot of everything a randomizer reset must NOT touch (this event only).
    $snapshot = static function () use ($pdo, $eventId): array {
        $hash = static fn (string $sql): string => md5(json_encode($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC)));

        return [
            'registration_scans' => $hash("SELECT * FROM registration_scans WHERE event_id = {$eventId} ORDER BY id"),
            'attendees' => $hash("SELECT * FROM attendees WHERE event_id = {$eventId} ORDER BY id"),
            'attendee_qr_codes' => $hash("SELECT q.* FROM attendee_qr_codes q JOIN attendees a ON a.id = q.attendee_id WHERE a.event_id = {$eventId} ORDER BY q.id"),
            'minor_manual_entries' => $hash("SELECT * FROM minor_manual_entries WHERE event_id = {$eventId} ORDER BY id"),
            'major_eligibility' => $hash("SELECT * FROM major_eligibility WHERE event_id = {$eventId} ORDER BY id"),
            'event_days' => $hash("SELECT id, event_id, day_number, event_date, label FROM event_days WHERE event_id = {$eventId} ORDER BY id"),
        ];
    };
    $drawRows = static fn (): array => $pdo->query("SELECT id, event_day_id, attendee_id, randomizer_type, drawn_by, selected_at, voided_at, voided_by, void_reason, reset_at, reset_by
        FROM randomizer_draws WHERE event_id = {$eventId} ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    $excluding = static fn (int $dayId, string $type): array => array_map('intval', $pdo->query("SELECT attendee_id FROM randomizer_draws
        WHERE event_day_id = {$dayId} AND randomizer_type = '{$type}' AND voided_at IS NULL AND reset_at IS NULL ORDER BY attendee_id")->fetchAll(PDO::FETCH_COLUMN));
    $resetReq = static fn (ApiClient $c, int $dayId, string $scope, string $phrase, string $pw, ?int $evId = null): array => $c->request('POST', '/settings/randomizer-reset', [
        'event_id' => $evId ?? $eventId, 'event_day_id' => $dayId, 'randomizer' => $scope, 'confirmation' => $phrase, 'password' => $pw,
    ]);
    $baseline = $snapshot();
    $drawsBefore = $drawRows();
    $ex = ['d1minor' => $excluding($day1, 'minor'), 'd1major' => $excluding($day1, 'major'), 'd2minor' => $excluding($day2, 'minor'), 'd2major' => $excluding($day2, 'major')];
    $abc = [$ids['A'], $ids['B'], $ids['C']];
    sort($abc);
    check('Setup: Day 1 Minor winners A, B, C are currently excluded', $ex['d1minor'] === $abc, $ex['d1minor']);
    check('Setup: Day 1 Major excluded = A, B; Day 2 has excluded winners in both', !array_diff([$ids['A'], $ids['B']], $ex['d1major']) && count($ex['d1major']) === 2 && $ex['d2minor'] !== [] && $ex['d2major'] !== []);

    $preview = $admin->request('GET', "/settings/randomizer-reset?event_id={$eventId}&event_day_id={$day1}");
    check('Admin preview (200): Day 1 Minor 3 current winners, Major 2', $preview['status'] === 200 && ($preview['body']['data']['minor']['count'] ?? null) === 3
        && ($preview['body']['data']['major']['count'] ?? null) === 2 && ($preview['body']['data']['eventDay']['dayNumber'] ?? null) === 1, $preview['raw']);
    check('Preview lists names without tokens or database IDs', !str_contains($preview['raw'], $tokens['A']) && !str_contains($preview['raw'], '"attendeeId"')
        && in_array('Alpha Tester', array_column($preview['body']['data']['minor']['winners'] ?? [], 'fullName'), true));
    check('Preview gives the three confirmation phrases', ($preview['body']['data']['phrases'] ?? null) === ['minor' => 'RESET MINOR DRAW', 'major' => 'RESET MAJOR DRAW', 'both' => 'RESET RAFFLE DRAWS']);

    foreach (['Event Operator' => $operator, 'Registration Staff' => $staff] as $label => $client) {
        check("{$label} cannot preview (403)", $client->request('GET', "/settings/randomizer-reset?event_id={$eventId}&event_day_id={$day1}")['status'] === 403);
        check("{$label} cannot reset (403)", $resetReq($client, $day1, 'minor', 'RESET MINOR DRAW', $adminPassword)['status'] === 403);
    }
    check('Scanner Operator cannot reset (403)', $resetReq($sc1, $day1, 'minor', 'RESET MINOR DRAW', $adminPassword)['status'] === 403);
    $r = $resetReq($admin, $day1, 'minor', 'RESET MAJOR DRAW', $adminPassword);
    check('Wrong phrase for the scope (RESET MAJOR DRAW for Minor) -> 422', $r['status'] === 422 && isset($r['body']['error']['details']['fields']['confirmation']), $r['raw']);
    $r = $resetReq($admin, $day1, 'minor', 'RESET MINOR DRAW', $adminPassword . 'x');
    check('Wrong password -> 422 on password', $r['status'] === 422 && isset($r['body']['error']['details']['fields']['password']), $r['raw']);
    check('Unknown randomizer scope -> 422', $resetReq($admin, $day1, 'all', 'RESET RAFFLE DRAWS', $adminPassword)['status'] === 422);
    check('Day of another event -> 404', $resetReq($admin, $day1, 'minor', 'RESET MINOR DRAW', $adminPassword, $otherEventId)['status'] === 404);
    $saved = $admin->swapCsrf(null);
    $r = $resetReq($admin, $day1, 'minor', 'RESET MINOR DRAW', $adminPassword);
    $admin->swapCsrf($saved);
    check('POST without CSRF token rejected', in_array($r['status'], [403, 419], true), (string) $r['status']);
    check('GET with reset-looking parameters is read-only', $admin->request('GET', "/settings/randomizer-reset?event_id={$eventId}&event_day_id={$day1}&randomizer=minor&confirmation=RESET+MINOR+DRAW")['status'] === 200);
    check('Refused / unauthorised attempts changed nothing (draws and all other data identical)', $drawRows() === $drawsBefore && $snapshot() === $baseline);
    check('Refused attempts audited (randomizer.reset_refused)', (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'randomizer.reset_refused' AND event_id = {$eventId}")->fetchColumn() >= 2);

    // ---- Reset Day 1 Minor
    $r = $resetReq($admin, $day1, 'minor', 'RESET MINOR DRAW', $adminPassword);
    check('Admin resets Day 1 Minor (200, 3 winners affected)', $r['status'] === 200 && ($r['body']['data']['affected'] ?? null) === ['minor' => 3], $r['raw']);
    $drawsAfter = $drawRows();
    $changed = array_values(array_filter(array_keys($drawsAfter), static fn ($i) => $drawsAfter[$i] !== $drawsBefore[$i]));
    check('History kept: same number of draw rows, none deleted', count($drawsAfter) === count($drawsBefore) && array_column($drawsAfter, 'id') === array_column($drawsBefore, 'id'));
    check('Only the 3 Day 1 Minor valid draws changed, and only reset_at / reset_by', count($changed) === 3 && !array_filter($changed, static function ($i) use ($drawsAfter, $drawsBefore, $day1): bool {
        $a = $drawsAfter[$i]; $b = $drawsBefore[$i];
        return (int) $a['event_day_id'] !== $day1 || $a['randomizer_type'] !== 'minor' || $a['voided_at'] !== null || $a['reset_at'] === null
            || array_diff_key($a, ['reset_at' => 1, 'reset_by' => 1]) != array_diff_key($b, ['reset_at' => 1, 'reset_by' => 1]);
    }));
    $voidRow = array_values(array_filter($drawsAfter, static fn ($d) => (int) $d['id'] === $day1Draws['minor'][0]))[0] ?? [];
    check('VOID draw untouched (still void, never marked reset)', ($voidRow['voided_at'] ?? null) !== null && array_key_exists('reset_at', $voidRow) && $voidRow['reset_at'] === null);
    check('Day 1 Minor: no winner excluded any more', $excluding($day1, 'minor') === []);
    check('Day 1 Major unchanged (A, B still excluded)', $excluding($day1, 'major') === $ex['d1major']);
    check('Day 2 Minor and Major unchanged', $excluding($day2, 'minor') === $ex['d2minor'] && $excluding($day2, 'major') === $ex['d2major']);
    check('Registrations, attendees, QR codes, manual participants and days unchanged', $snapshot() === $baseline);
    $audit = $pdo->query("SELECT user_id, metadata FROM audit_logs WHERE action = 'randomizer.reset' AND event_id = {$eventId} ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $meta = json_decode((string) ($audit['metadata'] ?? '{}'), true);
    check('Audited: randomizer.reset by the admin with day, scope, count and attendees', $audit !== false && (int) $audit['user_id'] === (int) $admin->data('GET', '/auth/me')['user']['id']
        && ($meta['event_day_id'] ?? null) === $day1 && ($meta['randomizer'] ?? '') === 'minor' && ($meta['affected']['minor'] ?? null) === 3 && count($meta['attendees']['minor'] ?? []) === 3, $meta);

    check('Admin sets Day 1 current to draw again', $admin->request('PATCH', "/event-days/{$day1}/activate")['status'] === 200);
    check('Previous Minor winners A, B (registered) and C (manual) are eligible for Day 1 Minor again', $poolCodes('minor') === [$codeOf('A'), $codeOf('B'), $codeOf('C')], $poolCodes('minor'));
    check('Manual participant C still sourced "manual" (record kept)', (array_column($pool('minor')['items'] ?? [], 'source', 'attendeeCode')[$codeOf('C')] ?? '') === 'manual');
    check('Out of scope: Day 1 Major winners A, B still excluded (pool = E only)', $poolCodes('major') === [$codeOf('E')], $poolCodes('major'));
    check('+ Add Participant: A no longer counts as an excluded Minor winner (409 ALREADY_ELIGIBLE, not ALREADY_WON)', ($add($operator, 'minor', $ids['A'])['body']['error']['code'] ?? '') === 'ALREADY_ELIGIBLE');
    check('+ Add Participant: A still ALREADY_WON for Major', ($add($operator, 'major', $ids['A'])['body']['error']['code'] ?? '') === 'ALREADY_WON');
    $again = [];
    $againIds = [];
    for ($i = 0; $i < 3; $i++) {
        $d = $draw($operator, 'minor')['body']['data'] ?? [];
        $again[] = $d['winner']['attendeeCode'] ?? '';
        $againIds[] = (int) ($d['drawId'] ?? 0);
    }
    sort($again);
    check('New Day 1 Minor draws after reset select the previous winners again (A, B, C once each)', $again === [$codeOf('A'), $codeOf('B'), $codeOf('C')], $again);
    check('No repeat after the new wins: 4th Minor draw -> 409', $draw($operator, 'minor')['status'] === 409);
    $hist = $operator->data('GET', '/randomizers/minor')['recentWinners'] ?? [];
    check('Day 1 Minor history shows old draws flagged exclusionReset and new ones not', count(array_filter($hist, static fn ($h) => $h['exclusionReset'])) === 3
        && count(array_filter($hist, static fn ($h) => !$h['exclusionReset'] && $h['status'] === 'valid')) === 3, $hist);
    check('Void still works after reset: voiding a new draw returns that attendee to Minor', $operator->request('POST', "/randomizers/draws/{$againIds[0]}/void", ['reason' => 'Smoke'])['status'] === 200
        && count($poolCodes('minor')) === 1);

    // ---- Reset Day 1 Major
    $r = $resetReq($admin, $day1, 'major', 'RESET MAJOR DRAW', $adminPassword);
    check('Admin resets Day 1 Major (2 affected)', $r['status'] === 200 && ($r['body']['data']['affected'] ?? null) === ['major' => 2], $r['raw']);
    check('Previous Major winners A, B eligible again for Day 1 Major (with manual E)', $poolCodes('major') === [$codeOf('A'), $codeOf('B'), $codeOf('E')], $poolCodes('major'));
    check('Major reset leaves the Day 1 Minor state alone (2 excluded after the void)', count($excluding($day1, 'minor')) === 2);
    check('Day 2 still unchanged', $excluding($day2, 'minor') === $ex['d2minor'] && $excluding($day2, 'major') === $ex['d2major']);

    // ---- Reset Day 2 Minor + Major
    $d1MinorBefore = $excluding($day1, 'minor');
    $d1MajorBefore = $excluding($day1, 'major');
    $r = $resetReq($admin, $day2, 'both', 'RESET MINOR DRAW', $adminPassword);
    check('Minor + Major needs RESET RAFFLE DRAWS (422 otherwise)', $r['status'] === 422);
    $r = $resetReq($admin, $day2, 'both', 'RESET RAFFLE DRAWS', $adminPassword);
    check('Admin resets Day 2 Minor + Major (counts per randomizer)', $r['status'] === 200 && ($r['body']['data']['affected'] ?? null) === ['minor' => count($ex['d2minor']), 'major' => count($ex['d2major'])], $r['raw']);
    check('Day 2: nobody excluded in Minor or Major', $excluding($day2, 'minor') === [] && $excluding($day2, 'major') === []);
    check('Day 1 untouched by the Day 2 reset', $excluding($day1, 'minor') === $d1MinorBefore && $excluding($day1, 'major') === $d1MajorBefore);
    check('Still no registration/attendee/QR/manual changes after all resets', $snapshot() === $baseline);
    $r = $resetReq($admin, $day2, 'both', 'RESET RAFFLE DRAWS', $adminPassword);
    check('Resetting again with no current winners is harmless (0 affected)', $r['status'] === 200 && ($r['body']['data']['total'] ?? null) === 0);

    // ---- Concurrency: a draw and a reset of the same day/randomizer at the same moment
    check('Admin sets Day 2 current again', $admin->request('PATCH', "/event-days/{$day2}/activate")['status'] === 200);
    $consistent = true;
    $orders = [];
    for ($i = 0; $i < 4; $i++) {
        [$dr, $rs] = ApiClient::parallel([
            [$operator, 'POST', '/randomizers/minor/draw'],
            [$admin, 'POST', '/settings/randomizer-reset', ['event_id' => $eventId, 'event_day_id' => $day2, 'randomizer' => 'minor', 'confirmation' => 'RESET MINOR DRAW', 'password' => $adminPassword]],
        ]);
        $drawId = (int) ($dr['body']['data']['drawId'] ?? 0);
        $row = $pdo->query("SELECT reset_at FROM randomizer_draws WHERE id = {$drawId}")->fetch(PDO::FETCH_ASSOC);
        $exNow = $excluding($day2, 'minor');
        // Draw first -> the reset lifted it (0 excluded); reset first -> the new win excludes (1).
        $ok = $dr['status'] === 201 || $dr['status'] === 200;
        $ok = $ok && $rs['status'] === 200 && $row !== false
            && (($row['reset_at'] !== null && $exNow === [] && ($rs['body']['data']['total'] ?? -1) >= 1)
                || ($row['reset_at'] === null && count($exNow) === 1 && ($rs['body']['data']['total'] ?? -1) === 0));
        $orders[] = $row !== false && $row['reset_at'] !== null ? 'draw-then-reset' : 'reset-then-draw';
        $consistent = $consistent && $ok;
        if (!$ok) {
            echo '    detail: ' . json_encode([$dr['raw'], $rs['raw'], $row, $exNow]) . "\n";
        }
    }
    check('Concurrent draw + reset is serialised (always one consistent order: ' . implode(', ', array_unique($orders)) . ')', $consistent);
    // Deterministic: hold the event-day lock exactly as a running draw does, then send a reset.
    if ($excluding($day2, 'minor') === []) {
        $draw($operator, 'minor'); // make sure one current winner exists
    }
    $lockPdo = new PDO('mysql:host=' . App\Core\Config::get('database.host') . ';port=' . App\Core\Config::get('database.port') . ';dbname=' . App\Core\Config::get('database.database') . ';charset=utf8mb4',
        (string) App\Core\Config::get('database.username'), (string) App\Core\Config::get('database.password'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $lockPdo->beginTransaction();
    $lockPdo->query("SELECT id FROM event_days WHERE id = {$day2} FOR UPDATE")->fetchAll();
    $multi = curl_multi_init();
    $handle = $admin->handle('POST', '/settings/randomizer-reset', ['event_id' => $eventId, 'event_day_id' => $day2, 'randomizer' => 'minor', 'confirmation' => 'RESET MINOR DRAW', 'password' => $adminPassword]);
    curl_multi_add_handle($multi, $handle);
    $waitUntil = microtime(true) + 1.5;
    do {
        curl_multi_exec($multi, $running);
        curl_multi_select($multi, 0.1);
    } while ($running > 0 && microtime(true) < $waitUntil);
    $stillWaiting = $running > 0;
    $excludedWhileLocked = $excluding($day2, 'minor');
    $lockPdo->commit(); // the "draw" finishes
    do {
        curl_multi_exec($multi, $running);
        curl_multi_select($multi, 0.1);
    } while ($running > 0);
    $lockedStatus = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_multi_remove_handle($multi, $handle);
    curl_multi_close($multi);
    check('While a draw holds the day lock, the reset waits (no partial reset)', $stillWaiting && count($excludedWhileLocked) === 1, json_encode([$stillWaiting, $excludedWhileLocked]));
    check('Once the draw finishes, the waiting reset completes (200) and lifts that exclusion', $lockedStatus === 200 && $excluding($day2, 'minor') === []);
    $d = $draw($operator, 'minor');
    check('A draw right after a reset uses the reset state (succeeds, winner excluded again)', in_array($d['status'], [200, 201], true) && count($excluding($day2, 'minor')) === 1);
    check('No attendee is excluded twice for the same day + randomizer', (int) $pdo->query(
        "SELECT COUNT(*) FROM (SELECT attendee_id, event_day_id, randomizer_type FROM randomizer_draws WHERE event_id = {$eventId}
         AND voided_at IS NULL AND reset_at IS NULL GROUP BY attendee_id, event_day_id, randomizer_type HAVING COUNT(*) > 1) x")->fetchColumn() === 0);

    // ---- Reports keep the history
    $w = $sheet($admin, '/reports/winners.xlsx?scope=all');
    check('Winners.xlsx: original 12 columns unchanged, 2 appended (Exclusion Reset At / By)', $w['headers'] === ['Event Day', 'Randomizer', 'Attendee Code', 'Full Name', 'Company', 'Cluster',
        'Drawn At', 'Drawn By', 'Status', 'Voided At', 'Voided By', 'Void Reason', 'Exclusion Reset At', 'Exclusion Reset By'], $w['headers']);
    check('Winners.xlsx: every draw row still present', count($w['rows']) === count($drawRows()));
    $aMinorD1 = array_values(array_filter($w['rows'], static fn ($r) => str_starts_with($r['Event Day'], 'Day 1 ') && $r['Randomizer'] === 'Minor' && $r['Attendee Code'] === $codeOf('A') && $r['Status'] === 'VALID'));
    check('Winners.xlsx: A listed twice for Day 1 Minor, the earlier win marked with the reset time and admin, the later win after it',
        count($aMinorD1) === 2 && $aMinorD1[0]['Exclusion Reset At'] !== '' && $aMinorD1[0]['Exclusion Reset By'] !== '' && $aMinorD1[1]['Exclusion Reset At'] === ''
        && $aMinorD1[1]['Drawn At'] >= $aMinorD1[0]['Exclusion Reset At'], $aMinorD1);
    check('Winners.xlsx: VOID rows have no reset mark', !array_filter($w['rows'], static fn ($r) => $r['Status'] === 'VOID' && $r['Exclusion Reset At'] !== ''));
    $dcsv = $admin->csv('/reports/draws.csv?scope=all');
    check('Draw CSV report still valid with the appended reset columns', $dcsv !== [] && array_key_exists('Exclusion Reset At', $dcsv[0]) && array_key_exists('Void Reason', $dcsv[0]));
    $el = $admin->csv('/reports/eligibility.csv?scope=all');
    check('Raffle eligibility report still valid (same columns, Won Minor kept as history)', $el !== [] && array_keys($el[0]) === ['Event Day', 'Attendee Code', 'Full Name', 'Company', 'Cluster', 'Email', 'Registered',
        'Minor Eligible', 'Minor Source', 'Won Minor', 'Major Eligible', 'Major Source', 'Won Major', 'Attendee Status'], array_keys($el[0] ?? []));
    $x1 = $sheet($admin, "/reports/day-attendees.xlsx?day_id={$day1}");
    check('Day 1 Attendees.xlsx unchanged by resets (same headers, A registered)', ($x1['headers'][6] ?? '') === 'Registration Status'
        && (array_column($x1['rows'], 'Registration Status', 'Attendee Code')[$codeOf('A')] ?? '') === 'Registered');

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
