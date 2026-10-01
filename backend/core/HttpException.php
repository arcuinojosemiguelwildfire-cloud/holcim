<?php

declare(strict_types=1);

namespace App\Core;

/**
 * An error that maps directly to an HTTP response.
 * Thrown anywhere in the request lifecycle; rendered by the global handler.
 */
class HttpException extends \RuntimeException
{
    /**
     * @param array<string, mixed>|null $details Extra machine-readable info (e.g. validation errors)
     */
    public function __construct(
        private readonly int $status,
        private readonly string $errorCode,
        string $message,
        private readonly ?array $details = null
    ) {
        parent::__construct($message, $status);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /** @return array<string, mixed>|null */
    public function details(): ?array
    {
        return $this->details;
    }

    public static function badRequest(string $message = 'Bad request.'): self
    {
        return new self(400, 'BAD_REQUEST', $message);
    }

    public static function unauthorized(string $message = 'Authentication required.'): self
    {
        return new self(401, 'UNAUTHENTICATED', $message);
    }

    public static function forbidden(string $message = 'You do not have permission to perform this action.'): self
    {
        return new self(403, 'FORBIDDEN', $message);
    }

    public static function notFound(string $message = 'Resource not found.'): self
    {
        return new self(404, 'NOT_FOUND', $message);
    }

    public static function methodNotAllowed(): self
    {
        return new self(405, 'METHOD_NOT_ALLOWED', 'Method not allowed.');
    }

    public static function conflict(string $message): self
    {
        return new self(409, 'CONFLICT', $message);
    }

    /** @param array<string, list<string>> $errors */
    public static function validation(array $errors, string $message = 'The given data was invalid.'): self
    {
        return new self(422, 'VALIDATION_ERROR', $message, ['fields' => $errors]);
    }

    public static function csrf(): self
    {
        return new self(403, 'CSRF_TOKEN_MISMATCH', 'Your session security token is missing or expired. Please refresh and try again.');
    }
}
