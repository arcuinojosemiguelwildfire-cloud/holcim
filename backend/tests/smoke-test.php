<?php

declare(strict_types=1);

/**
 * End-to-end API smoke test (no dependencies besides the PHP curl extension).
 *
 *   HOLCIM_TEST_EMAIL=admin@example.com HOLCIM_TEST_PASSWORD='...' \
 *     php backend/tests/smoke-test.php http://localhost/Holcim/backend/api
 *
 * Add --write to also exercise event creation/updates. --write CREATES REAL
 * EVENTS (named "Smoke Test ...") and needs an ADMIN account, so only use it
 * against a development database.
 *
 * Optional: HOLCIM_TEST_STAFF_EMAIL / HOLCIM_TEST_STAFF_PASSWORD for a
 * non-admin account to verify role checks.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

if (!function_exists('curl_init')) {
    fwrite(STDERR, "The PHP curl extension is required.\n");
    exit(1);
}

$positional = array_values(array_filter(array_slice($argv, 1), static fn ($a) => !str_starts_with($a, '--')));
$baseUrl = rtrim($positional[0] ?? 'http://localhost/Holcim/backend/api', '/');
$write = in_array('--write', $argv, true);
$email = getenv('HOLCIM_TEST_EMAIL') ?: '';
$password = getenv('HOLCIM_TEST_PASSWORD') ?: '';

final class Client
{
    private string $cookieFile;
    public ?string $csrf = null;

    public function __construct(private readonly string $baseUrl)
    {
        $this->cookieFile = tempnam(sys_get_temp_dir(), 'holcim-smoke-') ?: throw new RuntimeException('tempnam failed');
    }

    public function __destruct()
    {
        @unlink($this->cookieFile);
    }

    /** @return array{status:int, body:array<string,mixed>|null, raw:string} */
    public function request(string $method, string $path, ?array $json = null, bool $sendCsrf = true): array
    {
        $headers = ['Accept: application/json'];
        if ($json !== null) {
            $headers[] = 'Content-Type: application/json';
        }
        if ($sendCsrf && $this->csrf !== null) {
            $headers[] = 'X-CSRF-Token: ' . $this->csrf;
        }

        $handle = curl_init($this->baseUrl . $path);
        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_COOKIEJAR => $this->cookieFile,
            CURLOPT_COOKIEFILE => $this->cookieFile,
            CURLOPT_TIMEOUT => 15,
        ]);
        if ($json !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($json));
        }

        $raw = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        if ($raw === false) {
            throw new RuntimeException('Request failed: ' . curl_error($handle));
        }

        $body = json_decode((string) $raw, true);

        return ['status' => $status, 'body' => is_array($body) ? $body : null, 'raw' => (string) $raw];
    }

    public function refreshSession(): array
    {
        $response = $this->request('GET', '/auth/session');
        $this->csrf = $response['body']['data']['csrfToken'] ?? null;

        return $response;
    }
}

$passed = 0;
$failed = 0;

function check(string $label, bool $condition, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  PASS  {$label}\n";
    } else {
        $failed++;
        echo "  FAIL  {$label}" . ($detail !== '' ? "  -> {$detail}" : '') . "\n";
    }
}

echo "Smoke testing {$baseUrl}\n\n";
$client = new Client($baseUrl);

// --- Public endpoints ----------------------------------------------------
$health = $client->request('GET', '/health');
check('GET /health returns 200', $health['status'] === 200, $health['raw']);
check('Health reports database connected', ($health['body']['data']['database'] ?? null) === 'connected', $health['raw']);

$notFound = $client->request('GET', '/does-not-exist');
check('Unknown endpoint returns 404 JSON error', $notFound['status'] === 404 && ($notFound['body']['success'] ?? null) === false);

// --- Unauthenticated access ----------------------------------------------
$me = $client->request('GET', '/auth/me');
check('GET /auth/me without login returns 401', $me['status'] === 401, $me['raw']);
$events = $client->request('GET', '/events');
check('GET /events without login returns 401', $events['status'] === 401);

$session = $client->refreshSession();
check('GET /auth/session returns CSRF token', is_string($client->csrf) && strlen($client->csrf) >= 32);
check('Session reports unauthenticated', ($session['body']['data']['authenticated'] ?? null) === false);

$noCsrf = $client->request('POST', '/auth/login', ['email' => $email, 'password' => $password], sendCsrf: false);
check('Login without CSRF token is rejected (403)', $noCsrf['status'] === 403 && ($noCsrf['body']['error']['code'] ?? '') === 'CSRF_TOKEN_MISMATCH', $noCsrf['raw']);

