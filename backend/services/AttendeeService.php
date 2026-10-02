<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Models\Attendee;
use App\Models\Event;

/**
 * Attendee management for the ACTIVE event: list/search, view, edit,
 * archive/restore. attendee_code is never changed here.
 */
final class AttendeeService
{
    public const PAGE_SIZES = [25, 50, 100];

    /** @return array<string, mixed> the active event row */
    public static function activeEventOrFail(): array
    {
        $event = Event::findActive();
        if ($event === null) {
            throw new HttpException(409, 'NO_ACTIVE_EVENT', 'There is no active event. Set an event to Active on the Events page first.');
        }

        return $event;
    }

    /** @return array<string, mixed> */
    public static function list(string $search, ?string $department, ?string $status, int $page, int $perPage, ?string $qrStatus = null): array
    {
        $event = self::activeEventOrFail();
        $eventId = (int) $event['id'];

        $perPage = in_array($perPage, self::PAGE_SIZES, true) ? $perPage : 25;
        $page = max(1, $page);
        $result = Attendee::search($eventId, mb_substr(trim($search), 0, 100), $department, $status, $perPage, ($page - 1) * $perPage, $qrStatus);
        $totalPages = max(1, (int) ceil($result['total'] / $perPage));

        return [
            'event' => Event::toPublic($event),
            'items' => array_map(static fn (array $row): array => Attendee::toPublic($row), $result['items']),
            'pagination' => [
                'page' => $page,
                'perPage' => $perPage,
                'total' => $result['total'],
                'totalPages' => $totalPages,
            ],
            'departments' => Attendee::departments($eventId),
        ];
    }

    /** @return array<string, mixed> */
    public static function get(int $id): array
    {
        return Attendee::toPublic(self::findOrFail($id), withDetails: true);
    }

    /**
     * @param array<string, mixed> $data validated fields
     * @return array<string, mixed>
     */
    public static function update(Request $request, int $id, array $data): array
    {
        $before = self::findOrFail($id);
        $eventId = (int) $before['event_id'];

        $data['email'] = isset($data['email']) && $data['email'] !== '' ? mb_strtolower((string) $data['email']) : null;
        $data['department'] = isset($data['department']) && $data['department'] !== '' ? $data['department'] : null;
        $data['company'] = isset($data['company']) && $data['company'] !== '' ? $data['company'] : null;
        $data['external_identifier'] = isset($data['external_identifier']) && $data['external_identifier'] !== '' ? $data['external_identifier'] : null;

        if ($data['external_identifier'] !== null && Attendee::externalIdentifierTaken($eventId, $data['external_identifier'], $id)) {
            throw HttpException::validation(['external_identifier' => ['Another attendee in this event already has this external identifier.']]);
        }

        Attendee::update($id, $data);
        $after = self::findOrFail($id);

        $changes = [];
        foreach (['full_name', 'company', 'department', 'email', 'external_identifier'] as $field) {
            if ($before[$field] !== $after[$field]) {
                $changes[$field] = ['from' => $before[$field], 'to' => $after[$field]];
            }
        }
        if ($changes !== []) {
            AuditLogger::log(
                $request,
                'attendee.updated',
                "Updated attendee {$after['attendee_code']} ({$after['full_name']}).",
                $eventId,
                ['attendee_id' => $id, 'changes' => $changes]
            );
        }

        return Attendee::toPublic($after, withDetails: true);
    }

