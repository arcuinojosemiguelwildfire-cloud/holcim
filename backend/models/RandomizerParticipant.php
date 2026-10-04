<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Day-specific randomizer participants: pool listing with source, candidate
 * search for "+ Add Participant", and manual additions.
 *
 * Phase 9.2: registration is the source of eligibility for both raffles.
 * Manual additions are an exception for attendees who could not be scanned
 * and affect ONE randomizer only (minor -> minor_manual_entries, major ->
 * major_eligibility with source = 'manual'). They never touch the attendee
 * record, its QR, or registration_scans. Pools exclude anyone with a valid
 * draw of the same randomizer that day.
 */
final class RandomizerParticipant
{
    /** Manual additions of a type for day :d2. */
    private const MANUAL = [
        RandomizerDraw::TYPE_MINOR => 'SELECT attendee_id, created_at, added_by, reason FROM minor_manual_entries WHERE event_day_id = :d2',
        RandomizerDraw::TYPE_MAJOR => "SELECT attendee_id, imported_at AS created_at, added_by, reason FROM major_eligibility WHERE event_day_id = :d2 AND source = 'manual'",
    ];

    private static function type(string $type): string
    {
        return $type === RandomizerDraw::TYPE_MAJOR ? RandomizerDraw::TYPE_MAJOR : RandomizerDraw::TYPE_MINOR;
    }

    /**
     * Active attendees of the event matching $search, with whether they are
     * already in today's pool for $type and whether they already won it.
     *
     * @return list<array<string, mixed>>
     */
    public static function candidates(int $eventId, int $dayId, string $type, string $search, int $limit = 20): array
    {
        $type = self::type($type);
        $like = '%' . addcslashes($search, '%_\\') . '%';
        $statement = Database::connection()->prepare(
            "SELECT a.id, a.attendee_code, a.full_name, a.company, a.department, a.email,
                    (EXISTS (SELECT 1 FROM registration_scans r WHERE r.event_day_id = :d1 AND r.attendee_id = a.id)
                     OR EXISTS (SELECT 1 FROM (" . self::MANUAL[$type] . ") m WHERE m.attendee_id = a.id)) AS already_eligible,
                    EXISTS (SELECT 1 FROM randomizer_draws w WHERE w.event_day_id = :d3 AND w.randomizer_type = :wtype
                            AND w.attendee_id = a.id AND " . RandomizerDraw::EXCLUDES . ") AS already_won
             FROM attendees a
             WHERE a.event_id = :event_id AND a.status = 'active'
               AND (a.attendee_code LIKE :s1 OR a.full_name LIKE :s2 OR a.department LIKE :s3 OR a.email LIKE :s4 OR a.company LIKE :s5)
             ORDER BY a.full_name, a.attendee_code
             LIMIT " . max(1, min(50, $limit))
        );
        $statement->execute([
            'd1' => $dayId, 'd2' => $dayId, 'd3' => $dayId, 'wtype' => $type, 'event_id' => $eventId,
            's1' => $like, 's2' => $like, 's3' => $like, 's4' => $like, 's5' => $like,
        ]);

        return $statement->fetchAll();
    }

    /**
     * Today's draw pool for $type with the source of each participant
     * (registration first; a manual entry only if not also registered),
     * excluding today's valid winners of $type. Paginated.
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public static function pool(int $dayId, string $type, string $search, int $limit, int $offset): array
    {
        $type = self::type($type);
        $inner = "SELECT a.id, a.attendee_code, a.full_name, a.company, a.department, a.email,
                    'registration' AS source, r.scanned_at AS added_at, u.name AS added_by, NULL AS reason
                 FROM registration_scans r
                 JOIN attendees a ON a.id = r.attendee_id
                 LEFT JOIN users u ON u.id = r.scanner_user_id
                 WHERE r.event_day_id = :d1 AND a.status = 'active'
                 UNION ALL
                 SELECT a.id, a.attendee_code, a.full_name, a.company, a.department, a.email,
                    'manual' AS source, m.created_at AS added_at, u.name AS added_by, m.reason
                 FROM (" . self::MANUAL[$type] . ") m
                 JOIN attendees a ON a.id = m.attendee_id
                 LEFT JOIN users u ON u.id = m.added_by
                 WHERE a.status = 'active'
                   AND NOT EXISTS (SELECT 1 FROM registration_scans r2 WHERE r2.event_day_id = :d4 AND r2.attendee_id = m.attendee_id)";
        $params = ['d1' => $dayId, 'd2' => $dayId, 'd4' => $dayId, 'd3' => $dayId, 'wtype' => $type];

        $where = "WHERE NOT EXISTS (SELECT 1 FROM randomizer_draws w WHERE w.event_day_id = :d3 AND w.randomizer_type = :wtype
                    AND w.attendee_id = p.id AND " . RandomizerDraw::EXCLUDES . ")";
        if ($search !== '') {
            $like = '%' . addcslashes($search, '%_\\') . '%';
            $where .= ' AND (p.attendee_code LIKE :s1 OR p.full_name LIKE :s2 OR p.department LIKE :s3 OR p.email LIKE :s4 OR p.company LIKE :s5)';
            $params += ['s1' => $like, 's2' => $like, 's3' => $like, 's4' => $like, 's5' => $like];
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

    /**
     * Manual Major addition for the day. A leftover pre-Phase 9.2 imported row
     * for the same day and attendee (no longer counted) is converted to manual
     * instead of failing. Throws PDOException 23000 if a manual row exists.
     */
    public static function addMajor(int $eventId, int $dayId, int $attendeeId, ?int $userId, ?string $reason): void
    {
        $pdo = Database::connection();
        $converted = $pdo->prepare(
            "UPDATE major_eligibility SET source = 'manual', added_by = :user_id, reason = :reason, imported_at = NOW()
             WHERE event_day_id = :day_id AND attendee_id = :attendee_id AND source = 'import'"
        );
        $converted->execute(['user_id' => $userId, 'reason' => $reason, 'day_id' => $dayId, 'attendee_id' => $attendeeId]);
        if ($converted->rowCount() === 1) {
            return;
        }
        $pdo->prepare(
            "INSERT INTO major_eligibility (event_id, event_day_id, attendee_id, import_batch_id, source, added_by, reason, imported_at)
             VALUES (:event_id, :day_id, :attendee_id, NULL, 'manual', :user_id, :reason, NOW())"
        )->execute(['event_id' => $eventId, 'day_id' => $dayId, 'attendee_id' => $attendeeId, 'user_id' => $userId, 'reason' => $reason]);
    }
}
