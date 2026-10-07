<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Data access for `session_registrations` and `session_registration_changes`
 * (Phase 10, migration 033): one row per attendee per registration session,
 * guaranteed by UNIQUE (attendee_id, registration_session_id). The attendee
 * record and its QR code are never touched here.
 *
 * Rows are never deleted by the application. An admin removal sets
 * status = 'removed'; every scan, manual add, restore and removal is also
 * appended to session_registration_changes (who, when, why).
 *
 * Eligibility (always derived from these rows on the server):
 *   registered for a day   = an ACTIVE registration in ANY session of the day
 *   Major-qualifying       = an ACTIVE registration in a session of the day
 *                            with counts_for_major = 1
 */
final class SessionRegistration
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_REMOVED = 'removed';
    public const SOURCE_SCAN = 'scan';
    public const SOURCE_MANUAL = 'manual';

    /**
     * SELECT of attendee ids eligible from registration on day :$dayParam -
     * every session for Minor, Major-qualifying sessions only for Major.
     * $alias names the session_registrations alias so callers can append
     * "AND {alias}.attendee_id = ...". Parameter names are unique per call
     * because PDO uses native prepares (a name cannot be repeated).
     */
    public static function eligibleSql(string $type, string $dayParam, string $alias = 'sr'): string
    {
        $session = $alias . '_s';
        if ($type === RandomizerDraw::TYPE_MAJOR) {
            return "SELECT {$alias}.attendee_id FROM session_registrations {$alias}
                JOIN registration_sessions {$session} ON {$session}.id = {$alias}.registration_session_id
                WHERE {$alias}.event_day_id = :{$dayParam} AND {$alias}.status = 'active' AND {$session}.counts_for_major = 1";
        }

        return "SELECT {$alias}.attendee_id FROM session_registrations {$alias}
            WHERE {$alias}.event_day_id = :{$dayParam} AND {$alias}.status = 'active'";
    }

    /**
     * One row per attendee: their EARLIEST qualifying registration on day
     * :$dayParam (for pool listings), columns attendee_id, registered_at,
     * registered_by, session_label. Portable SQL (no GROUP_CONCAT).
     */
    public static function firstEligibleSql(string $type, string $dayParam): string
    {
        $major = $type === RandomizerDraw::TYPE_MAJOR;

        return "SELECT f.attendee_id, f.registered_at, f.registered_by, fs.label AS session_label
            FROM session_registrations f
            JOIN registration_sessions fs ON fs.id = f.registration_session_id
            WHERE f.event_day_id = :{$dayParam} AND f.status = 'active'" . ($major ? ' AND fs.counts_for_major = 1' : '') . "
              AND NOT EXISTS (
                SELECT 1 FROM session_registrations e
                JOIN registration_sessions es ON es.id = e.registration_session_id
                WHERE e.attendee_id = f.attendee_id AND e.event_day_id = f.event_day_id AND e.status = 'active'"
                . ($major ? ' AND es.counts_for_major = 1' : '') . "
                  AND (e.registered_at < f.registered_at OR (e.registered_at = f.registered_at AND e.id < f.id))
              )";
    }

    /** @return array<string, mixed>|null */
    public static function findForSession(int $sessionId, int $attendeeId, bool $lock = false): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT r.id, r.event_id, r.event_day_id, r.registration_session_id, r.attendee_id, r.status, r.source,
                    r.registered_at, r.registered_by, u.name AS registered_by_name, r.removed_at, r.removal_reason
             FROM session_registrations r LEFT JOIN users u ON u.id = r.registered_by
             WHERE r.registration_session_id = :session_id AND r.attendee_id = :attendee_id LIMIT 1'
            . ($lock ? ' FOR UPDATE' : '')
        );
        $statement->execute(['session_id' => $sessionId, 'attendee_id' => $attendeeId]);

        return $statement->fetch() ?: null;
    }

    /** Inserts an ACTIVE registration. Throws PDOException 23000 if a row for attendee + session exists. */
    public static function create(int $eventId, int $dayId, int $sessionId, int $attendeeId, string $source, ?int $userId): int
    {
        Database::connection()->prepare(
            "INSERT INTO session_registrations
                (event_id, event_day_id, registration_session_id, attendee_id, status, source, registered_at, registered_by)
             VALUES (:event_id, :day_id, :session_id, :attendee_id, 'active', :source, NOW(), :user_id)"
        )->execute([
            'event_id' => $eventId, 'day_id' => $dayId, 'session_id' => $sessionId,
            'attendee_id' => $attendeeId, 'source' => $source, 'user_id' => $userId,
        ]);

        return (int) Database::connection()->lastInsertId();
    }

    /**
     * Re-activates a REMOVED registration as a manual one (the earlier
     * registration and its removal stay in session_registration_changes).
     */
    public static function restore(int $id, ?int $userId): bool
    {
        $statement = Database::connection()->prepare(
            "UPDATE session_registrations
             SET status = 'active', source = 'manual', registered_at = NOW(), registered_by = :user_id,
                 removed_at = NULL, removed_by = NULL, removal_reason = NULL
             WHERE id = :id AND status = 'removed'"
        );
        $statement->execute(['user_id' => $userId, 'id' => $id]);

        return $statement->rowCount() === 1;
    }

    /** Marks an ACTIVE registration as removed (the row is kept). */
    public static function remove(int $id, ?int $userId, string $reason): bool
    {
        $statement = Database::connection()->prepare(
            "UPDATE session_registrations SET status = 'removed', removed_at = NOW(), removed_by = :user_id, removal_reason = :reason
             WHERE id = :id AND status = 'active'"
        );
        $statement->execute(['user_id' => $userId, 'reason' => $reason, 'id' => $id]);

        return $statement->rowCount() === 1;
    }

    public static function addChange(int $registrationId, int $eventId, string $action, string $statusAfter, ?int $userId, ?string $reason = null): void
    {
        Database::connection()->prepare(
            'INSERT INTO session_registration_changes (session_registration_id, event_id, action, status_after, performed_by, reason, created_at)
             VALUES (:registration_id, :event_id, :action, :status_after, :user_id, :reason, NOW())'
        )->execute([
            'registration_id' => $registrationId, 'event_id' => $eventId, 'action' => $action,
            'status_after' => $statusAfter, 'user_id' => $userId, 'reason' => $reason,
        ]);
    }

    /**
     * Every registration row of an attendee (active and removed) with its session.
     *
     * @return list<array<string, mixed>>
     */
    public static function forAttendee(int $attendeeId): array
    {
        $statement = Database::connection()->prepare(
            'SELECT r.id, r.registration_session_id, r.event_day_id, r.status, r.source, r.registered_at, r.removed_at, r.removal_reason,
                    r.created_at, r.updated_at, u.name AS registered_by_name, ru.name AS removed_by_name
             FROM session_registrations r
             LEFT JOIN users u ON u.id = r.registered_by
             LEFT JOIN users ru ON ru.id = r.removed_by
             WHERE r.attendee_id = :attendee_id'
        );
        $statement->execute(['attendee_id' => $attendeeId]);

        return $statement->fetchAll();
    }

    /**
     * Change history of an attendee's registrations, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public static function changesForAttendee(int $attendeeId, int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        $statement = Database::connection()->prepare(
            "SELECT c.id, c.action, c.status_after, c.reason, c.created_at, u.name AS performed_by_name,
                    s.label AS session_label, s.session_date, s.start_time, d.day_number
             FROM session_registration_changes c
             JOIN session_registrations r ON r.id = c.session_registration_id
             JOIN registration_sessions s ON s.id = r.registration_session_id
             JOIN event_days d ON d.id = r.event_day_id
             LEFT JOIN users u ON u.id = c.performed_by
             WHERE r.attendee_id = :attendee_id
             ORDER BY c.created_at DESC, c.id DESC
             LIMIT {$limit}"
        );
        $statement->execute(['attendee_id' => $attendeeId]);

        return $statement->fetchAll();
    }

    /** ACTIVE attendees with an active registration in the session. */
    public static function countForSession(int $sessionId): int
    {
        $statement = Database::connection()->prepare(
            "SELECT COUNT(*) FROM session_registrations r JOIN attendees a ON a.id = r.attendee_id
             WHERE r.registration_session_id = :session_id AND r.status = 'active' AND a.status = 'active'"
        );
        $statement->execute(['session_id' => $sessionId]);

        return (int) $statement->fetchColumn();
    }

    /** ACTIVE attendees registered (any session) on a day - each attendee once. */
    public static function countForDay(int $dayId): int
    {
        $statement = Database::connection()->prepare(
            "SELECT COUNT(DISTINCT r.attendee_id) FROM session_registrations r JOIN attendees a ON a.id = r.attendee_id
             WHERE r.event_day_id = :day_id AND r.status = 'active' AND a.status = 'active'"
        );
        $statement->execute(['day_id' => $dayId]);

        return (int) $statement->fetchColumn();
    }

    /** Successful QR registrations in a session, by one scanner or by everyone. */
    public static function countScans(int $sessionId, ?int $userId = null): int
    {
        $sql = "SELECT COUNT(*) FROM session_registrations WHERE registration_session_id = :session_id AND source = 'scan' AND status = 'active'";
        $params = ['session_id' => $sessionId];
        if ($userId !== null) {
            $sql .= ' AND registered_by = :user_id';
            $params['user_id'] = $userId;
        }
        $statement = Database::connection()->prepare($sql);
        $statement->execute($params);

        return (int) $statement->fetchColumn();
    }

    /** @return array<int, int> successful QR registrations per scanner user on a day */
    public static function scansPerUserForDay(int $dayId): array
    {
        $statement = Database::connection()->prepare(
            "SELECT registered_by, COUNT(*) AS total FROM session_registrations
             WHERE event_day_id = :day_id AND source = 'scan' AND status = 'active' AND registered_by IS NOT NULL
             GROUP BY registered_by"
        );
        $statement->execute(['day_id' => $dayId]);
        $out = [];
        foreach ($statement->fetchAll() as $row) {
            $out[(int) $row['registered_by']] = (int) $row['total'];
        }

        return $out;
    }

    /**
     * Most recent registrations of a session, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public static function recentForSession(int $sessionId, int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        $statement = Database::connection()->prepare(
            "SELECT r.registered_at, r.source, a.attendee_code, a.full_name, a.company, a.department, u.name AS registered_by_name
             FROM session_registrations r
             JOIN attendees a ON a.id = r.attendee_id
             LEFT JOIN users u ON u.id = r.registered_by
             WHERE r.registration_session_id = :session_id AND r.status = 'active'
             ORDER BY r.registered_at DESC, r.id DESC
             LIMIT {$limit}"
        );
        $statement->execute(['session_id' => $sessionId]);

        return $statement->fetchAll();
    }

    /** Has an active Major-qualifying registration on the day. */
    public static function isMajorQualified(int $dayId, int $attendeeId): bool
    {
        $statement = Database::connection()->prepare(
            'SELECT EXISTS (' . self::eligibleSql(RandomizerDraw::TYPE_MAJOR, 'd1') . ' AND sr.attendee_id = :attendee_id)'
        );
        $statement->execute(['d1' => $dayId, 'attendee_id' => $attendeeId]);

        return (bool) $statement->fetchColumn();
    }
}
