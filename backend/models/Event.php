<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Data access for the `events` table.
 */
final class Event
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_ARCHIVED = 'archived';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_ACTIVE,
        self::STATUS_COMPLETED,
        self::STATUS_ARCHIVED,
    ];

    private const COLUMNS = 'id, name, description, event_date, status, created_at, updated_at';

    /** Columns a client is allowed to write. */
    private const WRITABLE = ['name', 'description', 'event_date', 'status'];

    /** @return list<array<string, mixed>> Active first, then most recent date. */
    public static function all(): array
    {
        $statement = Database::connection()->query(
            'SELECT ' . self::COLUMNS . " FROM events
             ORDER BY (status = 'active') DESC, event_date DESC, id DESC"
        );

        return $statement->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public static function find(int $id): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM events WHERE id = :id LIMIT 1'
        );
        $statement->execute(['id' => $id]);

        return $statement->fetch() ?: null;
    }

    /** @return array<string, mixed>|null */
    public static function findActive(): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM events WHERE status = :status LIMIT 1'
        );
        $statement->execute(['status' => self::STATUS_ACTIVE]);

        return $statement->fetch() ?: null;
    }

    /**
     * Locks and returns the currently active event other than $exceptId.
     * Call inside a transaction.
     *
     * @return array<string, mixed>|null
     */
    public static function findOtherActiveForUpdate(?int $exceptId): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM events
             WHERE status = :status AND id <> :except_id
             LIMIT 1 FOR UPDATE'
        );
        $statement->execute(['status' => self::STATUS_ACTIVE, 'except_id' => $exceptId ?? 0]);

        return $statement->fetch() ?: null;
    }

    /** @param array<string, mixed> $data */
    public static function create(array $data): int
    {
        $statement = Database::connection()->prepare(
            'INSERT INTO events (name, description, event_date, status)
             VALUES (:name, :description, :event_date, :status)'
        );
        $statement->execute([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'event_date' => $data['event_date'],
            'status' => $data['status'] ?? self::STATUS_DRAFT,
        ]);

        return (int) Database::connection()->lastInsertId();
    }

    /**
     * Updates only whitelisted columns that are present in $data.
     * Column names come from the WRITABLE constant, never from user input.
     *
     * @param array<string, mixed> $data
     */
    public static function update(int $id, array $data): void
    {
        $fields = array_values(array_intersect(self::WRITABLE, array_keys($data)));
        if ($fields === []) {
            return;
        }

        $assignments = implode(', ', array_map(static fn (string $f): string => "{$f} = :{$f}", $fields));
        $params = ['id' => $id];
        foreach ($fields as $field) {
            $params[$field] = $data[$field];
        }

        $statement = Database::connection()->prepare("UPDATE events SET {$assignments} WHERE id = :id");
        $statement->execute($params);
    }

    /**
     * @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    public static function toPublic(array $event): array
    {
        return [
            'id' => (int) $event['id'],
            'name' => $event['name'],
            'description' => $event['description'],
            'eventDate' => $event['event_date'],
            'status' => $event['status'],
            'createdAt' => $event['created_at'],
            'updatedAt' => $event['updated_at'],
        ];
    }
}
