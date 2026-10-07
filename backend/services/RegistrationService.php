<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Models\Attendee;
use App\Models\EventDay;
use App\Models\RandomizerDraw;
use App\Models\RegistrationScan;
use App\Models\RegistrationSession;
use App\Models\SessionRegistration;
use App\Models\ScanLog;

/**
 * QR registration for the CURRENT REGISTRATION SESSION of the ACTIVE DAY of
 * the ACTIVE event (Phase 10). The server is the source of truth: the scanned
 * value is only a lookup key, never proof of identity, and the session is
 * always the one an admin made current - scanners cannot choose it. The same
 * attendee record and QR are used for every session; each session gets its
 * own registration row (UNIQUE attendee + session). Every attempt is written
 * to scan_logs. The first scan of a day is also kept in registration_scans
 * (the day-level check-in log, unchanged since Phase 8).
 *
 * Outcomes:
 *   registered            first registration in this session (row inserted, audit-logged)
 *   already_registered    already registered in this session (nothing inserted)
 *   REGISTRATION_REMOVED  an admin removed this session's registration (422; ask an admin)
 *   INVALID_QR            unknown/revoked token (422)
 *   WRONG_EVENT           token belongs to another event (422, no details)
 *   ATTENDEE_INACTIVE     attendee archived (422)
 *   NO_ACTIVE_SESSION     no current registration session (409)
 */
final class RegistrationService
{
    public const SCANS_PER_PAGE = 20;

