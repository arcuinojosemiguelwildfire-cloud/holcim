<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Data access for `registration_scans` (successful check-ins).
 *
 * One row per attendee per event, guaranteed by the UNIQUE KEY
 * (event_id, attendee_id) from migration 006. A second insert for the same
 * attendee fails with SQLSTATE 23000, which the service reports as
 * "already registered". No separate eligibility table is needed:
 *
 *   Minor eligible = has a registration_scans row for the event
 *                    AND the attendee is still active.
 */
final class RegistrationScan
{
    /** Registered (= minor eligible) ACTIVE attendees of an event. */
    public static function countRegisteredForEvent(int $eventId): int
    {
        $statement = Database::connection()->prepare(
            "SELECT COUNT(*) FROM registration_scans r
             JOIN attendees a ON a.id = r.attendee_id
             WHERE r.event_id = :event_id AND a.status = 'active'"
        );
        $statement->execute(['event_id' => $eventId]);

        return (int) $statement->fetchColumn();
    }

    /**
     * Inserts the check-in. Throws PDOException 23000 if the attendee is
     * already registered for the event.
     */
    public static function create(int $eventId, int $attendeeId, int $qrCodeId, ?int $scannerUserId): int
    {
        Database::connection()->prepare(
            'INSERT INTO registration_scans (event_id, attendee_id, qr_code_id, scanner_user_id, scanned_at)
             VALUES (:event_id, :attendee_id, :qr_code_id, :scanner_user_id, NOW())'
        )->execute([
            'event_id' => $eventId,
            'attendee_id' => $attendeeId,
            'qr_code_id' => $qrCodeId,
            'scanner_user_id' => $scannerUserId,
        ]);

        return (int) Database::connection()->lastInsertId();
    }

    /** @return array<string, mixed>|null */
    public static function findForAttendee(int $eventId, int $attendeeId): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT id, scanned_at, scanner_user_id FROM registration_scans
             WHERE event_id = :event_id AND attendee_id = :attendee_id LIMIT 1'
        );
        $statement->execute(['event_id' => $eventId, 'attendee_id' => $attendeeId]);

        return $statement->fetch() ?: null;
    }

    /** @return list<array<string, mixed>> most recent check-ins first */
    public static function recent(int $eventId, int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        $statement = Database::connection()->prepare(
            "SELECT r.scanned_at, a.attendee_code, a.full_name, a.department, u.name AS scanner_name
             FROM registration_scans r
             JOIN attendees a ON a.id = r.attendee_id
             LEFT JOIN users u ON u.id = r.scanner_user_id
             WHERE r.event_id = :event_id
             ORDER BY r.scanned_at DESC, r.id DESC
             LIMIT {$limit}"
        );
        $statement->execute(['event_id' => $eventId]);

        return $statement->fetchAll();
    }
}
