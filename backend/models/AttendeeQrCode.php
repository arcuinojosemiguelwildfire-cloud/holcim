<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Data access for `attendee_qr_codes`: exactly one current QR record per
 * attendee. Regeneration replaces the token in place (updated_at = issue time).
 */
final class AttendeeQrCode
{
    /** @return array{id: int, attendee_id: int, token: string, created_at: string, updated_at: string}|null */
    public static function findByAttendee(int $attendeeId): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT id, attendee_id, token, created_at, updated_at FROM attendee_qr_codes WHERE attendee_id = :id LIMIT 1'
        );
        $statement->execute(['id' => $attendeeId]);

        return $statement->fetch() ?: null;
    }

    public static function create(int $attendeeId, string $token): void
    {
        Database::connection()
            ->prepare('INSERT INTO attendee_qr_codes (attendee_id, token) VALUES (:attendee_id, :token)')
            ->execute(['attendee_id' => $attendeeId, 'token' => $token]);
    }

    /** Replaces the token; the old token stops existing immediately. */
    public static function replaceToken(int $attendeeId, string $token): void
    {
        Database::connection()
            ->prepare('UPDATE attendee_qr_codes SET token = :token, updated_at = NOW() WHERE attendee_id = :attendee_id')
            ->execute(['token' => $token, 'attendee_id' => $attendeeId]);
    }

    /** @return list<int> IDs of ACTIVE attendees of the event that have no QR yet */
    public static function activeAttendeeIdsWithoutQr(int $eventId): array
    {
        $statement = Database::connection()->prepare(
            "SELECT a.id FROM attendees a
             LEFT JOIN attendee_qr_codes q ON q.attendee_id = a.id
             WHERE a.event_id = :event_id AND a.status = 'active' AND q.id IS NULL
             ORDER BY a.attendee_code"
        );
        $statement->execute(['event_id' => $eventId]);

        return array_map('intval', $statement->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** @return array{active: int, generated: int} counts for ACTIVE attendees */
    public static function countsForEvent(int $eventId): array
    {
        $statement = Database::connection()->prepare(
            "SELECT COUNT(*) AS active, COUNT(q.id) AS generated
             FROM attendees a LEFT JOIN attendee_qr_codes q ON q.attendee_id = a.id
             WHERE a.event_id = :event_id AND a.status = 'active'"
        );
        $statement->execute(['event_id' => $eventId]);
        $row = $statement->fetch() ?: ['active' => 0, 'generated' => 0];

        return ['active' => (int) $row['active'], 'generated' => (int) $row['generated']];
    }

    /**
     * Print data for ACTIVE attendees of the event that have a QR, optionally
     * limited to $attendeeIds (IDs from other events are simply not matched).
     *
     * @param list<int>|null $attendeeIds
     * @return list<array<string, mixed>>
     */
    public static function printRows(int $eventId, ?array $attendeeIds): array
    {
        $sql = "SELECT a.id, a.attendee_code, a.full_name, a.company, a.department, q.token
                FROM attendees a JOIN attendee_qr_codes q ON q.attendee_id = a.id
                WHERE a.event_id = ? AND a.status = 'active'";
        $params = [$eventId];
        if ($attendeeIds !== null) {
            if ($attendeeIds === []) {
                return [];
            }
            $sql .= ' AND a.id IN (' . implode(',', array_fill(0, count($attendeeIds), '?')) . ')';
            array_push($params, ...$attendeeIds);
        }
        $statement = Database::connection()->prepare($sql . ' ORDER BY a.attendee_code');
        $statement->execute($params);

        return $statement->fetchAll();
    }
}