    /** @return array<string, mixed> */
    public static function scan(Request $request, string $scannedValue): array
    {
        [$event, $day, $session] = RegistrationSessionService::activeContext();
        $eventId = (int) $event['id'];
        $dayId = (int) $day['id'];
        $sessionId = (int) $session['id'];
        $userId = AuthService::currentUserId();

        $token = self::extractToken($scannedValue);
        $match = $token !== null ? self::findByToken($token) : null;

        if ($match === null) {
            ScanLog::record($eventId, $dayId, $userId, null, 'invalid_qr', $sessionId);
            throw self::rejection('INVALID_QR', 'This QR code is not recognized by the event system. Please check the attendee ID.');
        }
        if ((int) $match['event_id'] !== $eventId) {
            // No attendee reference: the attendee belongs to another event.
            ScanLog::record($eventId, $dayId, $userId, null, 'wrong_event', $sessionId);
            throw self::rejection('WRONG_EVENT', 'This attendee QR does not belong to the current event.');
        }
        $attendeeId = (int) $match['attendee_id'];
        if ($match['status'] !== Attendee::STATUS_ACTIVE) {
            ScanLog::record($eventId, $dayId, $userId, $attendeeId, 'attendee_inactive', $sessionId);
            throw self::rejection('ATTENDEE_INACTIVE', 'This attendee is not currently eligible for registration.');
        }

        // One transaction: the current session is read with a shared lock so an
        // admin switching sessions waits for this scan (and vice versa).
        [$status, $session, $registration] = Database::transaction(static function () use ($eventId, $dayId, $attendeeId, $userId, $match): array {
            $session = RegistrationSession::currentForDay($dayId, true);
            if ($session === null) {
                throw new HttpException(409, 'NO_ACTIVE_SESSION', 'No registration session is active for the current event day. An admin must select the current registration session.');
            }
            $sessionId = (int) $session['id'];
            $outcome = static fn (array $row): string => $row['status'] === SessionRegistration::STATUS_ACTIVE ? 'already_registered' : 'registration_removed';
            $existing = SessionRegistration::findForSession($sessionId, $attendeeId);
            if ($existing !== null) {
                return [$outcome($existing), $session, $existing];
            }
            try {
                $registrationId = SessionRegistration::create($eventId, $dayId, $sessionId, $attendeeId, SessionRegistration::SOURCE_SCAN, $userId);
            } catch (\PDOException $exception) {
                // UNIQUE (attendee_id, registration_session_id): two scanners, same QR, same moment.
                if ($exception->getCode() !== '23000') {
                    throw $exception;
                }
                $existing = SessionRegistration::findForSession($sessionId, $attendeeId) ?? ['status' => SessionRegistration::STATUS_ACTIVE];

                return [$outcome($existing), $session, $existing];
            }
            SessionRegistration::addChange($registrationId, $eventId, 'scanned', SessionRegistration::STATUS_ACTIVE, $userId);
            try {
                // Day-level check-in log (first scan of the day only).
                RegistrationScan::create($eventId, $dayId, $attendeeId, (int) $match['qr_id'], $userId);
            } catch (\PDOException $exception) {
                if ($exception->getCode() !== '23000') {
                    throw $exception;
                }
            }

            return ['registered', $session, SessionRegistration::findForSession($sessionId, $attendeeId)];
        });
        $sessionId = (int) $session['id'];

        ScanLog::record($eventId, $dayId, $userId, $attendeeId, $status, $sessionId);
        if ($status === 'registration_removed') {
            throw self::rejection('REGISTRATION_REMOVED', "This attendee's registration for {$session['label']} was removed by an admin. Please ask an admin.");
        }

        $majorEligible = RandomizerDraw::inBasePool($dayId, RandomizerDraw::TYPE_MAJOR, $attendeeId);
        if ($status === 'registered') {
            $user = AuthService::currentUser();
            AuditLogger::log(
                $request,
                'registration.created',
                "Registered {$match['attendee_code']} ({$match['full_name']}) for {$session['label']} (Day {$day['day_number']})"
                    . ($user ? " by {$user['name']}." : '.'),
                $eventId,
                [
                    'attendee_id' => $attendeeId, 'event_day_id' => $dayId, 'registration_session_id' => $sessionId,
                    'session_registration_id' => (int) ($registration['id'] ?? 0), 'source' => 'scan', 'major_eligible' => $majorEligible,
                ]
            );
        }

        return [
            'status' => $status,
            'eventDay' => EventDay::toPublic($day),
            'registrationSession' => RegistrationSession::toDesk($session),
            'attendee' => [
                'code' => $match['attendee_code'],
                'fullName' => $match['full_name'],
                'company' => $match['company'],
                'department' => $match['department'],
            ],
            'registeredAt' => $registration['registered_at'] ?? null,
            'registeredBy' => $registration['registered_by_name'] ?? null,
            'minorEligible' => true,
            // Derived on the server from this day's registration sessions (+ manual Major additions).
            'majorEligible' => $majorEligible,
            'counts' => self::counts($eventId, $dayId, $sessionId),
            'scanCounts' => self::scanCounts($sessionId, $userId),
        ];
    }