$badLogin = $client->request('POST', '/auth/login', ['email' => 'nobody@example.com', 'password' => 'wrong-password']);
check('Login with bad credentials returns 401', $badLogin['status'] === 401, $badLogin['raw']);

$invalid = $client->request('POST', '/auth/login', ['email' => 'not-an-email', 'password' => '']);
check('Login with invalid input returns 422', $invalid['status'] === 422, $invalid['raw']);

if ($email === '' || $password === '') {
    echo "\nSet HOLCIM_TEST_EMAIL and HOLCIM_TEST_PASSWORD to test login/logout.\n";
} else {
    // --- Login -----------------------------------------------------------
    $oldCsrf = $client->csrf;
    $login = $client->request('POST', '/auth/login', ['email' => $email, 'password' => $password]);
    check('Login with valid credentials returns 200', $login['status'] === 200, $login['raw']);
    $client->csrf = $login['body']['data']['csrfToken'] ?? null;
    check('CSRF token rotates on login', $client->csrf !== null && $client->csrf !== $oldCsrf);

    $me = $client->request('GET', '/auth/me');
    check('GET /auth/me after login returns 200', $me['status'] === 200, $me['raw']);
    $role = $me['body']['data']['user']['role'] ?? null;
    check('Role information is available', in_array($role, ['admin', 'registration_staff', 'event_operator'], true), (string) $role);
    check('Password hash is never returned', !str_contains($me['raw'], 'password'));

    $summary = $client->request('GET', '/dashboard/summary');
    check('GET /dashboard/summary returns 200', $summary['status'] === 200, $summary['raw']);

    $list = $client->request('GET', '/events');
    check('GET /events returns 200', $list['status'] === 200, $list['raw']);

    if ($write && $role === 'admin') {
        $suffix = date('His');
        $created = $client->request('POST', '/events', [
            'name' => "Smoke Test {$suffix}", 'description' => 'Created by smoke test',
            'event_date' => date('Y-m-d'), 'status' => 'draft',
        ]);
        check('POST /events creates event (201)', $created['status'] === 201, $created['raw']);
        $id = $created['body']['data']['event']['id'] ?? 0;

        $badEvent = $client->request('POST', '/events', ['name' => '', 'event_date' => '2026-02-30', 'status' => 'nope']);
        check('POST /events validates input (422)', $badEvent['status'] === 422
            && isset($badEvent['body']['error']['details']['fields']['name'], $badEvent['body']['error']['details']['fields']['event_date'], $badEvent['body']['error']['details']['fields']['status']), $badEvent['raw']);

        $updated = $client->request('PUT', "/events/{$id}", [
            'name' => "Smoke Test {$suffix} (edited)", 'description' => '', 'event_date' => date('Y-m-d'), 'status' => 'draft',
        ]);
        check('PUT /events/{id} updates event', $updated['status'] === 200
            && ($updated['body']['data']['event']['name'] ?? '') === "Smoke Test {$suffix} (edited)", $updated['raw']);

        $status = $client->request('PATCH', "/events/{$id}/status", ['status' => 'archived']);
        check('PATCH /events/{id}/status changes status', ($status['body']['data']['event']['status'] ?? null) === 'archived', $status['raw']);

        $missing = $client->request('GET', '/events/999999999');
        check('GET /events/{missing} returns 404', $missing['status'] === 404);
    }

    // --- Logout ----------------------------------------------------------
    $logout = $client->request('POST', '/auth/logout');
    check('POST /auth/logout returns 200', $logout['status'] === 200, $logout['raw']);
    $client->refreshSession();
    $me = $client->request('GET', '/auth/me');
    check('GET /auth/me after logout returns 401', $me['status'] === 401);
}

// --- Non-admin role checks ----------------------------------------------
$staffEmail = getenv('HOLCIM_TEST_STAFF_EMAIL') ?: '';
$staffPassword = getenv('HOLCIM_TEST_STAFF_PASSWORD') ?: '';
if ($staffEmail !== '' && $staffPassword !== '') {
    $staff = new Client($baseUrl);
    $staff->refreshSession();
    $login = $staff->request('POST', '/auth/login', ['email' => $staffEmail, 'password' => $staffPassword]);
    $staff->csrf = $login['body']['data']['csrfToken'] ?? null;
    check('Non-admin can log in', $login['status'] === 200, $login['raw']);
    check('Non-admin can view events', $staff->request('GET', '/events')['status'] === 200);
    $forbidden = $staff->request('POST', '/events', ['name' => 'X', 'event_date' => '2026-01-01', 'status' => 'draft']);
    check('Non-admin cannot create events (403)', $forbidden['status'] === 403, $forbidden['raw']);
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
