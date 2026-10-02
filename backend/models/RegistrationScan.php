<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Data access for `registration_scans` (successful check-ins).
 *
 * Phase 8: one row per attendee per EVENT DAY, guaranteed by the UNIQUE KEY
 * (event_day_id, attendee_id) from migration 018. The same QR checks the
 * attendee in once on each day. A second insert on the same day fails with
 * SQLSTATE 23000, which the service reports as "already registered".
 *
 *   Minor eligible (day) = registered that day (or manually added that day)
 *                          AND the attendee is still active.
 */
final class RegistrationScan
{
    /** Registered ACTIVE attendees on an event day. */
    public static function countRegisteredForDay(int $dayId): int
    {
        $statement = Database::connection()->prepare(
            "SELECT COUNT(*) FROM registration_scans r
             JOIN attendees a ON a.id = r.attendee_id
             WHERE r.event_day_id = :day_id AND a.status = 'active'"
        );
        $statement->execute(['day_id' => $dayId]);

        return (int) $statement->fetchColumn();
    }

    /** Successful check-ins on a day, all scanners or one scanner. */
    public static function countCheckIns(int $dayId, ?int $scannerUserId = null): int
    {
        $sql = 'SELECT COUNT(*) FROM registration_scans WHERE event_day_id = :day_id';
        $params = ['day_id' => $dayId];
        if ($scannerUserId !== null) {
            $sql .= ' AND scanner_user_id = :user_id';
            $params['user_id'] = $scannerUserId;
        }
        $statement = Database::connection()->prepare($sql);
        $statement->execute($params);

        return (int) $statement->fetchColumn();
    }

    /**
     * Inserts the check-in for the day. Throws PDOException 23000 if the
     * attendee is already registered on that day.
     */
    public static function create(int $eventId, int $dayId, int $attendeeId, int $qrCodeId, ?int $scannerUserId): int
    {
        Database::connection()->prepare(
            'INSERT INTO registration_scans (event_id, event_day_id, attendee_id, qr_code_id, scanner_user_id, scanned_at)
             VALUES (:event_id, :day_id, :attendee_id, :qr_code_id, :scanner_user_id, NOW())'
        )->execute([
            'event_id' => $eventId,
            'day_id' => $dayId,
            'attendee_id' => $attendeeId,
            'qr_code_id' => $qrCodeId,
            'scanner_user_id' => $scannerUserId,
        ]);

        return (int) Database::connection()->lastInsertId();
    }

    /** @return array<string, mixed>|null the attendee's check-in on the day */
    public static function findForAttendee(int $dayId, int $attendeeId): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT r.id, r.scanned_at, r.scanner_user_id, u.name AS scanner_name
             FROM registration_scans r LEFT JOIN users u ON u.id = r.scanner_user_id
             WHERE r.event_day_id = :day_id AND r.attendee_id = :attendee_id LIMIT 1'
        );
        $statement->execute(['day_id' => $dayId, 'attendee_id' => $attendeeId]);

        return $statement->fetch() ?: null;
    }

    /** @return list<array<string, mixed>> most recent check-ins of the day first */
    public static function recent(int $dayId, int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        $statement = Database::connection()->prepare(
            "SELECT r.scanned_at, a.attendee_code, a.full_name, a.company, a.department, u.name AS scanner_name
             FROM registration_scans r
             JOIN attendees a ON a.id = r.attendee_id
             LEFT JOIN users u ON u.id = r.scanner_user_id
             WHERE r.event_day_id = :day_id
             ORDER BY r.scanned_at DESC, r.id DESC
             LIMIT {$limit}"
        );
        $statement->execute(['day_id' => $dayId]);

        return $statement->fetchAll();
    }
}
