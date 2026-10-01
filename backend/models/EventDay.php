<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Data access for `event_days` (Phase 8). An event runs over one or more
 * days; at most one day per event is 'active' (UNIQUE active_lock).
 */
final class EventDay
{
    public const STATUS_UPCOMING = 'upcoming';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_COMPLETED = 'completed';

    public const STATUSES = [self::STATUS_UPCOMING, self::STATUS_ACTIVE, self::STATUS_COMPLETED];

    private const COLUMNS = 'id, event_id, day_number, event_date, label, status, created_at, updated_at';

    /** @return list<array<string, mixed>> */
    public static function forEvent(int $eventId): array
    {
        $statement = Database::connection()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM event_days WHERE event_id = :event_id ORDER BY day_number'
        );
        $statement->execute(['event_id' => $eventId]);

        return $statement->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public static function find(int $id): ?array
    {
        $statement = Database::connection()->prepare('SELECT ' . self::COLUMNS . ' FROM event_days WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $id]);

        return $statement->fetch() ?: null;
    }

    /** @return array<string, mixed>|null */
    public static function findActiveForEvent(int $eventId): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT ' . self::COLUMNS . " FROM event_days WHERE event_id = :event_id AND status = 'active' LIMIT 1"
        );
        $statement->execute(['event_id' => $eventId]);

        return $statement->fetch() ?: null;
    }

    public static function nextDayNumber(int $eventId): int
    {
        $statement = Database::connection()->prepare('SELECT COALESCE(MAX(day_number), 0) + 1 FROM event_days WHERE event_id = :event_id');
        $statement->execute(['event_id' => $eventId]);

        return (int) $statement->fetchColumn();
    }

    public static function create(int $eventId, int $dayNumber, string $date, ?string $label, string $status): int
    {
        Database::connection()->prepare(
            'INSERT INTO event_days (event_id, day_number, event_date, label, status)
             VALUES (:event_id, :day_number, :event_date, :label, :status)'
        )->execute([
            'event_id' => $eventId,
            'day_number' => $dayNumber,
            'event_date' => $date,
            'label' => $label,
            'status' => $status,
        ]);

        return (int) Database::connection()->lastInsertId();
    }

    public static function updateDetails(int $id, string $date, ?string $label): void
    {
        Database::connection()->prepare('UPDATE event_days SET event_date = :event_date, label = :label WHERE id = :id')
            ->execute(['event_date' => $date, 'label' => $label, 'id' => $id]);
    }

    public static function setStatus(int $id, string $status): void
    {
        Database::connection()->prepare('UPDATE event_days SET status = :status WHERE id = :id')
            ->execute(['status' => $status, 'id' => $id]);
    }

    /** Locks the event's day rows (call inside a transaction). */
    public static function lockForEvent(int $eventId): void
    {
        Database::connection()->prepare('SELECT id FROM event_days WHERE event_id = :event_id FOR UPDATE')
            ->execute(['event_id' => $eventId]);
    }

    /** "Day 2 — October 11, 2026" (label appended when set). */
    public static function displayName(array $day): string
    {
        $date = date('F j, Y', (int) strtotime((string) $day['event_date']));
        $name = "Day {$day['day_number']} — {$date}";

        return $day['label'] !== null && $day['label'] !== '' ? "{$name} ({$day['label']})" : $name;
    }

    /** Short form for CSV cells: "Day 2 (2026-10-11)". */
    public static function shortName(array $day): string
    {
        return "Day {$day['day_number']} ({$day['event_date']})";
    }

    /**
     * Status shown to users, derived from the current day (Phase 8.1):
     * the active day is "active", days before it "completed", days after it
     * "upcoming". The stored status column only marks the active day (it is
     * the current-day pointer); no day's records depend on it.
     * Without a current day the stored value is shown.
     */
    public static function displayStatus(array $day, ?int $currentDayNumber): string
    {
        if ($day['status'] === self::STATUS_ACTIVE) {
            return self::STATUS_ACTIVE;
        }
        if ($currentDayNumber === null) {
            return (string) $day['status'];
        }

        return (int) $day['day_number'] < $currentDayNumber ? self::STATUS_COMPLETED : self::STATUS_UPCOMING;
    }

    /**
     * @param array<string, mixed> $day
     * @param int|null $currentDayNumber day_number of the event's active day, for displayStatus()
     * @return array<string, mixed>
     */
    public static function toPublic(array $day, ?int $currentDayNumber = null): array
    {
        return [
            'id' => (int) $day['id'],
            'eventId' => (int) $day['event_id'],
            'dayNumber' => (int) $day['day_number'],
            'eventDate' => $day['event_date'],
            'label' => $day['label'],
            'status' => self::displayStatus($day, $currentDayNumber),
            'displayName' => self::displayName($day),
        ];
    }
}
