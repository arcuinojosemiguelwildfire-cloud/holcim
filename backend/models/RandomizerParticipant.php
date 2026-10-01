<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Day-specific randomizer participants (Phase 8): pool listing with source,
 * candidate search for "+ Add Participant", and manual additions.
 *
 * Manual additions never touch the attendee record, its QR, or
 * registration_scans (no check-in is created):
 *   minor -> minor_manual_entries (source manual)
 *   major -> major_eligibility with source = 'manual'
 */
final class RandomizerParticipant
{
    /**
     * Active attendees of the event matching $search, with whether they are
     * already in the day's pool (computed in the same query).
     *
     * @return list<array<string, mixed>>
     */
    public static function candidates(int $eventId, int $dayId, string $type, string $search, int $limit = 20): array
    {
        $like = '%' . addcslashes($search, '%_\\') . '%';
        $eligibleSql = $type === RandomizerDraw::TYPE_MAJOR
            ? 'EXISTS (SELECT 1 FROM major_eligibility m WHERE m.event_day_id = :d1 AND m.attendee_id = a.id)'
            : '(EXISTS (SELECT 1 FROM registration_scans r WHERE r.event_day_id = :d1 AND r.attendee_id = a.id)
                OR EXISTS (SELECT 1 FROM minor_manual_entries mm WHERE mm.event_day_id = :d2 AND mm.attendee_id = a.id))';
        $params = ['d1' => $dayId, 'event_id' => $eventId, 's1' => $like, 's2' => $like, 's3' => $like, 's4' => $like];
        if ($type !== RandomizerDraw::TYPE_MAJOR) {
            $params['d2'] = $dayId;
        }

        $statement = Database::connection()->prepare(
            "SELECT a.id, a.attendee_code, a.full_name, a.department, a.email, {$eligibleSql} AS already_eligible
             FROM attendees a
             WHERE a.event_id = :event_id AND a.status = 'active'
               AND (a.attendee_code LIKE :s1 OR a.full_name LIKE :s2 OR a.department LIKE :s3 OR a.email LIKE :s4)
             ORDER BY a.full_name, a.attendee_code
             LIMIT " . max(1, min(50, $limit))
        );
        $statement->execute($params);

        return $statement->fetchAll();
    }

    /**
     * The day's pool with the source of each participant, paginated.
     * Minor: registration first; a manual entry is listed only when the
     * attendee was not also registered that day.
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public static function pool(int $dayId, string $type, string $search, int $limit, int $offset): array
    {
        if ($type === RandomizerDraw::TYPE_MAJOR) {
            $inner = "SELECT a.id, a.attendee_code, a.full_name, a.department, a.email,
                        m.source, m.imported_at AS added_at, u.name AS added_by, m.reason
                 FROM major_eligibility m
                 JOIN attendees a ON a.id = m.attendee_id AND a.event_id = m.event_id
                 LEFT JOIN users u ON u.id = m.added_by
                 WHERE m.event_day_id = :d1 AND a.status = 'active'";
            $params = ['d1' => $dayId];
        } else {
            $inner = "SELECT a.id, a.attendee_code, a.full_name, a.department, a.email,
                        'registration' AS source, r.scanned_at AS added_at, u.name AS added_by, NULL AS reason
                 FROM registration_scans r
                 JOIN attendees a ON a.id = r.attendee_id
                 LEFT JOIN users u ON u.id = r.scanner_user_id
                 WHERE r.event_day_id = :d1 AND a.status = 'active'
                 UNION ALL
                 SELECT a.id, a.attendee_code, a.full_name, a.department, a.email,
                        'manual' AS source, mm.created_at AS added_at, u.name AS added_by, mm.reason
                 FROM minor_manual_entries mm
                 JOIN attendees a ON a.id = mm.attendee_id
                 LEFT JOIN users u ON u.id = mm.added_by
                 WHERE mm.event_day_id = :d2 AND a.status = 'active'
                   AND NOT EXISTS (SELECT 1 FROM registration_scans r2 WHERE r2.event_day_id = mm.event_day_id AND r2.attendee_id = mm.attendee_id)";
            $params = ['d1' => $dayId, 'd2' => $dayId];
        }

        $where = '';
        if ($search !== '') {
            $like = '%' . addcslashes($search, '%_\\') . '%';
            $where = 'WHERE (p.attendee_code LIKE :s1 OR p.full_name LIKE :s2 OR p.department LIKE :s3 OR p.email LIKE :s4)';
            $params += ['s1' => $like, 's2' => $like, 's3' => $like, 's4' => $like];
        }
        $pdo = Database::connection();

        $count = $pdo->prepare("SELECT COUNT(*) FROM ({$inner}) p {$where}");
        $count->execute($params);

        $statement = $pdo->prepare(
            "SELECT p.* FROM ({$inner}) p {$where} ORDER BY p.added_at DESC, p.id DESC LIMIT {$limit} OFFSET {$offset}"
        );
        $statement->execute($params);

        return ['items' => $statement->fetchAll(), 'total' => (int) $count->fetchColumn()];
    }

    /** Throws PDOException 23000 if the attendee is already manually added that day. */
    public static function addMinor(int $eventId, int $dayId, int $attendeeId, ?int $userId, ?string $reason): void
    {
        Database::connection()->prepare(
            'INSERT INTO minor_manual_entries (event_id, event_day_id, attendee_id, added_by, reason, created_at)
             VALUES (:event_id, :day_id, :attendee_id, :user_id, :reason, NOW())'
        )->execute(['event_id' => $eventId, 'day_id' => $dayId, 'attendee_id' => $attendeeId, 'user_id' => $userId, 'reason' => $reason]);
    }

    /** Throws PDOException 23000 if the attendee is already Major eligible that day. */
    public static function addMajor(int $eventId, int $dayId, int $attendeeId, ?int $userId, ?string $reason): void
    {
        Database::connection()->prepare(
            "INSERT INTO major_eligibility (event_id, event_day_id, attendee_id, import_batch_id, source, added_by, reason, imported_at)
             VALUES (:event_id, :day_id, :attendee_id, NULL, 'manual', :user_id, :reason, NOW())"
        )->execute(['event_id' => $eventId, 'day_id' => $dayId, 'attendee_id' => $attendeeId, 'user_id' => $userId, 'reason' => $reason]);
    }
}
