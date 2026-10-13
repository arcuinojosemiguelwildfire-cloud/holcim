<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Pre-drawn winners (migration 035): winners chosen OUTSIDE this system
 * (e.g. a Spin the Wheel app), assigned by an admin to letters A-Z for one
 * event day and revealed on the LED screen. Never part of the Minor / Major
 * Randomizer: nothing here touches randomizer_draws, pools or eligibility.
 *
 * A row is "live" while voided_at IS NULL. Among live rows of a day each
 * letter and each attendee appear once (UNIQUE keys on live_lock).
 */
final class PredrawnWinner
{
    public const STATUS_ASSIGNED = 'assigned';
    public const STATUS_REVEALED = 'revealed';
    public const STATUS_VOIDED = 'voided';

    private const SELECT = 'SELECT p.id, p.event_id, p.event_day_id, p.letter, p.attendee_id, p.prize_label,
            p.assigned_at, p.revealed_at, p.voided_at, p.void_reason,
            a.attendee_code, a.full_name, a.company, a.department, a.status AS attendee_status,
            ub.name AS assigned_by_name, ur.name AS revealed_by_name, uv.name AS voided_by_name,
            ed.day_number, ed.event_date AS day_date
        FROM predrawn_winners p
        JOIN attendees a ON a.id = p.attendee_id
        JOIN event_days ed ON ed.id = p.event_day_id
        LEFT JOIN users ub ON ub.id = p.assigned_by
        LEFT JOIN users ur ON ur.id = p.revealed_by
        LEFT JOIN users uv ON uv.id = p.voided_by';

    /** @return list<array<string, mixed>> every row of a day (live first by letter, then voided newest first) */
    public static function forDay(int $dayId): array
    {
        $statement = Database::connection()->prepare(self::SELECT . '
             WHERE p.event_day_id = :day_id
             ORDER BY p.voided_at IS NOT NULL, p.letter, p.voided_at DESC, p.id DESC');
        $statement->execute(['day_id' => $dayId]);

        return $statement->fetchAll();
    }

    /** @return list<array<string, mixed>> every row of an event (one day or all) for the report */
    public static function forEvent(int $eventId, ?int $dayId = null): array
    {
        $sql = self::SELECT . ' WHERE p.event_id = :event_id';
        $params = ['event_id' => $eventId];
        if ($dayId !== null) {
            $sql .= ' AND p.event_day_id = :day_id';
            $params['day_id'] = $dayId;
        }
        $statement = Database::connection()->prepare($sql . ' ORDER BY ed.day_number, p.letter, p.id');
        $statement->execute($params);

        return $statement->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public static function findInDay(int $id, int $dayId): ?array
    {
        $statement = Database::connection()->prepare(self::SELECT . ' WHERE p.id = :id AND p.event_day_id = :day_id LIMIT 1');
        $statement->execute(['id' => $id, 'day_id' => $dayId]);

        return $statement->fetch() ?: null;
    }

    /**
     * Live row of a day + letter. With $lock the row is locked FOR UPDATE
     * (call inside a transaction).
     *
     * @return array<string, mixed>|null
     */
    public static function liveByLetter(int $dayId, string $letter, bool $lock = false): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT p.id, p.attendee_id, p.revealed_at, p.voided_at FROM predrawn_winners p
             WHERE p.event_day_id = :day_id AND p.letter = :letter AND p.voided_at IS NULL LIMIT 1' . ($lock ? ' FOR UPDATE' : '')
        );
        $statement->execute(['day_id' => $dayId, 'letter' => $letter]);

        return $statement->fetch() ?: null;
    }

    /** @return array<string, mixed>|null live row of a day for an attendee */
    public static function liveByAttendee(int $dayId, int $attendeeId): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT id, letter FROM predrawn_winners WHERE event_day_id = :day_id AND attendee_id = :attendee_id AND voided_at IS NULL LIMIT 1'
        );
        $statement->execute(['day_id' => $dayId, 'attendee_id' => $attendeeId]);

        return $statement->fetch() ?: null;
    }

    public static function create(int $eventId, int $dayId, string $letter, int $attendeeId, ?string $prizeLabel, ?int $userId): int
    {
        Database::connection()->prepare(
            'INSERT INTO predrawn_winners (event_id, event_day_id, letter, attendee_id, prize_label, assigned_by)
             VALUES (:event_id, :day_id, :letter, :attendee_id, :prize_label, :assigned_by)'
        )->execute([
            'event_id' => $eventId, 'day_id' => $dayId, 'letter' => $letter, 'attendee_id' => $attendeeId,
            'prize_label' => $prizeLabel, 'assigned_by' => $userId,
        ]);

        return (int) Database::connection()->lastInsertId();
    }

    /** Changes an UNREVEALED live assignment; false when it was revealed or voided meanwhile. */
    public static function updateAssignment(int $id, string $letter, int $attendeeId, ?string $prizeLabel, ?int $userId): bool
    {
        $statement = Database::connection()->prepare(
            'UPDATE predrawn_winners SET letter = :letter, attendee_id = :attendee_id, prize_label = :prize_label,
                    assigned_by = :assigned_by, assigned_at = NOW()
             WHERE id = :id AND revealed_at IS NULL AND voided_at IS NULL'
        );
        $statement->execute(['letter' => $letter, 'attendee_id' => $attendeeId, 'prize_label' => $prizeLabel, 'assigned_by' => $userId, 'id' => $id]);

        return $statement->rowCount() === 1;
    }

    /** Removes an UNREVEALED live assignment (revealed rows are never deleted). */
    public static function deleteUnrevealed(int $id): bool
    {
        $statement = Database::connection()->prepare('DELETE FROM predrawn_winners WHERE id = :id AND revealed_at IS NULL AND voided_at IS NULL');
        $statement->execute(['id' => $id]);

        return $statement->rowCount() === 1;
    }

    /** Marks a live, unrevealed row as revealed; false if it was revealed / voided meanwhile. */
    public static function markRevealed(int $id, ?int $userId): bool
    {
        $statement = Database::connection()->prepare(
            'UPDATE predrawn_winners SET revealed_at = NOW(), revealed_by = :user_id
             WHERE id = :id AND revealed_at IS NULL AND voided_at IS NULL'
        );
        $statement->execute(['user_id' => $userId, 'id' => $id]);

        return $statement->rowCount() === 1;
    }

    /** Voids a revealed live row (kept for the audit trail); false if not revealed or already voided. */
    public static function voidReveal(int $id, ?int $userId, ?string $reason): bool
    {
        $statement = Database::connection()->prepare(
            'UPDATE predrawn_winners SET voided_at = NOW(), voided_by = :user_id, void_reason = :reason
             WHERE id = :id AND revealed_at IS NOT NULL AND voided_at IS NULL'
        );
        $statement->execute(['user_id' => $userId, 'reason' => $reason, 'id' => $id]);

        return $statement->rowCount() === 1;
    }

    /** @param array<string, mixed> $row */
    public static function status(array $row): string
    {
        return $row['voided_at'] !== null ? self::STATUS_VOIDED : ($row['revealed_at'] !== null ? self::STATUS_REVEALED : self::STATUS_ASSIGNED);
    }
}
