<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Day-specific draw pools (read-only) and the randomizer_draws history.
 *
 * Phase 9.2 rules, always for ONE event day and ACTIVE attendees only:
 *   base pool (type)  = registered that day (registration_scans)
 *                       UNION manual additions for that type and day
 *                       (minor: minor_manual_entries; major: major_eligibility
 *                       rows with source = 'manual')
 *   draw pool (type)  = base pool MINUS attendees with a valid (not void)
 *                       draw of the same type on the same day
 * Registration is the single source of eligibility for BOTH raffles. A
 * Minor win excludes from Minor only; a Major win from Major only. Voiding a
 * draw returns the attendee to that pool. Imported Major rows from earlier
 * phases (source = 'import') are kept as history but no longer count.
 */
final class RandomizerDraw
{
    public const TYPE_MINOR = 'minor';
    public const TYPE_MAJOR = 'major';
    public const TYPES = [self::TYPE_MINOR, self::TYPE_MAJOR];

    /** Attendee ids of a type's manual additions for day :d2. */
    private const MANUAL_SQL = [
        self::TYPE_MINOR => 'SELECT attendee_id FROM minor_manual_entries WHERE event_day_id = :d2',
        self::TYPE_MAJOR => "SELECT attendee_id FROM major_eligibility WHERE event_day_id = :d2 AND source = 'manual'",
    ];

