<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Immutable view of the current HTTP request.
 */
final class Request
{
    /** @var array<string, string> */
    private array $routeParams = [];

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body
     * @param array<string, string> $headers lower-cased header names
     */
    private function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly array $query,
        private readonly array $body,
        private readonly array $headers,
        private readonly string $ip,
        private readonly string $userAgent
    ) {
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        return new self(
            $method,
            self::resolvePath(),
            $_GET,
            self::parseBody($method),
            self::collectHeaders(),
            (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255)
        );
    }

    public function method(): string
    {
        return $this->method;
    }

    /** Route path relative to the API root, e.g. "/health" or "/events/5". */
    public function path(): string
    {
        return $this->path;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    /** @return array<string, mixed> */
    public function body(): array
    {
        return $this->body;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function ip(): string
    {
        return $this->ip;
    }

    public function userAgent(): string
    {
        return $this->userAgent;
    }

    public function isStateChanging(): bool
    {
        return !in_array($this->method, ['GET', 'HEAD', 'OPTIONS'], true);
    }

    /** @param array<string, string> $params */
    public function withRouteParams(array $params): self
    {
        $clone = clone $this;
        $clone->routeParams = $params;

        return $clone;
    }

    public function param(string $name): ?string
    {
        return $this->routeParams[$name] ?? null;
    }

    /**
     * Works out the route path no matter where the backend is installed:
     *   http://localhost/Holcim/backend/api/health -> /health
     *   https://example.com/api/health (backend uploaded as /api) -> /health
     *   php -S (built-in server, router script) /api/health -> /health
     */
    private static function resolvePath(): string
    {
        $uriPath = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $uriPath = rawurldecode($uriPath);

        $scriptDirectory = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
        if ($scriptDirectory !== '' && str_starts_with($uriPath, $scriptDirectory . '/')) {
            $uriPath = substr($uriPath, strlen($scriptDirectory));
        }

        if ($uriPath === '/api' || str_starts_with($uriPath, '/api/')) {
            $uriPath = substr($uriPath, 4);
        }

        $uriPath = '/' . trim($uriPath, '/');

        return $uriPath;
    }

    /** @return array<string, mixed> */
    private static function parseBody(string $method): array
    {
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return [];
        }

        $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
        if (!str_contains($contentType, 'application/json')) {
            return $_POST;
        }

        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw HttpException::badRequest('Request body is not valid JSON.');
        }

        if (!is_array($decoded)) {
            throw HttpException::badRequest('Request body must be a JSON object.');
        }

        return $decoded;
    }

    /** @return array<string, string> */
    private static function collectHeaders(): array
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = (string) $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }

        return $headers;
    }
}
