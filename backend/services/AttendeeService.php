<?php

declare(strict_types=1);

namespace App\Services;

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
