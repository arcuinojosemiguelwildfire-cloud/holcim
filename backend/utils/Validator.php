<?php

declare(strict_types=1);

namespace App\Utils;

use App\Core\HttpException;

/**
 * Small fluent input validator. Collects all errors, then throws a single
 * 422 VALIDATION_ERROR with per-field messages.
 *
 *   $data = Validator::make($request->body())
 *       ->string('name', required: true, max: 200)
 *       ->date('event_date', required: true)
 *       ->validate();
 *
 * validate() returns only the declared fields, trimmed and type-normalised;
 * undeclared input is dropped (no mass-assignment).
 */
final class Validator
{
    /** @var array<string, list<string>> */
    private array $errors = [];

    /** @var array<string, mixed> */
    private array $clean = [];

    /** @param array<string, mixed> $input */
    private function __construct(private readonly array $input)
    {
    }

    /** @param array<string, mixed> $input */
    public static function make(array $input): self
    {
        return new self($input);
    }

    public function string(string $field, bool $required = false, int $max = 255, int $min = 0, string $label = ''): self
    {
        $label = $label !== '' ? $label : self::label($field);
        $value = $this->input[$field] ?? null;

        if ($value === null || (is_string($value) && trim($value) === '')) {
            if ($required) {
                $this->addError($field, "{$label} is required.");
            } elseif (array_key_exists($field, $this->input)) {
                $this->clean[$field] = null;
            }

            return $this;
        }

        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            $this->addError($field, "{$label} must be text.");

            return $this;
        }

        $value = trim((string) $value);
        $length = mb_strlen($value);

        if ($length < $min) {
            $this->addError($field, "{$label} must be at least {$min} characters.");
        } elseif ($length > $max) {
            $this->addError($field, "{$label} may not be longer than {$max} characters.");
        } else {
            $this->clean[$field] = $value;
        }

        return $this;
    }

    public function email(string $field, bool $required = false, string $label = ''): self
    {
        $this->string($field, $required, 190, 0, $label);

        $value = $this->clean[$field] ?? null;
        if (is_string($value)) {
            if (filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                unset($this->clean[$field]);
                $this->addError($field, ($label ?: self::label($field)) . ' must be a valid email address.');
            } else {
                $this->clean[$field] = strtolower($value);
            }
        }

        return $this;
    }

    /** Validates a calendar date in Y-m-d format. */
    public function date(string $field, bool $required = false, string $label = ''): self
    {
        $label = $label !== '' ? $label : self::label($field);
        $this->string($field, $required, 10, 0, $label);

        $value = $this->clean[$field] ?? null;
        if (is_string($value)) {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
            if ($date === false || $date->format('Y-m-d') !== $value) {
                unset($this->clean[$field]);
                $this->addError($field, "{$label} must be a valid date (YYYY-MM-DD).");
            }
        }

        return $this;
    }

    /** Validates a wall-clock time "HH:MM" (24-hour); stored as "HH:MM:00". */
    public function time(string $field, bool $required = false, string $label = ''): self
    {
        $label = $label !== '' ? $label : self::label($field);
        $this->string($field, $required, 8, 0, $label);

        $value = $this->clean[$field] ?? null;
        if (is_string($value)) {
            if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)(:00)?$/', $value, $m) !== 1) {
                unset($this->clean[$field]);
                $this->addError($field, "{$label} must be a time (HH:MM, 24-hour).");
            } else {
                $this->clean[$field] = "{$m[1]}:{$m[2]}:00";
            }
        }

        return $this;
    }

    /** Accepts true/false (JSON booleans, 0/1, "true"/"false"). */
    public function boolean(string $field, bool $required = false, string $label = ''): self
    {
        $label = $label !== '' ? $label : self::label($field);
        if (!array_key_exists($field, $this->input) || $this->input[$field] === null) {
            if ($required) {
                $this->addError($field, "{$label} is required.");
            }

            return $this;
        }
        $value = filter_var($this->input[$field], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($value === null) {
            $this->addError($field, "{$label} must be true or false.");
        } else {
            $this->clean[$field] = $value;
        }

        return $this;
    }

    public function integer(string $field, bool $required = false, int $min = 0, int $max = PHP_INT_MAX, string $label = ''): self
    {
        $label = $label !== '' ? $label : self::label($field);
        $value = $this->input[$field] ?? null;
        if ($value === null || $value === '') {
            if ($required) {
                $this->addError($field, "{$label} is required.");
            }

            return $this;
        }
        if (!(is_int($value) || (is_string($value) && preg_match('/^-?\d{1,10}$/', $value) === 1)) || (int) $value < $min || (int) $value > $max) {
            $this->addError($field, "{$label} must be a whole number between {$min} and {$max}.");
        } else {
            $this->clean[$field] = (int) $value;
        }

        return $this;
    }

    /** @param list<string> $allowed */
    public function in(string $field, array $allowed, bool $required = false, string $label = ''): self
    {
        $label = $label !== '' ? $label : self::label($field);
        $this->string($field, $required, 100, 0, $label);

        $value = $this->clean[$field] ?? null;
        if (is_string($value) && !in_array($value, $allowed, true)) {
            unset($this->clean[$field]);
            $this->addError($field, "{$label} must be one of: " . implode(', ', $allowed) . '.');
        }

        return $this;
    }

    /**
     * @return array<string, mixed>
     * @throws HttpException
     */
    public function validate(): array
    {
        if ($this->errors !== []) {
            throw HttpException::validation($this->errors);
        }

        return $this->clean;
    }

    /** Validates a positive integer route parameter such as {id}. */
    public static function id(?string $value, string $resource = 'Resource'): int
    {
        if ($value === null || !ctype_digit($value) || (int) $value < 1 || strlen($value) > 10) {
            throw HttpException::notFound("{$resource} not found.");
        }

        return (int) $value;
    }

    private function addError(string $field, string $message): void
    {
        $this->errors[$field][] = $message;
    }

    private static function label(string $field): string
    {
        return ucfirst(str_replace('_', ' ', $field));
    }
}
