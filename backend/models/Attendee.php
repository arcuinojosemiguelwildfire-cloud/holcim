<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Data access for `attendees`. Attendees are soft-deleted (status = archived)
 * and their attendee_code never changes once assigned.
 */
final class Attendee
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_ARCHIVED = 'archived';
    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_ARCHIVED];

    public const CODE_PREFIX = 'ATT-';

    /**
     * Business fields (Phase 9.2): full_name, company (new), department =
     * Cluster (location/region; the column name is kept for compatibility).
     */
    private const COLUMNS = 'id, event_id, attendee_code, full_name, company, department, email, external_identifier,
        status, archived_at, extra_data, import_batch_id, created_at, updated_at';

    /** Columns an admin may edit. attendee_code is deliberately excluded. */
    private const EDITABLE = ['full_name', 'company', 'department', 'email', 'external_identifier'];

    /** Active attendees for an event (dashboard metric). */
    public static function countForEvent(int $eventId): int
    {
        $statement = Database::connection()->prepare(
            'SELECT COUNT(*) FROM attendees WHERE event_id = :event_id AND status = :status'
        );
        $statement->execute(['event_id' => $eventId, 'status' => self::STATUS_ACTIVE]);

        return (int) $statement->fetchColumn();
    }

    /**
     * Paginated search. $search matches code, name, department, email and
     * external ID. $qrStatus (generated|missing) filters on the QR record;
     * every row carries qr_generated_at (NULL when no QR exists).
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public static function search(int $eventId, string $search, ?string $department, ?string $status, int $limit, int $offset, ?string $qrStatus = null): array
    {
        $where = ['a.event_id = :event_id'];
        $params = ['event_id' => $eventId];

        if ($status !== null) {
            $where[] = 'a.status = :status';
            $params['status'] = $status;
        }
        if ($department !== null && $department !== '') {
            $where[] = 'a.department = :department';
            $params['department'] = $department;
        }
        if ($qrStatus === 'generated') {
            $where[] = 'q.id IS NOT NULL';
        } elseif ($qrStatus === 'missing') {
            $where[] = 'q.id IS NULL';
        }
        if ($search !== '') {
            $like = '%' . addcslashes($search, '%_\\') . '%';
            $where[] = '(a.attendee_code LIKE :s1 OR a.full_name LIKE :s2 OR a.department LIKE :s3 OR a.email LIKE :s4
                OR a.external_identifier LIKE :s5 OR a.company LIKE :s6)';
            foreach (['s1', 's2', 's3', 's4', 's5', 's6'] as $key) {
                $params[$key] = $like;
            }
        }

        $from = 'FROM attendees a LEFT JOIN attendee_qr_codes q ON q.attendee_id = a.id WHERE ' . implode(' AND ', $where);
        $pdo = Database::connection();

        $count = $pdo->prepare("SELECT COUNT(*) {$from}");
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $columns = implode(', ', array_map(static fn (string $c): string => 'a.' . trim($c), explode(',', self::COLUMNS)));
        $statement = $pdo->prepare(
            "SELECT {$columns}, q.updated_at AS qr_generated_at {$from}
             ORDER BY a.attendee_code ASC LIMIT {$limit} OFFSET {$offset}"
        );
        $statement->execute($params);

        return ['items' => $statement->fetchAll(), 'total' => $total];
    }

    /** @return list<string> distinct non-empty departments of active attendees */
    public static function departments(int $eventId): array
    {
        $statement = Database::connection()->prepare(
            "SELECT DISTINCT department FROM attendees
             WHERE event_id = :event_id AND department IS NOT NULL AND department <> ''
             ORDER BY department"
        );
        $statement->execute(['event_id' => $eventId]);

        return array_map('strval', $statement->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** @return array<string, mixed>|null */
    public static function find(int $id): ?array
    {
        $statement = Database::connection()->prepare('SELECT ' . self::COLUMNS . ' FROM attendees WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $id]);

        return $statement->fetch() ?: null;
    }

    /**
     * Minimal fields for duplicate detection, all statuses.
     *
     * @return list<array{id: int, attendee_code: string, full_name: string, department: ?string, email: ?string, external_identifier: ?string, status: string}>
     */
    public static function identityRowsForEvent(int $eventId): array
    {
        $statement = Database::connection()->prepare(
            'SELECT id, attendee_code, full_name, company, department, email, external_identifier, status
             FROM attendees WHERE event_id = :event_id'
        );
        $statement->execute(['event_id' => $eventId]);

        return $statement->fetchAll();
    }

    /** Highest numeric suffix of ATT-#### codes in the event (0 if none). */
    public static function maxCodeNumber(int $eventId): int
    {
        $statement = Database::connection()->prepare(
            "SELECT MAX(CAST(SUBSTRING(attendee_code, :offset) AS UNSIGNED))
             FROM attendees WHERE event_id = :event_id AND attendee_code LIKE :prefix"
        );
        $statement->execute([
            'offset' => strlen(self::CODE_PREFIX) + 1,
            'event_id' => $eventId,
            'prefix' => self::CODE_PREFIX . '%',
        ]);

        return (int) $statement->fetchColumn();
    }

    public static function formatCode(int $number): string
    {
        return self::CODE_PREFIX . str_pad((string) $number, 4, '0', STR_PAD_LEFT);
    }

    /** @param array<string, mixed> $data */
    public static function create(int $eventId, string $code, array $data, ?int $importBatchId): int
    {
        $statement = Database::connection()->prepare(
            'INSERT INTO attendees (event_id, attendee_code, full_name, company, department, email, external_identifier, extra_data, import_batch_id)
             VALUES (:event_id, :code, :full_name, :company, :department, :email, :external_identifier, :extra_data, :import_batch_id)'
        );
        $statement->execute([
            'event_id' => $eventId,
            'code' => $code,
            'full_name' => $data['full_name'],
            'company' => $data['company'] ?? null,
            'department' => $data['department'] ?? null,
            'email' => $data['email'] ?? null,
            'external_identifier' => $data['external_identifier'] ?? null,
            'extra_data' => !empty($data['extra_data'])
                ? json_encode($data['extra_data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
                : null,
            'import_batch_id' => $importBatchId,
        ]);

        return (int) Database::connection()->lastInsertId();
    }

    /** @param array<string, mixed> $data */
    public static function update(int $id, array $data): void
    {
        $fields = array_values(array_intersect(self::EDITABLE, array_keys($data)));
        if ($fields === []) {
            return;
        }
        $assignments = implode(', ', array_map(static fn (string $f): string => "{$f} = :{$f}", $fields));
        $params = ['id' => $id];
        foreach ($fields as $field) {
            $params[$field] = $data[$field];
        }

        Database::connection()->prepare("UPDATE attendees SET {$assignments} WHERE id = :id")->execute($params);
    }

    public static function setStatus(int $id, string $status): void
    {
        $statement = Database::connection()->prepare(
            'UPDATE attendees SET status = :status,
                archived_at = IF(:status2 = \'archived\', NOW(), NULL)
             WHERE id = :id'
        );
        $statement->execute(['status' => $status, 'status2' => $status, 'id' => $id]);
    }

    /** Is the external identifier used by another attendee in the event? */
    public static function externalIdentifierTaken(int $eventId, string $externalIdentifier, int $exceptId): bool
    {
        $statement = Database::connection()->prepare(
            'SELECT 1 FROM attendees WHERE event_id = :event_id AND external_identifier = :ext AND id <> :id LIMIT 1'
        );
        $statement->execute(['event_id' => $eventId, 'ext' => $externalIdentifier, 'id' => $exceptId]);

        return (bool) $statement->fetchColumn();
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function toPublic(array $row, bool $withDetails = false): array
    {
        $public = [
            'id' => (int) $row['id'],
            'attendeeCode' => $row['attendee_code'],
            'fullName' => $row['full_name'],
            'company' => $row['company'],
            'department' => $row['department'],
            'email' => $row['email'],
            'externalIdentifier' => $row['external_identifier'],
            'status' => $row['status'],
            'createdAt' => $row['created_at'],
        ];
        if (array_key_exists('qr_generated_at', $row)) {
            $public['qrGeneratedAt'] = $row['qr_generated_at'];
        }

        if ($withDetails) {
            $extra = $row['extra_data'] !== null ? json_decode((string) $row['extra_data'], true) : null;
            $public += [
                'eventId' => (int) $row['event_id'],
                'archivedAt' => $row['archived_at'],
                'updatedAt' => $row['updated_at'],
                'importBatchId' => $row['import_batch_id'] !== null ? (int) $row['import_batch_id'] : null,
                'extraData' => is_array($extra) ? $extra : (object) [],
            ];
        }

        return $public;
    }
}
