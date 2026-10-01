<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\HttpException;
use App\Core\Request;
use App\Models\RandomizerDraw;

/**
 * Minor Randomizer for the ACTIVE event.
 *
 * Eligible pool = active attendees of the active event with a successful
 * registration_scans record (queried server-side on every draw).
 * The winner is chosen here with random_int() (CSPRNG); the browser only
 * animates the result. Each draw is stored in randomizer_draws; winners stay
 * eligible (no repeat-winner rule has been defined).
 */
final class MinorRandomizerService
{
    /** Extra eligible names sent for the rolling animation (never the whole pool). */
    private const ROLL_SAMPLE_SIZE = 24;

    /** @return array<string, mixed> */
    public static function summary(): array
    {
        $event = AttendeeService::activeEventOrFail();
        $eventId = (int) $event['id'];

        return [
            'event' => ['id' => $eventId, 'name' => $event['name']],
            'eligibleCount' => RandomizerDraw::countMinorEligible($eventId),
            'recentWinners' => self::recentWinners($eventId),
        ];
    }

    /** @return array<string, mixed> */
    public static function draw(Request $request): array
    {
        $event = AttendeeService::activeEventOrFail();
        $eventId = (int) $event['id'];

        $pool = RandomizerDraw::minorEligibleIds($eventId);
        if ($pool === []) {
            throw new HttpException(409, 'NO_ELIGIBLE_ATTENDEES', 'No eligible attendees yet.');
        }

        $winnerId = $pool[random_int(0, count($pool) - 1)];
        $drawId = RandomizerDraw::create($eventId, $winnerId, RandomizerDraw::TYPE_MINOR, AuthService::currentUserId());

        // Random sample of other eligible names for the rolling effect.
        $others = array_values(array_diff($pool, [$winnerId]));
        $sample = [];
        $count = count($others);
        for ($i = 0; $i < min(self::ROLL_SAMPLE_SIZE, $count); $i++) {
            $j = random_int($i, $count - 1);
            [$others[$i], $others[$j]] = [$others[$j], $others[$i]];
            $sample[] = $others[$i];
        }

        $rows = [];
        foreach (RandomizerDraw::attendeesByIds([$winnerId, ...$sample]) as $row) {
            $rows[(int) $row['id']] = $row;
        }
        $winner = $rows[$winnerId];
        $draw = RandomizerDraw::find($drawId);

        AuditLogger::log(
            $request,
            'randomizer.minor_draw',
            "Minor draw winner: {$winner['attendee_code']} ({$winner['full_name']}).",
            $eventId,
            ['draw_id' => $drawId, 'attendee_id' => $winnerId, 'pool_size' => count($pool)]
        );

        return [
            'drawId' => $drawId,
            'selectedAt' => $draw['selected_at'] ?? null,
            'eligibleCount' => count($pool),
            'winner' => self::present($winner),
            'rollNames' => array_values(array_map(
                static fn (int $id): string => $rows[$id]['full_name'],
                array_filter($sample, static fn (int $id): bool => isset($rows[$id]))
            )),
            'recentWinners' => self::recentWinners($eventId),
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function recentWinners(int $eventId): array
    {
        return array_map(static fn (array $row): array => [
            'drawId' => (int) $row['id'],
            'selectedAt' => $row['selected_at'],
            'attendeeCode' => $row['attendee_code'],
            'fullName' => $row['full_name'],
            'department' => $row['department'],
            'drawnBy' => $row['drawn_by_name'],
        ], RandomizerDraw::recent($eventId, RandomizerDraw::TYPE_MINOR, 10));
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function present(array $row): array
    {
        return [
            'attendeeCode' => $row['attendee_code'],
            'fullName' => $row['full_name'],
            'department' => $row['department'],
        ];
    }
}
