<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Data access for `registration_scans`. Phase 1 only reads counts; the
 * scanner phase will add the write path.
 */
final class RegistrationScan
{
    /** Number of distinct attendees registered (checked in) for an event. */
    public static function countRegisteredForEvent(int $eventId): int
    {
        $statement = Database::connection()->prepare(
            'SELECT COUNT(*) FROM registration_scans WHERE event_id = :event_id'
        );
        $statement->execute(['event_id' => $eventId]);

        return (int) $statement->fetchColumn();
    }
}
