<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Models\Attendee;
use App\Models\EventDay;
use App\Models\RegistrationScan;
use App\Models\ScanLog;

/**
 * QR check-in for the ACTIVE DAY of the ACTIVE event (Phase 8). The server
 * is the source of truth: the scanned value is only a lookup key, never proof
 * of identity. The same QR is used on every day; each day has its own
 * check-in. Every attempt is written to scan_logs.
 *
 * Outcomes:
 *   registered          first check-in of the day (row inserted, audit-logged)
 *   already_registered  attendee already checked in today (nothing inserted)
 *   INVALID_QR          unknown/revoked token (422)
 *   WRONG_EVENT         token belongs to another event (422, no details)
 *   ATTENDEE_INACTIVE   attendee archived (422)
 */
final class RegistrationService
{
    public const SCANS_PER_PAGE = 20;

    /** @return array<string, mixed> */
    public static function scan(Request $request, string $scannedValue): array
    {
        [$event, $day] = EventDayService::activeContext();
        $eventId = (int) $event['id'];
        $dayId = (int) $day['id'];
        $userId = AuthService::currentUserId();

        $token = self::extractToken($scannedValue);
        $match = $token !== null ? self::findByToken($token) : null;

        if ($match === null) {
            ScanLog::record($eventId, $dayId, $userId, null, 'invalid_qr');
            throw self::rejection('INVALID_QR', 'This QR code is not recognized by the event system. Please check the attendee ID.');
        }
        if ((int) $match['event_id'] !== $eventId) {
            // No attendee reference: the attendee belongs to another event.
            ScanLog::record($eventId, $dayId, $userId, null, 'wrong_event');
            throw self::rejection('WRONG_EVENT', 'This attendee QR does not belong to the current event.');
        }
        $attendeeId = (int) $match['attendee_id'];
        if ($match['status'] !== Attendee::STATUS_ACTIVE) {
            ScanLog::record($eventId, $dayId, $userId, $attendeeId, 'attendee_inactive');
            throw self::rejection('ATTENDEE_INACTIVE', 'This attendee is not currently eligible for registration.');
        }

        $status = 'registered';
        try {
            RegistrationScan::create($eventId, $dayId, $attendeeId, (int) $match['qr_id'], $userId);
        } catch (\PDOException $exception) {
            // Unique (event_day_id, attendee_id): one check-in per day, even if
            // two scanners submit the same QR at the same moment.
            if ($exception->getCode() !== '23000') {
                throw $exception;
            }
            $status = 'already_registered';
        }
        ScanLog::record($eventId, $dayId, $userId, $attendeeId, $status);

        $registration = RegistrationScan::findForAttendee($dayId, $attendeeId);

        if ($status === 'registered') {
            $user = AuthService::currentUser();
            AuditLogger::log(
                $request,
                'registration.checked_in',
                "Registered {$match['attendee_code']} ({$match['full_name']}) for Day {$day['day_number']}" . ($user ? " by {$user['name']}." : '.'),
                $eventId,
                ['attendee_id' => $attendeeId, 'event_day_id' => $dayId, 'registration_id' => (int) ($registration['id'] ?? 0)]
            );
        }

        return [
            'status' => $status,
            'eventDay' => EventDay::toPublic($day),
            'attendee' => [
                'code' => $match['attendee_code'],
                'fullName' => $match['full_name'],
                'company' => $match['company'],
                'department' => $match['department'],
            ],
            'registeredAt' => $registration['scanned_at'] ?? null,
            'registeredBy' => $registration['scanner_name'] ?? null,
            'minorEligible' => true,
            'counts' => self::counts($eventId, $dayId),
            'scanCounts' => self::scanCounts($dayId, $userId),
        ];
    }

    /** @return array<string, mixed> counters + recent check-ins for the active day */
    public static function summary(): array
    {
        [$event, $day] = EventDayService::activeContext();
        $eventId = (int) $event['id'];
        $dayId = (int) $day['id'];

        return [
            'event' => ['id' => $eventId, 'name' => $event['name']],
            'eventDay' => EventDay::toPublic($day),
            'counts' => self::counts($eventId, $dayId),
            'scanCounts' => self::scanCounts($dayId, AuthService::currentUserId()),
            'recent' => array_map(static fn (array $row): array => [
                'registeredAt' => $row['scanned_at'],
                'attendeeCode' => $row['attendee_code'],
                'fullName' => $row['full_name'],
                'company' => $row['company'],
                'department' => $row['department'],
                'scannedBy' => $row['scanner_name'],
            ], RegistrationScan::recent($dayId, 20)),
        ];
    }

