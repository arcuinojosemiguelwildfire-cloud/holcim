<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Attendee;
use App\Models\Event;
use App\Models\RegistrationScan;

/**
 * Builds the dashboard summary for the ACTIVE event.
 *
 * Every metric reports whether it is backed by a working module:
 *   available = true  -> value comes from real database rows
 *   available = false -> the module that defines it is not built yet; value is 0
 * Minor/Major eligibility rules are defined in later phases, so they report
 * available = false instead of guessing.
 */
final class DashboardService
{
    /** @return array<string, mixed> */
    public static function summary(): array
    {
        $event = Event::findActive();

        if ($event === null) {
            return [
                'activeEvent' => null,
                'metrics' => self::metrics(null),
            ];
        }

        return [
            'activeEvent' => Event::toPublic($event),
            'metrics' => self::metrics((int) $event['id']),
        ];
    }

    /** @return array<string, array{value: int, available: bool}> */
    private static function metrics(?int $eventId): array
    {
        return [
            'totalAttendees' => [
                'value' => $eventId !== null ? Attendee::countForEvent($eventId) : 0,
                'available' => true,
            ],
            'registered' => [
                'value' => $eventId !== null ? RegistrationScan::countRegisteredForEvent($eventId) : 0,
                'available' => true,
            ],
            'minorEligible' => ['value' => 0, 'available' => false],
            'majorEligible' => ['value' => 0, 'available' => false],
        ];
    }
}