    /**
     * FROM/WHERE fragment for the draw pool of $type (alias `a` = attendee).
     * Params: d1, d2, d3 = day id, wtype = type.
     */
    private static function poolFrom(string $type): string
    {
        return 'FROM (
                SELECT attendee_id FROM registration_scans WHERE event_day_id = :d1
                UNION ' . self::MANUAL_SQL[self::normalise($type)] . '
            ) p
            JOIN attendees a ON a.id = p.attendee_id
            WHERE a.status = \'active\'
              AND NOT EXISTS (
                SELECT 1 FROM randomizer_draws w
                WHERE w.event_day_id = :d3 AND w.randomizer_type = :wtype
                  AND w.attendee_id = a.id AND w.voided_at IS NULL
              )';
    }

    /** @return array<string, int|string> */
    private static function poolParams(int $dayId, string $type): array
    {
        return ['d1' => $dayId, 'd2' => $dayId, 'd3' => $dayId, 'wtype' => self::normalise($type)];
    }

    private static function normalise(string $type): string
    {
        return $type === self::TYPE_MAJOR ? self::TYPE_MAJOR : self::TYPE_MINOR;
    }

    /** Size of the current draw pool (winners of this type today excluded). */
    public static function countEligible(int $dayId, string $type): int
    {
        $statement = Database::connection()->prepare('SELECT COUNT(*) ' . self::poolFrom($type));
        $statement->execute(self::poolParams($dayId, $type));

        return (int) $statement->fetchColumn();
    }

    /** @return list<int> attendee IDs of the current draw pool for $type */
    public static function eligibleIds(int $dayId, string $type): array
    {
        $statement = Database::connection()->prepare('SELECT a.id ' . self::poolFrom($type) . ' ORDER BY a.id');
        $statement->execute(self::poolParams($dayId, $type));

        return array_map('intval', $statement->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** Registered today, or already manually added to this type's pool today. */
    public static function inBasePool(int $dayId, string $type, int $attendeeId): bool
    {
        $statement = Database::connection()->prepare(
            'SELECT (EXISTS (SELECT 1 FROM registration_scans WHERE event_day_id = :d1 AND attendee_id = :a1)
                 OR EXISTS (SELECT 1 FROM (' . self::MANUAL_SQL[self::normalise($type)] . ') m WHERE m.attendee_id = :a2))'
        );
        $statement->execute(['d1' => $dayId, 'd2' => $dayId, 'a1' => $attendeeId, 'a2' => $attendeeId]);

        return (bool) $statement->fetchColumn();
    }

    /** Has a valid (not void) draw of $type today. */
    public static function hasValidWin(int $dayId, string $type, int $attendeeId): bool
    {
        $statement = Database::connection()->prepare(
            'SELECT COUNT(*) FROM randomizer_draws
             WHERE event_day_id = :day_id AND randomizer_type = :type AND attendee_id = :attendee_id AND voided_at IS NULL'
        );
        $statement->execute(['day_id' => $dayId, 'type' => self::normalise($type), 'attendee_id' => $attendeeId]);

        return (int) $statement->fetchColumn() > 0;
    }

    /** Serialises draws of one event day (call inside a transaction). */
    public static function lockDay(int $dayId): void
    {
        Database::connection()->prepare('SELECT id FROM event_days WHERE id = :id FOR UPDATE')->execute(['id' => $dayId]);
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
            'SELECT id, attendee_code, full_name, company, department FROM attendees WHERE id IN ('
            . implode(',', array_fill(0, count($attendeeIds), '?')) . ')'
        );
        $statement->execute($attendeeIds);

        return $statement->fetchAll();
    }

    public static function create(int $eventId, int $dayId, int $attendeeId, string $type, ?int $userId): int
    {
        Database::connection()->prepare(
            'INSERT INTO randomizer_draws (event_id, event_day_id, attendee_id, randomizer_type, drawn_by, selected_at)
             VALUES (:event_id, :day_id, :attendee_id, :type, :user_id, NOW())'
        )->execute(['event_id' => $eventId, 'day_id' => $dayId, 'attendee_id' => $attendeeId, 'type' => $type, 'user_id' => $userId]);

        return (int) Database::connection()->lastInsertId();
    }

    /** @return array<string, mixed>|null */
    public static function find(int $id): ?array
    {
        $statement = Database::connection()->prepare('SELECT id, selected_at FROM randomizer_draws WHERE id = :id');
        $statement->execute(['id' => $id]);

        return $statement->fetch() ?: null;
    }

    /** @return list<array<string, mixed>> latest draws of the day first */
    public static function recent(int $dayId, string $type, int $limit = 10): array
    {
        $limit = max(1, min(50, $limit));
        $statement = Database::connection()->prepare(
            "SELECT d.id, d.selected_at, d.voided_at, d.void_reason, a.attendee_code, a.full_name, a.company, a.department,
                    u.name AS drawn_by_name, v.name AS voided_by_name
             FROM randomizer_draws d
             JOIN attendees a ON a.id = d.attendee_id
             LEFT JOIN users u ON u.id = d.drawn_by
             LEFT JOIN users v ON v.id = d.voided_by
             WHERE d.event_day_id = :day_id AND d.randomizer_type = :type
             ORDER BY d.selected_at DESC, d.id DESC
             LIMIT {$limit}"
        );
        $statement->execute(['day_id' => $dayId, 'type' => $type]);

        return $statement->fetchAll();
    }

    /** @return array<string, mixed>|null draw of the given event day (any type) */
    public static function findInDay(int $drawId, int $dayId): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT d.id, d.event_id, d.event_day_id, d.attendee_id, d.randomizer_type, d.selected_at, d.voided_at, a.attendee_code, a.full_name
             FROM randomizer_draws d JOIN attendees a ON a.id = d.attendee_id
             WHERE d.id = :id AND d.event_day_id = :day_id LIMIT 1'
        );
        $statement->execute(['id' => $drawId, 'day_id' => $dayId]);

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
     * Draws of an event (both types), oldest first, for the CSV report.
     * $dayId limits the report to one day.
     *
     * @return list<array<string, mixed>>
     */
    public static function allForEvent(int $eventId, ?int $dayId = null): array
    {
        $sql = 'SELECT d.id, d.randomizer_type, d.selected_at, d.voided_at, d.void_reason,
                    a.attendee_code, a.full_name, a.company, a.department, u.name AS drawn_by_name, v.name AS voided_by_name,
                    ed.day_number, ed.event_date AS day_date
             FROM randomizer_draws d
             JOIN event_days ed ON ed.id = d.event_day_id
             JOIN attendees a ON a.id = d.attendee_id
             LEFT JOIN users u ON u.id = d.drawn_by
             LEFT JOIN users v ON v.id = d.voided_by
             WHERE d.event_id = :event_id';
        $params = ['event_id' => $eventId];
        if ($dayId !== null) {
            $sql .= ' AND d.event_day_id = :day_id';
            $params['day_id'] = $dayId;
        }
        $statement = Database::connection()->prepare($sql . ' ORDER BY ed.day_number, d.selected_at, d.id');
        $statement->execute($params);

        return $statement->fetchAll();
    }
}
