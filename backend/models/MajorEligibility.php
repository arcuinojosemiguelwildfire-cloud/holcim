<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Data access for `major_eligibility` (Phase 6).
 * Major eligible = a row here for the active event AND the attendee is active.
 */
final class MajorEligibility
{
    /** @return array<int, true> attendee IDs already eligible in the event (any attendee status) */
    public static function attendeeIdSet(int $eventId): array
    {
        $statement = Database::connection()->prepare('SELECT attendee_id FROM major_eligibility WHERE event_id = :event_id');
        $statement->execute(['event_id' => $eventId]);

        return array_fill_keys(array_map('intval', $statement->fetchAll(\PDO::FETCH_COLUMN)), true);
    }

    /** Inserts eligibility; returns false if the attendee was already eligible. */
    public static function add(int $eventId, int $attendeeId, ?int $importBatchId): bool
    {
        $statement = Database::connection()->prepare(
            'INSERT IGNORE INTO major_eligibility (event_id, attendee_id, import_batch_id, imported_at)
             VALUES (:event_id, :attendee_id, :batch_id, NOW())'
        );
        $statement->execute(['event_id' => $eventId, 'attendee_id' => $attendeeId, 'batch_id' => $importBatchId]);

        return $statement->rowCount() === 1;
    }

    /**
     * Active Major eligible attendees, paginated and searchable.
     *
     * @return array{items: list<array<string, mixed>>, total: int, departments: list<string>}
     */
    public static function search(int $eventId, string $search, ?string $department, int $limit, int $offset): array
    {
        $where = ["m.event_id = :event_id", "a.status = 'active'"];
        $params = ['event_id' => $eventId];
        if ($department !== null && $department !== '') {
            $where[] = 'a.department = :department';
            $params['department'] = $department;
        }
        if ($search !== '') {
            $like = '%' . addcslashes($search, '%_\\') . '%';
            $where[] = '(a.attendee_code LIKE :s1 OR a.full_name LIKE :s2 OR a.department LIKE :s3 OR a.email LIKE :s4)';
            foreach (['s1', 's2', 's3', 's4'] as $key) {
                $params[$key] = $like;
            }
        }
        $from = 'FROM major_eligibility m JOIN attendees a ON a.id = m.attendee_id AND a.event_id = m.event_id WHERE ' . implode(' AND ', $where);
        $pdo = Database::connection();

        $count = $pdo->prepare("SELECT COUNT(*) {$from}");
        $count->execute($params);

        $statement = $pdo->prepare(
            "SELECT a.id, a.attendee_code, a.full_name, a.department, a.email, m.imported_at {$from}
             ORDER BY a.attendee_code LIMIT {$limit} OFFSET {$offset}"
        );
        $statement->execute($params);

        $departments = $pdo->prepare(
            "SELECT DISTINCT a.department FROM major_eligibility m JOIN attendees a ON a.id = m.attendee_id
             WHERE m.event_id = :event_id AND a.status = 'active' AND a.department IS NOT NULL AND a.department <> ''
             ORDER BY a.department"
        );
        $departments->execute(['event_id' => $eventId]);

        return [
            'items' => $statement->fetchAll(),
            'total' => (int) $count->fetchColumn(),
            'departments' => array_map('strval', $departments->fetchAll(\PDO::FETCH_COLUMN)),
        ];
    }

    /** @return list<array<string, mixed>> recent Major eligibility import batches */
    public static function importHistory(int $eventId, int $limit = 20): array
    {
        $statement = Database::connection()->prepare(
            "SELECT b.id, b.original_filename, b.total_rows, b.successful_rows, b.error_summary, b.created_at, u.name AS imported_by_name
             FROM import_batches b LEFT JOIN users u ON u.id = b.imported_by
             WHERE b.event_id = :event_id AND b.import_type = 'major_entries'
             ORDER BY b.created_at DESC, b.id DESC LIMIT {$limit}"
        );
        $statement->execute(['event_id' => $eventId]);

        return $statement->fetchAll();
    }
}
