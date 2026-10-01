<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Eligible pools (read-only: minor from registration_scans, major from
 * major_eligibility) and the randomizer_draws history.
 */
final class RandomizerDraw
{
    public const TYPE_MINOR = 'minor';
    public const TYPE_MAJOR = 'major';

    /** SQL fragment: active attendees of :event_id with a check-in. */
    private const ELIGIBLE_FROM = "FROM registration_scans r
        JOIN attendees a ON a.id = r.attendee_id AND a.event_id = r.event_id
        WHERE r.event_id = :event_id AND a.status = 'active'";

    /** SQL fragment: active attendees of :event_id with a Major eligibility row. */
    private const MAJOR_ELIGIBLE_FROM = "FROM major_eligibility m
        JOIN attendees a ON a.id = m.attendee_id AND a.event_id = m.event_id
        WHERE m.event_id = :event_id AND a.status = 'active'";

    public static function countEligible(int $eventId, string $type): int
    {
        $statement = Database::connection()->prepare('SELECT COUNT(*) ' . self::fromFor($type));
        $statement->execute(['event_id' => $eventId]);

        return (int) $statement->fetchColumn();
    }

    /** @return list<int> attendee IDs of the eligible pool for $type */
    public static function eligibleIds(int $eventId, string $type): array
    {
        $statement = Database::connection()->prepare('SELECT a.id ' . self::fromFor($type) . ' ORDER BY a.id');
        $statement->execute(['event_id' => $eventId]);

        return array_map('intval', $statement->fetchAll(\PDO::FETCH_COLUMN));
    }

    private static function fromFor(string $type): string
    {
        return $type === self::TYPE_MAJOR ? self::MAJOR_ELIGIBLE_FROM : self::ELIGIBLE_FROM;
    }

    /**
     * @param list<int> $attendeeIds
     * @return list<array{id: int, attendee_code: string, full_name: string, department: ?string}>
     */
    public static function attendeesByIds(array $attendeeIds): array
    {
        if ($attendeeIds === []) {
            return [];
        }
        $statement = Database::connection()->prepare(
            'SELECT id, attendee_code, full_name, department FROM attendees WHERE id IN ('
            . implode(',', array_fill(0, count($attendeeIds), '?')) . ')'
        );
        $statement->execute($attendeeIds);

        return $statement->fetchAll();
    }

    public static function create(int $eventId, int $attendeeId, string $type, ?int $userId): int
    {
        Database::connection()->prepare(
            'INSERT INTO randomizer_draws (event_id, attendee_id, randomizer_type, drawn_by, selected_at)
             VALUES (:event_id, :attendee_id, :type, :user_id, NOW())'
        )->execute(['event_id' => $eventId, 'attendee_id' => $attendeeId, 'type' => $type, 'user_id' => $userId]);

        return (int) Database::connection()->lastInsertId();
    }

    /** @return array<string, mixed>|null */
    public static function find(int $id): ?array
    {
        $statement = Database::connection()->prepare('SELECT id, selected_at FROM randomizer_draws WHERE id = :id');
        $statement->execute(['id' => $id]);

        return $statement->fetch() ?: null;
    }

    /** @return list<array<string, mixed>> latest draws first */
    public static function recent(int $eventId, string $type, int $limit = 10): array
    {
        $limit = max(1, min(50, $limit));
        $statement = Database::connection()->prepare(
            "SELECT d.id, d.selected_at, d.voided_at, d.void_reason, a.attendee_code, a.full_name, a.department,
                    u.name AS drawn_by_name, v.name AS voided_by_name
             FROM randomizer_draws d
             JOIN attendees a ON a.id = d.attendee_id
             LEFT JOIN users u ON u.id = d.drawn_by
             LEFT JOIN users v ON v.id = d.voided_by
             WHERE d.event_id = :event_id AND d.randomizer_type = :type
             ORDER BY d.selected_at DESC, d.id DESC
             LIMIT {$limit}"
        );
        $statement->execute(['event_id' => $eventId, 'type' => $type]);

        return $statement->fetchAll();
    }

    /** @return array<string, mixed>|null draw of the given event (any type) */
    public static function findInEvent(int $drawId, int $eventId): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT d.id, d.event_id, d.attendee_id, d.randomizer_type, d.selected_at, d.voided_at, a.attendee_code, a.full_name
             FROM randomizer_draws d JOIN attendees a ON a.id = d.attendee_id
             WHERE d.id = :id AND d.event_id = :event_id LIMIT 1'
        );
        $statement->execute(['id' => $drawId, 'event_id' => $eventId]);

        return $statement->fetch() ?: null;
    }

    /** Marks a draw VOID (never deletes it). Returns false if it was already void. */
    public static function void(int $drawId, ?int $userId, ?string $reason): bool
    {
        $statement = Database::connection()->prepare(
            'UPDATE randomizer_draws SET voided_at = NOW(), voided_by = :user_id, void_reason = :reason
             WHERE id = :id AND voided_at IS NULL'
        );
        $statement->execute(['user_id' => $userId, 'reason' => $reason, 'id' => $drawId]);

        return $statement->rowCount() === 1;
    }

    /**
     * All draws of an event (both types), oldest first, for the CSV report.
     *
     * @return list<array<string, mixed>>
     */
    public static function allForEvent(int $eventId): array
    {
        $statement = Database::connection()->prepare(
            'SELECT d.id, d.randomizer_type, d.selected_at, d.voided_at, d.void_reason,
                    a.attendee_code, a.full_name, a.department, u.name AS drawn_by_name, v.name AS voided_by_name
             FROM randomizer_draws d
             JOIN attendees a ON a.id = d.attendee_id
             LEFT JOIN users u ON u.id = d.drawn_by
             LEFT JOIN users v ON v.id = d.voided_by
             WHERE d.event_id = :event_id
             ORDER BY d.selected_at, d.id'
        );
        $statement->execute(['event_id' => $eventId]);

        return $statement->fetchAll();
    }
}
