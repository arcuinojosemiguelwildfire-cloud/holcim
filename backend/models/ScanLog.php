<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Data access for `scan_logs` (Phase 8): every scan attempt per event day,
 * successful or not, for the scanner's Recent Scans list and filters.
 * Check-in counts always come from registration_scans.
 */
final class ScanLog
{
    public const RESULTS = ['registered', 'already_registered', 'invalid_qr', 'wrong_event', 'attendee_inactive'];

    /** Filter name => result values. */
    public const FILTERS = [
        'all' => [],
        'successful' => ['registered'],
        'already_registered' => ['already_registered'],
        'invalid' => ['invalid_qr', 'wrong_event', 'attendee_inactive'],
    ];

    public static function record(int $eventId, int $dayId, ?int $userId, ?int $attendeeId, string $result): void
    {
        Database::connection()->prepare(
            'INSERT INTO scan_logs (event_id, event_day_id, user_id, attendee_id, result, scanned_at)
             VALUES (:event_id, :day_id, :user_id, :attendee_id, :result, NOW())'
        )->execute([
            'event_id' => $eventId,
            'day_id' => $dayId,
            'user_id' => $userId,
            'attendee_id' => $attendeeId,
            'result' => $result,
        ]);
    }

    /**
     * Paginated scans of a day, newest first. One query for the page and one
     * for the total (no per-row lookups).
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public static function page(int $dayId, ?int $userId, string $filter, int $limit, int $offset): array
    {
        $where = ['l.event_day_id = :day_id'];
        $params = ['day_id' => $dayId];
        if ($userId !== null) {
            $where[] = 'l.user_id = :user_id';
            $params['user_id'] = $userId;
        }
        $results = self::FILTERS[$filter] ?? [];
        if ($results !== []) {
            $placeholders = [];
            foreach ($results as $i => $result) {
                $placeholders[] = ":r{$i}";
                $params["r{$i}"] = $result;
            }
            $where[] = 'l.result IN (' . implode(', ', $placeholders) . ')';
        }
        $whereSql = implode(' AND ', $where);
        $pdo = Database::connection();

        $count = $pdo->prepare("SELECT COUNT(*) FROM scan_logs l WHERE {$whereSql}");
        $count->execute($params);

        $statement = $pdo->prepare(
            "SELECT l.id, l.result, l.scanned_at, a.attendee_code, a.full_name, a.company, a.department, u.name AS scanned_by
             FROM scan_logs l
             LEFT JOIN attendees a ON a.id = l.attendee_id
             LEFT JOIN users u ON u.id = l.user_id
             WHERE {$whereSql}
             ORDER BY l.scanned_at DESC, l.id DESC
             LIMIT {$limit} OFFSET {$offset}"
        );
        $statement->execute($params);

        return ['items' => $statement->fetchAll(), 'total' => (int) $count->fetchColumn()];
    }
}
