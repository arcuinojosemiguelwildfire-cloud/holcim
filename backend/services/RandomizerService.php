<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\HttpException;
use App\Core\Request;
use App\Models\Attendee;
use App\Models\EventDay;
use App\Models\RandomizerDraw;
use App\Models\RandomizerParticipant;

/**
 * Minor and Major Randomizers for the ACTIVE DAY of the ACTIVE event.
 *
 * Eligible pool (queried server-side on every draw, current day only):
 *   minor = active attendees registered today OR manually added today
 *   major = active attendees with a major_eligibility row for today
 *           (imported responses or manual additions)
 * The winner is chosen here with random_int() (CSPRNG); the browser only
 * animates the result. Each draw is stored in randomizer_draws; winners stay
 * eligible (no repeat-winner rule has been defined).
 */
final class RandomizerService
{
    /** Extra eligible names sent for the rolling animation (never the whole pool). */
    private const ROLL_SAMPLE_SIZE = 24;

    /** @return array<string, mixed> */
    public static function summary(string $type): array
    {
        [$event, $day] = EventDayService::activeContext();
        $dayId = (int) $day['id'];

        return [
            'event' => ['id' => (int) $event['id'], 'name' => $event['name']],
            'eventDay' => EventDay::toPublic($day),
            'eligibleCount' => RandomizerDraw::countEligible($dayId, $type),
            'recentWinners' => self::recentWinners($dayId, $type),
        ];
    }

