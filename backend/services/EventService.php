<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Models\Event;

/**
 * Business rules for events.
 *
 * Core rule: at most ONE event can be active at a time. It is enforced here
 * (friendly 409 message) and by a unique index in the database (race-safe).
 */
final class EventService
{
    /** @return list<array<string, mixed>> */
    public static function list(): array
    {
        return array_map([Event::class, 'toPublic'], Event::all());
    }

    /** @return array<string, mixed> */
    public static function get(int $id): array
    {
        return Event::toPublic(self::findOrFail($id));
    }

    /**
     * @param array<string, mixed> $data validated input
     * @return array<string, mixed>
     */
    public static function create(Request $request, array $data): array
    {
        $id = self::guardActiveConflict(static function () use ($data): int {
            if (($data['status'] ?? Event::STATUS_DRAFT) === Event::STATUS_ACTIVE) {
                self::assertNoOtherActive(null);
            }

            $eventId = Event::create($data);
            // Phase 8: every event starts with Day 1 on its event date.
            EventDayService::createFirstDay($eventId, (string) $data['event_date'], ($data['status'] ?? Event::STATUS_DRAFT) === Event::STATUS_ACTIVE);

            return $eventId;
        });

        $event = self::findOrFail($id);
        AuditLogger::log(
            $request,
            AuditLogger::EVENT_CREATED,
            "Created event \"{$event['name']}\".",
            $id,
            ['status' => $event['status'], 'event_date' => $event['event_date']]
        );

        return Event::toPublic($event);
    }

    /**
     * @param array<string, mixed> $data validated input (partial allowed)
     * @return array<string, mixed>
     */
    public static function update(Request $request, int $id, array $data): array
    {
        $before = self::findOrFail($id);

        self::guardActiveConflict(static function () use ($id, $data): void {
            if (($data['status'] ?? null) === Event::STATUS_ACTIVE) {
                self::assertNoOtherActive($id);
            }
            Event::update($id, $data);
        });

        $after = self::findOrFail($id);
        if ($after['status'] === Event::STATUS_ACTIVE) {
            EventDayService::ensureActiveDay($id, (string) $after['event_date']);
        }
        $changes = self::diff($before, $after, array_keys($data));

        if ($changes !== []) {
            $statusChanged = isset($changes['status']);
            AuditLogger::log(
                $request,
                $statusChanged && count($changes) === 1 ? AuditLogger::EVENT_STATUS_CHANGED : AuditLogger::EVENT_UPDATED,
                $statusChanged
                    ? "Event \"{$after['name']}\" status changed from {$before['status']} to {$after['status']}."
                    : "Updated event \"{$after['name']}\".",
                $id,
                ['changes' => $changes]
            );
        }

        return Event::toPublic($after);
    }

    /** @return array<string, mixed> */
    private static function findOrFail(int $id): array
    {
        $event = Event::find($id);
        if ($event === null) {
            throw HttpException::notFound('Event not found.');
        }

        return $event;
    }

    private static function assertNoOtherActive(?int $exceptId): void
    {
        $active = Event::findOtherActiveForUpdate($exceptId);
        if ($active !== null) {
            throw HttpException::conflict(
                "\"{$active['name']}\" is already the active event. Set it to completed or archived before activating another event."
            );
        }
    }

    /**
     * Runs $callback in a transaction and converts a unique-index violation on
     * the single-active-event lock into the same friendly 409.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    private static function guardActiveConflict(callable $callback): mixed
    {
        try {
            return Database::transaction(static fn () => $callback());
        } catch (\PDOException $exception) {
            if ($exception->getCode() === '23000' && str_contains($exception->getMessage(), 'uq_events_single_active')) {
                throw HttpException::conflict('Another event is already active. Set it to completed or archived first.');
            }
            throw $exception;
        }
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @param list<string> $fields
     * @return array<string, array{from: mixed, to: mixed}>
     */
    private static function diff(array $before, array $after, array $fields): array
    {
        $changes = [];
        foreach ($fields as $field) {
            if (($before[$field] ?? null) !== ($after[$field] ?? null)) {
                $changes[$field] = ['from' => $before[$field] ?? null, 'to' => $after[$field] ?? null];
            }
        }

        return $changes;
    }
}
