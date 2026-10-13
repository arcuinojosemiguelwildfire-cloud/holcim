<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * The Randomizer's name pool (migration 036): the registration sessions an
 * admin selected for ONE event day, and the attendees registered in them.
 *
 * Pool rule (the existing eligibility rules of the system):
 *   an attendee is in the pool of day D when they have an ACTIVE
 *   session_registrations row (status 'active'; admin-removed registrations
 *   do not count) in at least one selected session of D, AND the attendee
 *   is active (archived attendees never count) AND belongs to the same event.
 *   Each attendee is listed once, however many selected sessions they
 *   registered in. Disabled sessions still count (their registrations are
 *   valid, as in every other report). Selection rows are configuration only:
 *   registrations and scan history are never changed by them.
 */
final class RandomizerPool
{
    /** FROM / WHERE fragment of the pool of :{$dayParam} (attendee alias `a`). */
    private static function poolFrom(string $dayParam): string
    {
        return "FROM attendees a
            WHERE a.status = 'active' AND EXISTS (
                SELECT 1 FROM randomizer_pool_sessions ps
                JOIN session_registrations sr ON sr.registration_session_id = ps.registration_session_id
                    AND sr.event_day_id = ps.event_day_id AND sr.status = 'active'
                WHERE ps.event_day_id = :{$dayParam} AND sr.attendee_id = a.id AND a.event_id = ps.event_id
            )";
    }

    /** @return list<int> selected session ids of a day */
    public static function selectedSessionIds(int $dayId): array
    {
        $statement = Database::connection()->prepare('SELECT registration_session_id FROM randomizer_pool_sessions WHERE event_day_id = :day_id ORDER BY registration_session_id');
        $statement->execute(['day_id' => $dayId]);

        return array_map('intval', $statement->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Sessions of a day with whether they are selected and their active
     * registration count (for the admin picker).
     *
     * @return list<array<string, mixed>>
     */
    public static function sessionsForDay(int $dayId): array
    {
        $statement = Database::connection()->prepare(
            "SELECT s.id, s.label, s.session_date, s.start_time, s.is_enabled, s.is_current, s.sort_order,
                    (ps.id IS NOT NULL) AS selected,
                    (SELECT COUNT(*) FROM session_registrations sr JOIN attendees a ON a.id = sr.attendee_id
                     WHERE sr.registration_session_id = s.id AND sr.status = 'active' AND a.status = 'active') AS registered
             FROM registration_sessions s
             LEFT JOIN randomizer_pool_sessions ps ON ps.registration_session_id = s.id AND ps.event_day_id = s.event_day_id
             WHERE s.event_day_id = :day_id
             ORDER BY s.session_date, s.start_time IS NULL, s.start_time, s.sort_order, s.id"
        );
        $statement->execute(['day_id' => $dayId]);

        return $statement->fetchAll();
    }

    /** Replaces the selection of a day (call inside a transaction). Session ids must be of this day. */
    public static function replace(int $eventId, int $dayId, array $sessionIds, ?int $userId): void
    {
        $pdo = Database::connection();
        $pdo->prepare('DELETE FROM randomizer_pool_sessions WHERE event_day_id = :day_id')->execute(['day_id' => $dayId]);
        $insert = $pdo->prepare('INSERT INTO randomizer_pool_sessions (event_id, event_day_id, registration_session_id, selected_by)
            VALUES (:event_id, :day_id, :session_id, :user_id)');
        foreach ($sessionIds as $sessionId) {
            $insert->execute(['event_id' => $eventId, 'day_id' => $dayId, 'session_id' => (int) $sessionId, 'user_id' => $userId]);
        }
    }

    public static function count(int $dayId): int
    {
        $statement = Database::connection()->prepare('SELECT COUNT(*) ' . self::poolFrom('d1'));
        $statement->execute(['d1' => $dayId]);

        return (int) $statement->fetchColumn();
    }

    public static function contains(int $dayId, int $attendeeId): bool
    {
        $statement = Database::connection()->prepare('SELECT 1 ' . self::poolFrom('d1') . ' AND a.id = :attendee_id LIMIT 1');
        $statement->execute(['d1' => $dayId, 'attendee_id' => $attendeeId]);

        return (bool) $statement->fetchColumn();
    }

    /** @return list<string> random sample of pool names (display only - never selects anyone) */
    public static function sampleNames(int $dayId, int $limit): array
    {
        $limit = max(1, min(200, $limit));
        $statement = Database::connection()->prepare('SELECT a.full_name ' . self::poolFrom('d1') . " ORDER BY RAND() LIMIT {$limit}");
        $statement->execute(['d1' => $dayId]);

        return array_map('strval', $statement->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Pool attendees matching a search (assignment picker).
     *
     * @return list<array<string, mixed>>
     */
    public static function search(int $dayId, string $search, int $limit = 20): array
    {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
        $limit = max(1, min(50, $limit));
        $statement = Database::connection()->prepare(
            'SELECT a.id, a.attendee_code, a.full_name, a.company, a.department, a.external_identifier ' . self::poolFrom('d1') . '
               AND (a.full_name LIKE :s1 OR a.attendee_code LIKE :s2 OR a.company LIKE :s3 OR a.external_identifier LIKE :s4)
             ORDER BY a.full_name, a.attendee_code LIMIT ' . $limit
        );
        $statement->execute(['d1' => $dayId, 's1' => $like, 's2' => $like, 's3' => $like, 's4' => $like]);

        return $statement->fetchAll();
    }

    /**
     * Which of the given attendees would be in the pool of $dayId if the
     * selection were $sessionIds (used to warn before saving).
     *
     * @param list<int> $attendeeIds
     * @param list<int> $sessionIds
     * @return list<int>
     */
    public static function inSessions(int $dayId, array $sessionIds, array $attendeeIds): array
    {
        if ($sessionIds === [] || $attendeeIds === []) {
            return [];
        }
        $sessions = implode(',', array_map('intval', $sessionIds));
        $attendees = implode(',', array_map('intval', $attendeeIds));
        $statement = Database::connection()->prepare(
            "SELECT DISTINCT a.id FROM attendees a
             JOIN session_registrations sr ON sr.attendee_id = a.id AND sr.status = 'active' AND sr.event_day_id = :day_id
             JOIN registration_sessions s ON s.id = sr.registration_session_id AND s.event_id = a.event_id
             WHERE a.status = 'active' AND a.id IN ({$attendees}) AND sr.registration_session_id IN ({$sessions})"
        );
        $statement->execute(['day_id' => $dayId]);

        return array_map('intval', $statement->fetchAll(\PDO::FETCH_COLUMN));
    }
}