    /**
     * Scan history of the active day: view mine|all, result filter, paginated.
     *
     * @return array<string, mixed>
     */
    public static function scans(string $view, string $filter, int $page): array
    {
        [, $day] = EventDayService::activeContext();
        $dayId = (int) $day['id'];
        $view = $view === 'all' ? 'all' : 'mine';
        $filter = array_key_exists($filter, ScanLog::FILTERS) ? $filter : 'all';
        $page = max(1, $page);
        $perPage = self::SCANS_PER_PAGE;

        $result = ScanLog::page($dayId, $view === 'mine' ? AuthService::currentUserId() : null, $filter, $perPage, ($page - 1) * $perPage);

        return [
            'eventDay' => EventDay::toPublic($day),
            'view' => $view,
            'filter' => $filter,
            'items' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'result' => $row['result'],
                'scannedAt' => $row['scanned_at'],
                'attendeeCode' => $row['attendee_code'],
                'fullName' => $row['full_name'],
                'company' => $row['company'],
                'department' => $row['department'],
                'scannedBy' => $row['scanned_by'],
            ], $result['items']),
            'pagination' => [
                'page' => $page,
                'perPage' => $perPage,
                'total' => $result['total'],
                'totalPages' => max(1, (int) ceil($result['total'] / $perPage)),
            ],
        ];
    }

    /**
     * Read-only attendee lookup for the scanner: minimal fields plus today's
     * registration status (one query, no per-row lookups).
     *
     * @return array<string, mixed>
     */
    public static function searchAttendees(string $search): array
    {
        [$event, $day] = EventDayService::activeContext();
        $search = mb_substr(trim($search), 0, 100);
        $items = [];

        if ($search !== '') {
            $like = '%' . addcslashes($search, '%_\\') . '%';
            $statement = Database::connection()->prepare(
                'SELECT a.attendee_code, a.full_name, a.company, a.department, a.status, r.scanned_at, u.name AS scanned_by
                 FROM attendees a
                 LEFT JOIN registration_scans r ON r.attendee_id = a.id AND r.event_day_id = :day_id
                 LEFT JOIN users u ON u.id = r.scanner_user_id
                 WHERE a.event_id = :event_id
                   AND (a.attendee_code LIKE :s1 OR a.full_name LIKE :s2 OR a.department LIKE :s3 OR a.email LIKE :s4 OR a.company LIKE :s5)
                 ORDER BY a.full_name, a.attendee_code
                 LIMIT 20'
            );
            $statement->execute(['day_id' => (int) $day['id'], 'event_id' => (int) $event['id'], 's1' => $like, 's2' => $like, 's3' => $like, 's4' => $like, 's5' => $like]);
            foreach ($statement->fetchAll() as $row) {
                $items[] = [
                    'attendeeCode' => $row['attendee_code'],
                    'fullName' => $row['full_name'],
                    'company' => $row['company'],
                    'department' => $row['department'],
                    'attendeeStatus' => $row['status'],
                    'registeredToday' => $row['scanned_at'] !== null,
                    'registeredAt' => $row['scanned_at'],
                    'registeredBy' => $row['scanned_by'],
                ];
            }
        }

        return ['eventDay' => EventDay::toPublic($day), 'items' => $items];
    }

    /** @return array{total: int, registered: int, remaining: int} for the day */
    public static function counts(int $eventId, int $dayId): array
    {
        $total = Attendee::countForEvent($eventId);
        $registered = RegistrationScan::countRegisteredForDay($dayId);

        return ['total' => $total, 'registered' => $registered, 'remaining' => max(0, $total - $registered)];
    }

    /** @return array{mine: int, all: int} successful check-ins today (personal / general) */
    public static function scanCounts(int $dayId, ?int $userId): array
    {
        return [
            'mine' => $userId !== null ? RegistrationScan::countCheckIns($dayId, $userId) : 0,
            'all' => RegistrationScan::countCheckIns($dayId),
        ];
    }

    /**
     * Accepts "{APP_URL}/q/{token}", any URL ending in the token, or the bare
     * token. Returns null when the value cannot be a token.
     */
    public static function extractToken(string $value): ?string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > 500) {
            return null;
        }
        if (preg_match('#^https?://#i', $value)) {
            $path = (string) parse_url($value, PHP_URL_PATH);
            $segments = array_values(array_filter(explode('/', $path), static fn ($s) => $s !== ''));
            $value = (string) end($segments);
        }

        return preg_match('/^[A-Za-z0-9_-]{16,64}$/', $value) ? $value : null;
    }

    /** @return array<string, mixed>|null */
    private static function findByToken(string $token): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT q.id AS qr_id, a.id AS attendee_id, a.event_id, a.status, a.attendee_code, a.full_name, a.company, a.department
             FROM attendee_qr_codes q JOIN attendees a ON a.id = q.attendee_id
             WHERE q.token = :token LIMIT 1'
        );
        $statement->execute(['token' => $token]);

        return $statement->fetch() ?: null;
    }

    private static function rejection(string $code, string $message): HttpException
    {
        return new HttpException(422, $code, $message, ['status' => strtolower($code)]);
    }
}
