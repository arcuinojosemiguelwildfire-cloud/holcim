<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Models\Attendee;
use App\Models\RegistrationScan;

/**
 * QR check-in for the ACTIVE event. The server is the source of truth:
 * the scanned value is only a lookup key, never proof of identity.
 *
 * Outcomes:
 *   registered          first successful check-in (row inserted, audit-logged)
 *   already_registered  attendee already checked in (nothing inserted)
 *   INVALID_QR          unknown/revoked token (422)
 *   WRONG_EVENT         token belongs to another event (422, no details)
 *   ATTENDEE_INACTIVE   attendee archived (422)
 */
final class RegistrationService
{
    /** @return array<string, mixed> */
    public static function scan(Request $request, string $scannedValue): array
    {
        $event = AttendeeService::activeEventOrFail();
        $eventId = (int) $event['id'];

        $token = self::extractToken($scannedValue);
        $match = $token !== null ? self::findByToken($token) : null;

        if ($match === null) {
            throw self::rejection('INVALID_QR', 'This QR code is not recognized by the event system. Please check the attendee ID.');
        }
        if ((int) $match['event_id'] !== $eventId) {
            throw self::rejection('WRONG_EVENT', 'This attendee QR does not belong to the current event.');
        }
        if ($match['status'] !== Attendee::STATUS_ACTIVE) {
            throw self::rejection('ATTENDEE_INACTIVE', 'This attendee is not currently eligible for registration.');
        }

        $attendeeId = (int) $match['attendee_id'];
        $status = 'registered';

        try {
            RegistrationScan::create($eventId, $attendeeId, (int) $match['qr_id'], AuthService::currentUserId());
        } catch (\PDOException $exception) {
            // Unique (event_id, attendee_id): the DB guarantees one check-in,
            // even if two scanners submit the same QR at the same moment.
            if ($exception->getCode() !== '23000') {
                throw $exception;
            }
            $status = 'already_registered';
        }

        $registration = RegistrationScan::findForAttendee($eventId, $attendeeId);

        if ($status === 'registered') {
            $user = AuthService::currentUser();
            AuditLogger::log(
                $request,
                'registration.checked_in',
                "Registered {$match['attendee_code']} ({$match['full_name']})" . ($user ? " by {$user['name']}." : '.'),
                $eventId,
                ['attendee_id' => $attendeeId, 'registration_id' => (int) ($registration['id'] ?? 0)]
            );
        }

        return [
            'status' => $status,
            'attendee' => [
                'code' => $match['attendee_code'],
                'fullName' => $match['full_name'],
                'department' => $match['department'],
            ],
            'registeredAt' => $registration['scanned_at'] ?? null,
            'minorEligible' => true,
            'counts' => self::counts($eventId),
        ];
    }

    /** @return array<string, mixed> */
    public static function summary(): array
    {
        $event = AttendeeService::activeEventOrFail();
        $eventId = (int) $event['id'];

        return [
            'event' => ['id' => $eventId, 'name' => $event['name']],
            'counts' => self::counts($eventId),
            'recent' => array_map(static fn (array $row): array => [
                'registeredAt' => $row['scanned_at'],
                'attendeeCode' => $row['attendee_code'],
                'fullName' => $row['full_name'],
                'department' => $row['department'],
                'scannedBy' => $row['scanner_name'],
            ], RegistrationScan::recent($eventId, 20)),
        ];
    }

    /** @return array{total: int, registered: int, remaining: int} */
    public static function counts(int $eventId): array
    {
        $total = Attendee::countForEvent($eventId);
        $registered = RegistrationScan::countRegisteredForEvent($eventId);

        return ['total' => $total, 'registered' => $registered, 'remaining' => max(0, $total - $registered)];
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
            'SELECT q.id AS qr_id, a.id AS attendee_id, a.event_id, a.status, a.attendee_code, a.full_name, a.department
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