    /**
     * Manual "Add Attendee" (admin / event operator): creates ONE attendee in
     * the active event's master list with the next ATT-#### code and a normal
     * QR token. It does NOT register the attendee and does NOT make them raffle
     * eligible - that only happens when their QR is scanned on an event day.
     *
     * Duplicates (exact, no fuzzy matching, all statuses incl. archived):
     * same Employee ID, same email, or same Full Name + Company + Cluster
     * (case/whitespace-insensitive, the import's identity rule).
     *
     * @param array<string, mixed> $data validated fields
     * @return array{attendee: array<string, mixed>, qr: array<string, mixed>}
     */
    public static function create(Request $request, array $data): array
    {
        $event = self::activeEventOrFail();
        $eventId = (int) $event['id'];
        $clean = static function (mixed $value): ?string {
            $value = trim((string) preg_replace('/\s+/u', ' ', (string) ($value ?? '')));

            return $value === '' ? null : $value;
        };
        $record = [
            'full_name' => (string) $clean($data['full_name'] ?? ''),
            'company' => $clean($data['company'] ?? null),
            'department' => $clean($data['department'] ?? null),
            'email' => ($email = $clean($data['email'] ?? null)) !== null ? mb_strtolower($email) : null,
            'external_identifier' => $clean($data['external_identifier'] ?? null),
        ];
        if ($record['full_name'] === '') {
            throw HttpException::validation(['full_name' => ['Full name is required.']]);
        }

        $id = Database::transaction(static function (\PDO $pdo) use ($eventId, $record): int {
            // Serialises code generation with imports and other manual adds.
            $pdo->prepare('SELECT id FROM events WHERE id = :id FOR UPDATE')->execute(['id' => $eventId]);
            self::assertNotDuplicate($eventId, $record);

            $code = Attendee::formatCode(Attendee::maxCodeNumber($eventId) + 1);
            $id = Attendee::create($eventId, $code, $record, null);
            QrCodeService::insertWithUniqueToken($id);

            return $id;
        });

        $attendee = self::findOrFail($id);
        AuditLogger::log(
            $request,
            'attendee.created',
            "Added attendee {$attendee['attendee_code']} ({$attendee['full_name']}) manually.",
            $eventId,
            ['attendee_id' => $id, 'source' => 'manual']
        );

        return ['attendee' => Attendee::toPublic($attendee, withDetails: true), 'qr' => QrCodeService::forAttendee($id)];
    }

    /** @param array<string, ?string> $record */
    private static function assertNotDuplicate(int $eventId, array $record): void
    {
        $norm = static fn (?string $v): string => AttendeeImportService::normaliseText($v);
        $nameKey = $norm($record['full_name']) . '|' . $norm($record['company']) . '|' . $norm($record['department']);
        foreach (Attendee::identityRowsForEvent($eventId) as $row) {
            $where = $row['status'] === Attendee::STATUS_ARCHIVED ? ' (archived)' : '';
            $who = " Existing record: {$row['attendee_code']}{$where}.";
            if ($record['external_identifier'] !== null && $norm($row['external_identifier']) === $norm($record['external_identifier'])) {
                throw new HttpException(409, 'DUPLICATE_ATTENDEE', 'An attendee with this Employee ID already exists.' . $who,
                    ['fields' => ['external_identifier' => ['An attendee with this Employee ID already exists.']], 'attendeeId' => (int) $row['id']]);
            }
            if ($record['email'] !== null && $norm($row['email']) === $norm($record['email'])) {
                throw new HttpException(409, 'DUPLICATE_ATTENDEE', 'An attendee with this email already exists.' . $who,
                    ['fields' => ['email' => ['An attendee with this email already exists.']], 'attendeeId' => (int) $row['id']]);
            }
            if ($norm($row['full_name']) . '|' . $norm($row['company']) . '|' . $norm($row['department']) === $nameKey) {
                throw new HttpException(409, 'DUPLICATE_ATTENDEE', 'An attendee with this Full Name, Company and Cluster already exists.' . $who,
                    ['fields' => ['full_name' => ['An attendee with this Full Name, Company and Cluster already exists.']], 'attendeeId' => (int) $row['id']]);
            }
        }
    }

    /** @return array<string, mixed> */
    public static function setStatus(Request $request, int $id, string $status): array
    {
        $before = self::findOrFail($id);
        if ($before['status'] !== $status) {
            Attendee::setStatus($id, $status);
            AuditLogger::log(
                $request,
                $status === Attendee::STATUS_ARCHIVED ? 'attendee.archived' : 'attendee.restored',
                ($status === Attendee::STATUS_ARCHIVED ? 'Archived' : 'Restored') . " attendee {$before['attendee_code']} ({$before['full_name']}).",
                (int) $before['event_id'],
                ['attendee_id' => $id]
            );
        }

        return Attendee::toPublic(self::findOrFail($id), withDetails: true);
    }

    /** @return array<string, mixed> */
    private static function findOrFail(int $id): array
    {
        $attendee = Attendee::find($id);
        if ($attendee === null) {
            throw HttpException::notFound('Attendee not found.');
        }

        return $attendee;
    }
}
