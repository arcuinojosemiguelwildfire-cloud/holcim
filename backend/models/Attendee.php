<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Data access for `attendees`. Phase 1 only needs counts for the dashboard;
 * import/management queries arrive with the Attendee Import phase.
 */
final class Attendee
{
    public static function countForEvent(int $eventId): int
    {
        $statement = Database::connection()->prepare(
            'SELECT COUNT(*) FROM attendees WHERE event_id = :event_id'
        );
        $statement->execute(['event_id' => $eventId]);

        return (int) $statement->fetchColumn();
    }
}
