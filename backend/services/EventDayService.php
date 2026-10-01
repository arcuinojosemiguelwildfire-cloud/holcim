<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Models\Event;
use App\Models\EventDay;

/**
 * Event days (Phase 8). The system always works on the ACTIVE day of the
 * ACTIVE event, resolved here on the server. No endpoint accepts an event or
 * day ID from the browser for day-specific work (scans, pools, draws, imports).
 */
final class EventDayService
{
    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>} [active event, active day]
     * @throws HttpException 409 NO_ACTIVE_EVENT / NO_ACTIVE_DAY
     */
    public static function activeContext(): array
    {
        $event = AttendeeService::activeEventOrFail();
        $day = EventDay::findActiveForEvent((int) $event['id']);
        if ($day === null) {
            throw new HttpException(409, 'NO_ACTIVE_DAY', 'The active event has no active Event Day. An admin must set the current day on the Events page.');
        }

        return [$event, $day];
    }

    /** @return array<string, mixed> {event, eventDay} for the current context (nulls when not set) */
    public static function current(): array
    {
        $event = Event::findActive();
        $day = $event !== null ? EventDay::findActiveForEvent((int) $event['id']) : null;

        return [
            'event' => $event !== null ? ['id' => (int) $event['id'], 'name' => $event['name']] : null,
            'eventDay' => $day !== null ? EventDay::toPublic($day) : null,
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function listForEvent(int $eventId): array
    {
        self::eventOrFail($eventId);

        return array_map([EventDay::class, 'toPublic'], EventDay::forEvent($eventId));
    }

    /**
     * Adds the next day (Day N+1) to an event, status upcoming.
     *
     * @param array{event_date: string, label?: ?string} $data
     * @return array<string, mixed>
     */
    public static function create(Request $request, int $eventId, array $data): array
    {
        $event = self::eventOrFail($eventId);
        $id = self::guardUnique(static function () use ($eventId, $data): int {
            EventDay::lockForEvent($eventId);

            return EventDay::create($eventId, EventDay::nextDayNumber($eventId), $data['event_date'], $data['label'] ?? null, EventDay::STATUS_UPCOMING);
        });
        $day = EventDay::find($id) ?? [];

        AuditLogger::log($request, 'event_day.created', "Added " . EventDay::displayName($day) . " to \"{$event['name']}\".", $eventId, ['event_day_id' => $id]);

        return EventDay::toPublic($day);
    }

    /**
     * @param array{event_date: string, label?: ?string} $data
     * @return array<string, mixed>
     */
    public static function update(Request $request, int $dayId, array $data): array
    {
        $before = self::dayOrFail($dayId);
        EventDay::updateDetails($dayId, $data['event_date'], $data['label'] ?? null);
        $after = self::dayOrFail($dayId);

        if ($before['event_date'] !== $after['event_date'] || $before['label'] !== $after['label']) {
            AuditLogger::log($request, 'event_day.updated', 'Updated ' . EventDay::displayName($after) . '.', (int) $after['event_id'], [
                'event_day_id' => $dayId,
                'changes' => [
                    'event_date' => ['from' => $before['event_date'], 'to' => $after['event_date']],
                    'label' => ['from' => $before['label'], 'to' => $after['label']],
                ],
            ]);
        }

        return EventDay::toPublic($after);
    }

    /**
     * Makes $dayId the active day of its event. The previously active day is
     * marked completed (or upcoming if it is a later day, i.e. a correction).
     * Records of every day are kept untouched.
     *
     * @return array<string, mixed>
     */
    public static function activate(Request $request, int $dayId): array
    {
        $day = self::dayOrFail($dayId);
        $eventId = (int) $day['event_id'];

        $previous = Database::transaction(static function () use ($eventId, $dayId): ?array {
            EventDay::lockForEvent($eventId);
            $current = EventDay::findActiveForEvent($eventId);
            if ($current !== null && (int) $current['id'] === $dayId) {
                return null;
            }
            if ($current !== null) {
                // Moving forward completes the old day; moving back (a correction)
                // returns the later day to upcoming.
                $target = EventDay::find($dayId);
                $movingForward = $target !== null && (int) $target['day_number'] > (int) $current['day_number'];
                EventDay::setStatus((int) $current['id'], $movingForward ? EventDay::STATUS_COMPLETED : EventDay::STATUS_UPCOMING);
            }
            EventDay::setStatus($dayId, EventDay::STATUS_ACTIVE);

            return $current ?? [];
        });

        $after = self::dayOrFail($dayId);
        if ($previous !== null) {
            AuditLogger::log(
                $request,
                'event_day.activated',
                'Current Event Day set to ' . EventDay::displayName($after)
                    . ($previous !== [] ? ' (was ' . EventDay::displayName($previous) . ').' : '.'),
                $eventId,
                ['event_day_id' => $dayId, 'previous_event_day_id' => $previous !== [] ? (int) $previous['id'] : null]
            );
        }

        return EventDay::toPublic($after);
    }

    /** Creates Day 1 for a new event (inside the event-create transaction). */
    public static function createFirstDay(int $eventId, string $date, bool $active): void
    {
        EventDay::create($eventId, 1, $date, null, $active ? EventDay::STATUS_ACTIVE : EventDay::STATUS_UPCOMING);
    }

    /**
     * When an event becomes active and none of its days is active, activate
     * the first day that is not completed (or Day 1). Creates Day 1 if missing.
     */
    public static function ensureActiveDay(int $eventId, string $fallbackDate): void
    {
        Database::transaction(static function () use ($eventId, $fallbackDate): void {
            EventDay::lockForEvent($eventId);
            if (EventDay::findActiveForEvent($eventId) !== null) {
                return;
            }
            $days = EventDay::forEvent($eventId);
            if ($days === []) {
                self::createFirstDay($eventId, $fallbackDate, true);

                return;
            }
            $pick = $days[0];
            foreach ($days as $day) {
                if ($day['status'] !== EventDay::STATUS_COMPLETED) {
                    $pick = $day;
                    break;
                }
            }
            EventDay::setStatus((int) $pick['id'], EventDay::STATUS_ACTIVE);
        });
    }

    /** @return array<string, mixed> */
    private static function eventOrFail(int $eventId): array
    {
        $event = Event::find($eventId);
        if ($event === null) {
            throw HttpException::notFound('Event not found.');
        }

        return $event;
    }

    /** @return array<string, mixed> */
    private static function dayOrFail(int $dayId): array
    {
        $day = EventDay::find($dayId);
        if ($day === null) {
            throw HttpException::notFound('Event day not found.');
        }

        return $day;
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    private static function guardUnique(callable $callback): mixed
    {
        try {
            return Database::transaction(static fn () => $callback());
        } catch (\PDOException $exception) {
            if ($exception->getCode() === '23000') {
                throw HttpException::conflict('That day could not be added because another change happened at the same time. Try again.');
            }
            throw $exception;
        }
    }
}
