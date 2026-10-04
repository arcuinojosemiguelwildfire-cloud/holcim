<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Models\Event;
use App\Models\EventDay;
use App\Models\RandomizerDraw;
use App\Models\User;

/**
 * Settings > Randomizer Reset (Phase 9.4, admin only).
 *
 * Lifts the "no repeat winner" exclusion for ONE event day and Minor, Major
 * or both, so previous winners can be drawn again. It does NOT delete draws:
 * each currently excluding draw (not VOID, not already reset) of that day +
 * randomizer gets reset_at / reset_by and stays in the draw history,
 * Winners.xlsx and the draw report. Registrations, attendees, QR codes,
 * manual participants, eligibility and every other day / randomizer are
 * untouched.
 *
 * The update runs in a transaction holding the same event-day row lock as a
 * draw (RandomizerDraw::lockDay), so a reset and a draw of that day are
 * serialised: a draw either finishes before the reset (and its winner is
 * reset too) or starts after it (and sees the reset state).
 */
final class RandomizerResetService
{
    public const SCOPES = ['minor', 'major', 'both'];

    /** Confirmation phrase per scope. */
    public const PHRASES = [
        'minor' => 'RESET MINOR DRAW',
        'major' => 'RESET MAJOR DRAW',
        'both' => 'RESET RAFFLE DRAWS',
    ];

    /**
     * Read-only preview: current excluding winners of the day per randomizer.
     *
     * @return array<string, mixed>
     */
    public static function preview(int $eventId, int $dayId): array
    {
        [$event, $day] = self::eventDay($eventId, $dayId);
        $result = [
            'event' => ['id' => (int) $event['id'], 'name' => $event['name']],
            'eventDay' => EventDay::toPublic($day),
            'phrases' => self::PHRASES,
        ];
        foreach (RandomizerDraw::TYPES as $type) {
            $rows = RandomizerDraw::excludingDraws($dayId, $type);
            $result[$type] = [
                'count' => count($rows),
                'winners' => array_map(static fn (array $row): array => [
                    'attendeeCode' => $row['attendee_code'],
                    'fullName' => $row['full_name'],
                    'company' => $row['company'],
                    'department' => $row['department'],
                    'drawnAt' => $row['selected_at'],
                ], $rows),
            ];
        }

        return $result;
    }

    /**
     * Verifies the admin, phrase and password, then resets.
     *
     * @return array<string, mixed>
     */
    public static function reset(Request $request, int $eventId, int $dayId, string $scope, string $confirmation, string $password): array
    {
        $admin = AuthService::currentUser();
        if ($admin === null || $admin['role'] !== User::ROLE_ADMIN) {
            throw HttpException::forbidden();
        }
        if (!in_array($scope, self::SCOPES, true)) {
            throw HttpException::validation(['randomizer' => ['Choose Minor, Major or Minor + Major.']]);
        }
        [$event, $day] = self::eventDay($eventId, $dayId);
        $label = self::label($scope);
        $dayName = "Day {$day['day_number']}";

        $errors = [];
        if (trim($confirmation) !== self::PHRASES[$scope]) {
            $errors['confirmation'][] = 'Type ' . self::PHRASES[$scope] . ' exactly to confirm.';
        }
        $hash = User::passwordHashFor((int) $admin['id']);
        if ($password === '' || $hash === null || !password_verify($password, $hash)) {
            $errors['password'][] = 'Your current password is incorrect.';
        }
        if ($errors !== []) {
            AuditLogger::log($request, 'randomizer.reset_refused', "{$label} reset for {$dayName} refused (confirmation or password incorrect).",
                $eventId, ['event_day_id' => $dayId, 'randomizer' => $scope]);
            throw HttpException::validation($errors, 'The reset was not performed.');
        }

        $types = $scope === 'both' ? RandomizerDraw::TYPES : [$scope];
        $userId = (int) $admin['id'];
        $result = Database::transaction(static function () use ($dayId, $types, $userId): array {
            RandomizerDraw::lockDay($dayId); // same lock as a draw: never runs in the middle of one
            $affected = [];
            foreach ($types as $type) {
                $rows = RandomizerDraw::excludingDraws($dayId, $type);
                $changed = RandomizerDraw::resetExclusions($dayId, $type, $userId);
                if ($changed !== count($rows)) {
                    throw new \RuntimeException('Randomizer reset changed an unexpected number of rows.');
                }
                $affected[$type] = $rows;
            }

            return $affected;
        });

        $total = array_sum(array_map('count', $result));
        $counts = array_map('count', $result);
        AuditLogger::log(
            $request,
            'randomizer.reset',
            "{$label} winner exclusions reset for {$event['name']} {$dayName} by {$admin['name']}: {$total} winner(s) can be drawn again. Draw history kept.",
            $eventId,
            [
                'event_day_id' => $dayId,
                'day_number' => (int) $day['day_number'],
                'randomizer' => $scope,
                'affected' => $counts,
                'draw_ids' => array_map(static fn (array $rows): array => array_map(static fn (array $r): int => (int) $r['id'], $rows), $result),
                'attendees' => array_map(static fn (array $rows): array => array_map(
                    static fn (array $r): string => "{$r['attendee_code']} {$r['full_name']}",
                    $rows
                ), $result),
            ]
        );

        return [
            'event' => ['id' => (int) $event['id'], 'name' => $event['name']],
            'eventDay' => EventDay::toPublic($day),
            'randomizer' => $scope,
            'affected' => $counts,
            'total' => $total,
            'message' => $total === 0
                ? "There were no current {$label} winners for {$dayName}; nothing needed resetting."
                : "{$label} reset for {$dayName}: {$total} previous winner(s) can be drawn again. Draw history was kept.",
        ];
    }

    public static function label(string $scope): string
    {
        return match ($scope) {
            'minor' => 'Minor',
            'major' => 'Major',
            default => 'Minor + Major',
        };
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} */
    private static function eventDay(int $eventId, int $dayId): array
    {
        $event = Event::find($eventId);
        $day = EventDay::find($dayId);
        if ($event === null || $day === null || (int) $day['event_id'] !== (int) $event['id']) {
            throw HttpException::notFound('Event day not found for that event.');
        }

        return [$event, $day];
    }
}