    /** @return array<string, mixed> counters + recent registrations for the current session */
    public static function summary(): array
    {
        [$event, $day, $session] = RegistrationSessionService::activeContext();
        $eventId = (int) $event['id'];
        $dayId = (int) $day['id'];
        $sessionId = (int) $session['id'];

        return [
            'event' => ['id' => $eventId, 'name' => $event['name']],
            'eventDay' => EventDay::toPublic($day),
            'registrationSession' => RegistrationSession::toDesk($session),
            // A later session has started but this one is still current: ask an admin to switch.
            'pendingSession' => RegistrationSessionService::pendingSwitch($day, $session),
            'counts' => self::counts($eventId, $dayId, $sessionId),
            'scanCounts' => self::scanCounts($sessionId, AuthService::currentUserId()),
            'recent' => array_map(static fn (array $row): array => [
                'registeredAt' => $row['registered_at'],
                'attendeeCode' => $row['attendee_code'],
                'fullName' => $row['full_name'],
                'company' => $row['company'],
                'department' => $row['department'],
                'scannedBy' => $row['registered_by_name'],
                'source' => $row['source'],
            ], SessionRegistration::recentForSession($sessionId, 20)),
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
                'sessionLabel' => $row['session_label'],
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
        $session = RegistrationSession::currentForDay((int) $day['id']);
        $search = mb_substr(trim($search), 0, 100);
        $items = [];

        if ($search !== '') {
            $like = '%' . addcslashes($search, '%_\\') . '%';
            $statement = Database::connection()->prepare(
                "SELECT a.attendee_code, a.full_name, a.company, a.department, a.status,
                        (SELECT MIN(d.registered_at) FROM session_registrations d
                         WHERE d.attendee_id = a.id AND d.event_day_id = :day_id AND d.status = 'active') AS day_registered_at,
                        cur.registered_at AS session_registered_at, u.name AS session_registered_by
                 FROM attendees a
                 LEFT JOIN session_registrations cur ON cur.attendee_id = a.id AND cur.registration_session_id = :session_id AND cur.status = 'active'
                 LEFT JOIN users u ON u.id = cur.registered_by
                 WHERE a.event_id = :event_id
                   AND (a.attendee_code LIKE :s1 OR a.full_name LIKE :s2 OR a.department LIKE :s3 OR a.email LIKE :s4 OR a.company LIKE :s5)
                 ORDER BY a.full_name, a.attendee_code
                 LIMIT 20"
            );
            $statement->execute([
                'day_id' => (int) $day['id'], 'session_id' => $session !== null ? (int) $session['id'] : 0, 'event_id' => (int) $event['id'],
                's1' => $like, 's2' => $like, 's3' => $like, 's4' => $like, 's5' => $like,
            ]);
            foreach ($statement->fetchAll() as $row) {
                $items[] = [
                    'attendeeCode' => $row['attendee_code'],
                    'fullName' => $row['full_name'],
                    'company' => $row['company'],
                    'department' => $row['department'],
                    'attendeeStatus' => $row['status'],
                    // Registered in any session of today / in the current session.
                    'registeredToday' => $row['day_registered_at'] !== null,
                    'registeredThisSession' => $row['session_registered_at'] !== null,
                    'registeredAt' => $row['session_registered_at'] ?? $row['day_registered_at'],
                    'registeredBy' => $row['session_registered_by'],
                ];
            }
        }

        return [
            'eventDay' => EventDay::toPublic($day),
            'registrationSession' => $session !== null ? RegistrationSession::toDesk($session) : null,
            'items' => $items,
        ];
    }

    /**
     * total = active attendees; registered / remaining = the CURRENT session;
     * registeredToday = registered in any session of the day (each attendee once).
     *
     * @return array{total: int, registered: int, remaining: int, registeredToday: int}
     */
    public static function counts(int $eventId, int $dayId, int $sessionId): array
    {
        $total = Attendee::countForEvent($eventId);
        $registered = SessionRegistration::countForSession($sessionId);

        return [
            'total' => $total,
            'registered' => $registered,
            'remaining' => max(0, $total - $registered),
            'registeredToday' => SessionRegistration::countForDay($dayId),
        ];
    }

    /** @return array{mine: int, all: int} successful QR registrations in the current session (personal / general) */
    public static function scanCounts(int $sessionId, ?int $userId): array
    {
        return [
            'mine' => $userId !== null ? SessionRegistration::countScans($sessionId, $userId) : 0,
            'all' => SessionRegistration::countScans($sessionId),
        ];
    }

    /**
     * Resolves a scanned value to a token (Phase 9.5):
     *   - FORMAT A (current labels): the bare token, used as is;
     *   - FORMAT B (older labels):  "http(s)://any-host/.../q/{token}" - the
     *     domain is ignored, the segment after "/q/" is the token.
     * Anything else (other URLs, wrong characters/length) returns null and is
     * reported as an invalid QR. The token is always the identifier.
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
            $count = count($segments);
            if ($count < 2 || $segments[$count - 2] !== 'q') {
                return null;
            }
            $value = $segments[$count - 1];
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
