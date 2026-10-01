<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Minor eligible pool (read-only, derived from registration_scans) and the
 * randomizer_draws history.
 */
final class RandomizerDraw
{
    public const TYPE_MINOR = 'minor';

    /** SQL fragment: active attendees of :event_id with a check-in. */
    private const ELIGIBLE_FROM = "FROM registration_scans r
        JOIN attendees a ON a.id = r.attendee_id AND a.event_id = r.event_id
        WHERE r.event_id = :event_id AND a.status = 'active'";

    public static function countMinorEligible(int $eventId): int
    {
        $statement = Database::connection()->prepare('SELECT COUNT(*) ' . self::ELIGIBLE_FROM);
        $statement->execute(['event_id' => $eventId]);

        return (int) $statement->fetchColumn();
    }

    /** @return list<int> attendee IDs of the Minor eligible pool */
    public static function minorEligibleIds(int $eventId): array
    {
        $statement = Database::connection()->prepare('SELECT a.id ' . self::ELIGIBLE_FROM . ' ORDER BY a.id');
        $statement->execute(['event_id' => $eventId]);

        return array_map('intval', $statement->fetchAll(\PDO::FETCH_COLUMN));
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
            "SELECT d.id, d.selected_at, a.attendee_code, a.full_name, a.department, u.name AS drawn_by_name
             FROM randomizer_draws d
             JOIN attendees a ON a.id = d.attendee_id
             LEFT JOIN users u ON u.id = d.drawn_by
             WHERE d.event_id = :event_id AND d.randomizer_type = :type
             ORDER BY d.selected_at DESC, d.id DESC
             LIMIT {$limit}"
        );
        $statement->execute(['event_id' => $eventId, 'type' => $type]);

        return $statement->fetchAll();
    }
}
