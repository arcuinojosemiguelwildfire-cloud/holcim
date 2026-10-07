<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Attendee;
use App\Models\Event;
use App\Models\EventDay;
use App\Models\RandomizerDraw;
use App\Models\SessionRegistration;

/**
 * Builds the dashboard summary for the ACTIVE DAY of the ACTIVE event.
 *
 * Every metric reports whether it is backed by a working module:
 *   available = true  -> value comes from real database rows
 *   available = false -> the module that defines it is not built yet; value is 0
 * Registered / Minor / Major are counted for the current Event Day only
 * (Phase 10, see RandomizerDraw; winners of the day excluded from the pools):
 *   Registered     = registered in ANY registration session today (each attendee once)
 *   Minor eligible = registered today OR manually added to Minor today
 *   Major eligible = registered today in a session that counts for Major
 *                    OR manually added to Major today
 */
final class DashboardService
{
    /** @return array<string, mixed> */
    public static function summary(): array
    {
        $event = Event::findActive();
        $day = $event !== null ? EventDay::findActiveForEvent((int) $event['id']) : null;

        return [
            'activeEvent' => $event !== null ? Event::toPublic($event) : null,
            'activeDay' => $day !== null ? EventDay::toPublic($day) : null,
            'metrics' => self::metrics($event !== null ? (int) $event['id'] : null, $day !== null ? (int) $day['id'] : null),
        ];
    }

    /** @return array<string, array{value: int, available: bool}> */
    private static function metrics(?int $eventId, ?int $dayId): array
    {
        return [
            'totalAttendees' => [
                'value' => $eventId !== null ? Attendee::countForEvent($eventId) : 0,
                'available' => true,
            ],
            'registered' => [
                'value' => $dayId !== null ? SessionRegistration::countForDay($dayId) : 0,
                'available' => true,
            ],
            'minorEligible' => [
                'value' => $dayId !== null ? RandomizerDraw::countEligible($dayId, RandomizerDraw::TYPE_MINOR) : 0,
                'available' => true,
            ],
            'majorEligible' => [
                'value' => $dayId !== null ? RandomizerDraw::countEligible($dayId, RandomizerDraw::TYPE_MAJOR) : 0,
                'available' => true,
            ],
        ];
    }
}
