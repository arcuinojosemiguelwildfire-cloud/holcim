<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Models\Attendee;
use App\Models\AttendeeQrCode;
use App\Utils\Token;

/**
 * Attendee QR codes for the ACTIVE event.
 *
 * - Token: 24 random bytes -> 32-char URL-safe string (192 bits). Opaque: no
 *   personal data; unique index in the DB makes Phase 4 lookups O(1).
 * - QR content: "{APP_URL}/q/{token}", or just the token when APP_URL is not
 *   configured. The scanner (Phase 4) reads the last path segment, so either
 *   form resolves.
 * - A token never changes on attendee edits or re-imports. Only an explicit
 *   regenerate replaces it, and the old token is invalid from that moment.
 * - Images are rendered by the client from the payload; nothing is stored on
 *   disk.
 */
final class QrCodeService
{
    private const TOKEN_BYTES = 24;

    /** @return array<string, mixed> */
    public static function summary(): array
    {
        $event = AttendeeService::activeEventOrFail();
        $counts = AttendeeQrCode::countsForEvent((int) $event['id']);

        return [
            'event' => ['id' => (int) $event['id'], 'name' => $event['name']],
            'activeAttendees' => $counts['active'],
            'generated' => $counts['generated'],
            'missing' => $counts['active'] - $counts['generated'],
            'qrBaseUrl' => self::baseUrl(),
        ];
    }

    /** @return array<string, mixed> QR state of one attendee in the active event */
    public static function forAttendee(int $attendeeId): array
    {
        $attendee = self::attendeeInActiveEvent($attendeeId);

        return self::present($attendee, AttendeeQrCode::findByAttendee($attendeeId));
    }

    /** Creates the QR if missing; never replaces an existing one. */
    public static function generate(Request $request, int $attendeeId): array
    {
        $attendee = self::attendeeInActiveEvent($attendeeId, requireActive: true);
        $existing = AttendeeQrCode::findByAttendee($attendeeId);
        if ($existing !== null) {
            return self::present($attendee, $existing);
        }

        self::insertWithUniqueToken($attendeeId);
        AuditLogger::log($request, 'qr.generated', "Generated QR for {$attendee['attendee_code']} ({$attendee['full_name']}).",
            (int) $attendee['event_id'], ['attendee_id' => $attendeeId]);

        return self::present($attendee, AttendeeQrCode::findByAttendee($attendeeId));
    }

    /** Replaces the token. The previously printed QR becomes invalid. */
    public static function regenerate(Request $request, int $attendeeId): array
    {
        $attendee = self::attendeeInActiveEvent($attendeeId, requireActive: true);
        $existing = AttendeeQrCode::findByAttendee($attendeeId);
        if ($existing === null) {
            return self::generate($request, $attendeeId);
        }

        self::withUniqueToken(static fn (string $token) => AttendeeQrCode::replaceToken($attendeeId, $token));
        AuditLogger::log($request, 'qr.regenerated', "Regenerated QR for {$attendee['attendee_code']} ({$attendee['full_name']}). The previous QR is no longer valid.",
            (int) $attendee['event_id'], [
                'attendee_id' => $attendeeId,
                // Only a short prefix of the revoked token is kept, for traceability.
                'previous_token_prefix' => substr($existing['token'], 0, 6),
                'previous_issued_at' => $existing['updated_at'],
            ]);

        return self::present($attendee, AttendeeQrCode::findByAttendee($attendeeId));
    }

    /**
     * Generates QR codes ONLY for active attendees that have none.
     * Existing QR codes are never touched.
     *
     * @return array<string, mixed>
     */
    public static function generateMissing(Request $request): array
    {
        $event = AttendeeService::activeEventOrFail();
        $eventId = (int) $event['id'];

        $created = Database::transaction(static function (\PDO $pdo) use ($eventId): int {
            $pdo->prepare('SELECT id FROM events WHERE id = :id FOR UPDATE')->execute(['id' => $eventId]);
            $ids = AttendeeQrCode::activeAttendeeIdsWithoutQr($eventId);
            foreach ($ids as $attendeeId) {
                self::insertWithUniqueToken($attendeeId);
            }

            return count($ids);
        });

        if ($created > 0) {
            AuditLogger::log($request, 'qr.generated_missing', "Generated {$created} missing QR code(s).", $eventId, ['created' => $created]);
        }

        return ['created' => $created] + self::summary();
    }

    /**
     * Data for the print sheet: active attendees with a QR in the active event.
     *
     * @param list<int>|null $attendeeIds
     * @return array<string, mixed>
     */
    public static function printData(?array $attendeeIds): array
    {
        $event = AttendeeService::activeEventOrFail();
        $rows = AttendeeQrCode::printRows((int) $event['id'], $attendeeIds);

        return [
            'event' => ['id' => (int) $event['id'], 'name' => $event['name']],
            'items' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'attendeeCode' => $row['attendee_code'],
                'fullName' => $row['full_name'],
                'company' => $row['company'],
                'department' => $row['department'],
                'qrPayload' => self::payload($row['token']),
            ], $rows),
        ];
    }

    public static function payload(string $token): string
    {
        $base = self::baseUrl();

        return $base !== '' ? $base . '/q/' . $token : $token;
    }

    private static function baseUrl(): string
    {
        return rtrim((string) Config::get('app.url', ''), '/');
    }

    /** @return array<string, mixed> */
    private static function attendeeInActiveEvent(int $attendeeId, bool $requireActive = false): array
    {
        $event = AttendeeService::activeEventOrFail();
        $attendee = Attendee::find($attendeeId);
        if ($attendee === null || (int) $attendee['event_id'] !== (int) $event['id']) {
            throw HttpException::notFound('Attendee not found in the active event.');
        }
        if ($requireActive && $attendee['status'] !== Attendee::STATUS_ACTIVE) {
            throw HttpException::conflict('This attendee is archived. Restore the attendee before generating a QR code.');
        }

        return $attendee;
    }

    private static function insertWithUniqueToken(int $attendeeId): void
    {
        self::withUniqueToken(static fn (string $token) => AttendeeQrCode::create($attendeeId, $token));
    }

    /** Retries on the (astronomically unlikely) token collision. */
    private static function withUniqueToken(callable $write): void
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                $write(Token::random(self::TOKEN_BYTES));

                return;
            } catch (\PDOException $exception) {
                if ($attempt >= 3 || !str_contains($exception->getMessage(), 'uq_attendee_qr_codes_token')) {
                    throw $exception;
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $attendee
     * @param array<string, mixed>|null $qr
     * @return array<string, mixed>
     */
    private static function present(array $attendee, ?array $qr): array
    {
        return [
            'attendeeId' => (int) $attendee['id'],
            'attendeeCode' => $attendee['attendee_code'],
            'fullName' => $attendee['full_name'],
            'company' => $attendee['company'],
            'department' => $attendee['department'],
            'attendeeStatus' => $attendee['status'],
            'status' => $qr !== null ? 'generated' : 'missing',
            'generatedAt' => $qr['updated_at'] ?? null,
            'qrPayload' => $qr !== null ? self::payload($qr['token']) : null,
        ];
    }
}
