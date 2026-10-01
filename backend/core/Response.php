<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Every API response uses one of two shapes:
 *
 *   Success: { "success": true,  "data": <any>, "message"?: string }
 *   Error:   { "success": false, "error": { "code": string, "message": string, "details"?: object } }
 */
final class Response
{
    /**
     * @param array<string, string> $headers
     */
    private function __construct(
        private readonly int $status,
        private readonly array $payload,
        private array $headers = [],
        private readonly ?string $rawBody = null,
        private readonly string $contentType = 'application/json; charset=utf-8'
    ) {
    }

    /**
     * Non-JSON response (CSV download, redirect, small HTML page).
     *
     * @param array<string, string> $headers
     */
    public static function raw(int $status, string $body, string $contentType, array $headers = []): self
    {
        return new self($status, [], $headers, $body, $contentType);
    }

    public static function success(mixed $data = null, int $status = 200, ?string $message = null): self
    {
        $payload = ['success' => true, 'data' => $data];
        if ($message !== null) {
            $payload['message'] = $message;
        }

        return new self($status, $payload);
    }

    public static function created(mixed $data, ?string $message = null): self
    {
        return self::success($data, 201, $message);
    }

    /** @param array<string, mixed>|null $details */
    public static function error(int $status, string $code, string $message, ?array $details = null): self
    {
        $error = ['code' => $code, 'message' => $message];
        if ($details !== null) {
            $error['details'] = $details;
        }

        return new self($status, ['success' => false, 'error' => $error]);
    }

    public function withHeader(string $name, string $value): self
    {
        $clone = clone $this;
        $clone->headers[$name] = $value;

        return $clone;
    }

    public function status(): int
    {
        return $this->status;
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return $this->payload;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            header('Content-Type: ' . $this->contentType);
            foreach ($this->headers as $name => $value) {
                header("{$name}: {$value}");
            }
        }

        if ($this->rawBody !== null) {
            echo $this->rawBody;

            return;
        }

        echo json_encode(
            $this->payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
        );
    }
}