    /** @return array<string, mixed> */
    public static function draw(Request $request, string $type): array
    {
        [$event, $day] = EventDayService::activeContext();
        $eventId = (int) $event['id'];
        $dayId = (int) $day['id'];

        $pool = RandomizerDraw::eligibleIds($dayId, $type);
        if ($pool === []) {
            throw new HttpException(409, 'NO_ELIGIBLE_ATTENDEES', "No eligible attendees for Day {$day['day_number']} yet.");
        }

        $winnerId = $pool[random_int(0, count($pool) - 1)];
        $drawId = RandomizerDraw::create($eventId, $dayId, $winnerId, $type, AuthService::currentUserId());

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
            "randomizer.{$type}_draw",
            ucfirst($type) . " draw (Day {$day['day_number']}) winner: {$winner['attendee_code']} ({$winner['full_name']}).",
            $eventId,
            ['draw_id' => $drawId, 'event_day_id' => $dayId, 'attendee_id' => $winnerId, 'pool_size' => count($pool)]
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
            'recentWinners' => self::recentWinners($dayId, $type),
        ];
    }

    /**
     * Marks a draw of the ACTIVE DAY as VOID (e.g. winner not present).
     * The record stays in history; eligibility is not touched.
     *
     * @return array<string, mixed>
     */
    public static function void(Request $request, int $drawId, ?string $reason): array
    {
        [$event, $day] = EventDayService::activeContext();
        $eventId = (int) $event['id'];
        $dayId = (int) $day['id'];

        // Voids are day-specific: only draws of the current day can be voided.
        $draw = RandomizerDraw::findInDay($drawId, $dayId);
        if ($draw === null) {
            throw HttpException::notFound('Draw not found for the current event day.');
        }
        if (!RandomizerDraw::void($drawId, AuthService::currentUserId(), $reason)) {
            throw HttpException::conflict('This draw has already been voided.');
        }

        AuditLogger::log(
            $request,
            "randomizer.{$draw['randomizer_type']}_draw_voided",
            ucfirst((string) $draw['randomizer_type']) . " draw #{$drawId} voided: {$draw['attendee_code']} ({$draw['full_name']})"
                . ($reason !== null && $reason !== '' ? " - {$reason}" : '') . '.',
            $eventId,
            ['draw_id' => $drawId, 'event_day_id' => $dayId, 'attendee_id' => (int) $draw['attendee_id'], 'reason' => $reason]
        );

        return [
            'drawId' => $drawId,
            'status' => 'void',
            'recentWinners' => self::recentWinners($dayId, (string) $draw['randomizer_type']),
        ];
    }

    /**
     * Search active attendees to add to today's pool.
     *
     * @return array<string, mixed>
     */
    public static function candidates(string $type, string $search): array
    {
        [$event, $day] = EventDayService::activeContext();
        $search = mb_substr(trim($search), 0, 100);
        $rows = $search === '' ? [] : RandomizerParticipant::candidates((int) $event['id'], (int) $day['id'], $type, $search);

        return [
            'eventDay' => EventDay::toPublic($day),
            'items' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'attendeeCode' => $row['attendee_code'],
                'fullName' => $row['full_name'],
                'department' => $row['department'],
                'email' => $row['email'],
                'alreadyEligible' => (bool) $row['already_eligible'],
            ], $rows),
        ];
    }

    /**
     * Today's pool with each participant's source, paginated.
     *
     * @return array<string, mixed>
     */
    public static function participants(string $type, string $search, int $page): array
    {
        [, $day] = EventDayService::activeContext();
        $dayId = (int) $day['id'];
        $perPage = 25;
        $page = max(1, $page);
        $result = RandomizerParticipant::pool($dayId, $type, mb_substr(trim($search), 0, 100), $perPage, ($page - 1) * $perPage);

        return [
            'eventDay' => EventDay::toPublic($day),
            'eligibleCount' => RandomizerDraw::countEligible($dayId, $type),
            'items' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'attendeeCode' => $row['attendee_code'],
                'fullName' => $row['full_name'],
                'department' => $row['department'],
                'source' => $row['source'],
                'addedAt' => $row['added_at'],
                'addedBy' => $row['added_by'],
                'reason' => $row['reason'],
            ], $result['items']),
            'pagination' => [
                'page' => $page,
                'perPage' => $perPage,
                'total' => $result['total'],
                'totalPages' => max(1, (int) ceil($result['total'] / $perPage)),
            ],
        ];
    }

    /**
     * Manually adds an active attendee of the active event to TODAY's pool.
     * Creates a day-specific record (source manual) only: the attendee,
     * QR code and registration are not changed; no check-in is created.
     *
     * @return array<string, mixed>
     */
    public static function addParticipant(Request $request, string $type, int $attendeeId, ?string $reason): array
    {
        [$event, $day] = EventDayService::activeContext();
        $eventId = (int) $event['id'];
        $dayId = (int) $day['id'];

        $attendee = Attendee::find($attendeeId);
        if ($attendee === null || (int) $attendee['event_id'] !== $eventId) {
            throw HttpException::notFound('Attendee not found in the active event.');
        }
        if ($attendee['status'] !== Attendee::STATUS_ACTIVE) {
            throw new HttpException(422, 'ATTENDEE_INACTIVE', 'This attendee is archived and cannot be added.');
        }

        $label = $type === RandomizerDraw::TYPE_MAJOR ? 'Major' : 'Minor';
        $already = new HttpException(409, 'ALREADY_ELIGIBLE', "{$attendee['full_name']} is already eligible for today's {$label} draw.");
        if (RandomizerDraw::isEligible($dayId, $type, $attendeeId)) {
            throw $already;
        }

        $userId = AuthService::currentUserId();
        try {
            if ($type === RandomizerDraw::TYPE_MAJOR) {
                RandomizerParticipant::addMajor($eventId, $dayId, $attendeeId, $userId, $reason);
            } else {
                RandomizerParticipant::addMinor($eventId, $dayId, $attendeeId, $userId, $reason);
            }
        } catch (\PDOException $exception) {
            if ($exception->getCode() === '23000') {
                throw $already; // added by someone else at the same moment
            }
            throw $exception;
        }

        AuditLogger::log(
            $request,
            "randomizer.{$type}_participant_added",
            "Manually added {$attendee['attendee_code']} ({$attendee['full_name']}) to the {$label} pool for Day {$day['day_number']}"
                . ($reason !== null && $reason !== '' ? " - {$reason}" : '') . '.',
            $eventId,
            ['attendee_id' => $attendeeId, 'event_day_id' => $dayId, 'source' => 'manual', 'reason' => $reason]
        );

        return [
            'attendee' => ['id' => $attendeeId, 'attendeeCode' => $attendee['attendee_code'], 'fullName' => $attendee['full_name'], 'department' => $attendee['department']],
            'source' => 'manual',
            'eventDay' => EventDay::toPublic($day),
            'eligibleCount' => RandomizerDraw::countEligible($dayId, $type),
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function recentWinners(int $dayId, string $type): array
    {
        return array_map(static fn (array $row): array => [
            'drawId' => (int) $row['id'],
            'selectedAt' => $row['selected_at'],
            'attendeeCode' => $row['attendee_code'],
            'fullName' => $row['full_name'],
            'department' => $row['department'],
            'drawnBy' => $row['drawn_by_name'],
            'status' => $row['voided_at'] !== null ? 'void' : 'valid',
            'voidReason' => $row['void_reason'],
            'voidedBy' => $row['voided_by_name'],
        ], RandomizerDraw::recent($dayId, $type, 10));
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
