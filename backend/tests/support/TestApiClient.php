<?php

declare(strict_types=1);

/**
 * Minimal API client for the HTTP test scripts (same as the one inside
 * phase8-smoke-test.php): cookie session, CSRF header, JSON bodies,
 * parallel requests and CSV download.
 */
final class TestApiClient
{
    private string $cookieFile;
    private ?string $csrf = null;

    public function __construct(private readonly string $baseUrl)
    {
        $this->cookieFile = tempnam(sys_get_temp_dir(), 'holcim-test-') ?: throw new RuntimeException('tempnam failed');
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
     * client's session; optional 4th element = JSON body. @param list<array{0: TestApiClient, 1: string, 2: string, 3?: array<string, mixed>}> $requests
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
