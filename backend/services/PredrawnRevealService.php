<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Models\Attendee;
use App\Models\EventDay;
use App\Models\PredrawnWinner;
use App\Models\RandomizerDraw;

/**
 * Pre-Drawn Winner Reveal (migration 035), for the ACTIVE event day.
 *
 * The winners were already selected outside this system (Spin the Wheel).
 * An admin assigns each one to a letter A-Z (optional prize label); on the
 * LED screen the operator presses Space (names roll) and then the letter,
 * which calls reveal(): the server checks the assignment and records the
 * reveal in one locked transaction, and only then does the screen show the
 * winner. Every record is labelled "Pre-Drawn Winner" - never a random draw.
 *
 * Separate from the Minor / Major Randomizer: nothing is written to
 * randomizer_draws and Minor / Major eligibility and pools are unchanged.
 *
 * Roles (routes.php): list / roll names / reveal = admin + event operator;
 * assign / edit / remove / void = admin.
 */
final class PredrawnRevealService
{
    public const CATEGORY = 'Pre-Drawn Winner';
    public const PRIZE_MAX = 150;
    private const ROLL_SAMPLE_SIZE = 40;

    /** @return array<string, mixed> assignments + history of the active day */
    public static function overview(): array
    {
        [$event, $day] = EventDayService::activeContext();
        $rows = PredrawnWinner::forDay((int) $day['id']);

        return [
            'event' => ['id' => (int) $event['id'], 'name' => $event['name']],
            'eventDay' => EventDay::toPublic($day),
            'category' => self::CATEGORY,
            'assignments' => array_values(array_map([self::class, 'present'], array_filter($rows, static fn (array $r): bool => $r['voided_at'] === null))),
            'voided' => array_values(array_map([self::class, 'present'], array_filter($rows, static fn (array $r): bool => $r['voided_at'] !== null))),
        ];
    }

    /**
     * Names for the rolling animation: a random sample of the active event's
     * active attendees (display only; never decides anything).
     *
     * @return array{names: list<string>}
     */
    public static function rollNames(): array
    {
        [$event] = EventDayService::activeContext();
        $statement = Database::connection()->prepare(
            "SELECT full_name FROM attendees WHERE event_id = :event_id AND status = 'active' ORDER BY RAND() LIMIT " . self::ROLL_SAMPLE_SIZE
        );
        $statement->execute(['event_id' => (int) $event['id']]);

        return ['names' => array_map('strval', $statement->fetchAll(\PDO::FETCH_COLUMN))];
    }

    /**
     * @param array<string, mixed> $input {letter, attendee_id, prize_label?}
     * @return array<string, mixed>
     */
    public static function assign(Request $request, array $input): array
    {
        [$event, $day] = EventDayService::activeContext();
        [$letter, $attendeeId, $prize] = self::validateInput($input);
        $eventId = (int) $event['id'];
        $dayId = (int) $day['id'];
        $userId = AuthService::currentUserId();

        $id = Database::transaction(static function () use ($eventId, $dayId, $letter, $attendeeId, $prize, $userId): int {
            RandomizerDraw::lockDay($dayId); // same per-day lock as draws: serialises assignment changes and reveals
            self::checkAttendee($eventId, $attendeeId);
            self::checkFree($dayId, $letter, $attendeeId, null);
            try {
                return PredrawnWinner::create($eventId, $dayId, $letter, $attendeeId, $prize, $userId);
            } catch (\PDOException $exception) {
                throw self::duplicateOr($exception);
            }
        });
        $row = PredrawnWinner::findInDay($id, $dayId);
        AuditLogger::log($request, 'predrawn.assigned', sprintf(
            '%s assigned to letter %s: %s (%s)%s, Day %d.',
            self::CATEGORY, $letter, $row['attendee_code'], $row['full_name'], $prize !== null ? " - prize \"{$prize}\"" : '', (int) $day['day_number']
        ), $eventId, ['predrawn_winner_id' => $id, 'event_day_id' => $dayId, 'letter' => $letter, 'attendee_id' => $attendeeId, 'prize_label' => $prize]);

        return ['assignment' => self::present($row)];
    }

    /**
     * Edits an assignment that has NOT been revealed yet.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function update(Request $request, int $id, array $input): array
    {
        [$event, $day] = EventDayService::activeContext();
        [$letter, $attendeeId, $prize] = self::validateInput($input);
        $eventId = (int) $event['id'];
        $dayId = (int) $day['id'];
        $userId = AuthService::currentUserId();

        $before = Database::transaction(static function () use ($id, $eventId, $dayId, $letter, $attendeeId, $prize, $userId): array {
            RandomizerDraw::lockDay($dayId);
            $current = self::liveUnrevealedOrFail($id, $dayId);
            self::checkAttendee($eventId, $attendeeId);
            self::checkFree($dayId, $letter, $attendeeId, $id);
            try {
                if (!PredrawnWinner::updateAssignment($id, $letter, $attendeeId, $prize, $userId)) {
                    throw HttpException::conflict('This assignment was revealed or changed meanwhile. Reload the page.');
                }
            } catch (\PDOException $exception) {
                throw self::duplicateOr($exception);
            }

            return $current;
        });
        $row = PredrawnWinner::findInDay($id, $dayId);
        AuditLogger::log($request, 'predrawn.assignment_updated', sprintf(
            '%s assignment changed: letter %s %s (%s) -> letter %s %s (%s)%s.',
            self::CATEGORY, $before['letter'], $before['attendee_code'], $before['full_name'], $letter, $row['attendee_code'], $row['full_name'],
            $prize !== null ? " - prize \"{$prize}\"" : ''
        ), $eventId, ['predrawn_winner_id' => $id, 'event_day_id' => $dayId, 'letter' => $letter, 'attendee_id' => $attendeeId, 'prize_label' => $prize,
            'previous' => ['letter' => $before['letter'], 'attendee_id' => (int) $before['attendee_id'], 'prize_label' => $before['prize_label']]]);

        return ['assignment' => self::present($row)];
    }

    /** Removes an assignment that has NOT been revealed (revealed ones can only be voided). */
    public static function remove(Request $request, int $id): void
    {
        [$event, $day] = EventDayService::activeContext();
        $dayId = (int) $day['id'];
        $row = Database::transaction(static function () use ($id, $dayId): array {
            RandomizerDraw::lockDay($dayId);
            $current = self::liveUnrevealedOrFail($id, $dayId);
            if (!PredrawnWinner::deleteUnrevealed($id)) {
                throw HttpException::conflict('This assignment was revealed or changed meanwhile. Reload the page.');
            }

            return $current;
        });
        AuditLogger::log($request, 'predrawn.assignment_removed', sprintf(
            '%s assignment removed (not revealed): letter %s, %s (%s).', self::CATEGORY, $row['letter'], $row['attendee_code'], $row['full_name']
        ), (int) $event['id'], ['predrawn_winner_id' => $id, 'event_day_id' => $dayId, 'letter' => $row['letter'], 'attendee_id' => (int) $row['attendee_id'], 'prize_label' => $row['prize_label']]);
    }

    /**
     * Reveals the winner assigned to $letter. One locked transaction: the
     * letter must have a live, unrevealed assignment whose attendee is still
     * active in this event. Concurrent / repeated reveals of the same letter
     * get 409 - exactly one reveal is recorded.
     *
     * @return array<string, mixed>
     */
    public static function reveal(Request $request, mixed $letterInput): array
    {
        [$event, $day] = EventDayService::activeContext();
        $letter = self::letter($letterInput);
        $eventId = (int) $event['id'];
        $dayId = (int) $day['id'];
        $userId = AuthService::currentUserId();

        $id = Database::transaction(static function () use ($eventId, $dayId, $letter, $userId): int {
            RandomizerDraw::lockDay($dayId);
            $row = PredrawnWinner::liveByLetter($dayId, $letter, lock: true);
            if ($row === null) {
                throw new HttpException(404, 'NOT_ASSIGNED', "Letter {$letter} has no pre-drawn winner assigned for this event day.");
            }
            if ($row['revealed_at'] !== null) {
                throw new HttpException(409, 'ALREADY_REVEALED', "Letter {$letter} was already revealed at " . date('g:i A', (int) strtotime((string) $row['revealed_at'])) . '. Void it first to reveal again.');
            }
            $attendee = Attendee::find((int) $row['attendee_id']);
            if ($attendee === null || (int) $attendee['event_id'] !== $eventId || $attendee['status'] !== Attendee::STATUS_ACTIVE) {
                throw new HttpException(409, 'ATTENDEE_INACTIVE', "The attendee assigned to letter {$letter} is no longer active. Change the assignment first.");
            }
            if (!PredrawnWinner::markRevealed((int) $row['id'], $userId)) {
                throw new HttpException(409, 'ALREADY_REVEALED', "Letter {$letter} was already revealed.");
            }

            return (int) $row['id'];
        });

        $row = PredrawnWinner::findInDay($id, $dayId);
        AuditLogger::log($request, 'predrawn.revealed', sprintf(
            '%s revealed (letter %s, Day %d): %s (%s)%s. Selected beforehand outside the system - not a random draw.',
            self::CATEGORY, $letter, (int) $day['day_number'], $row['attendee_code'], $row['full_name'],
            $row['prize_label'] !== null ? " - prize \"{$row['prize_label']}\"" : ''
        ), $eventId, ['predrawn_winner_id' => $id, 'event_day_id' => $dayId, 'letter' => $letter, 'attendee_id' => (int) $row['attendee_id'], 'category' => self::CATEGORY]);

        return [
            'reveal' => self::present($row),
            // What the LED screen shows: the name and, when set, the prize label.
            'winner' => ['fullName' => $row['full_name'], 'prizeLabel' => $row['prize_label']],
        ];
    }

    /** Voids a REVEALED assignment (kept in history); its letter / attendee can then be assigned again. */
    public static function void(Request $request, int $id, ?string $reason): array
    {
        [$event, $day] = EventDayService::activeContext();
        $dayId = (int) $day['id'];
        $reason = $reason !== null && trim($reason) !== '' ? trim($reason) : null;

        $row = Database::transaction(static function () use ($id, $dayId, $reason): array {
            RandomizerDraw::lockDay($dayId);
            $current = PredrawnWinner::findInDay($id, $dayId);
            if ($current === null) {
                throw HttpException::notFound('Pre-drawn winner not found for the current event day.');
            }
            if ($current['voided_at'] !== null) {
                throw HttpException::conflict('This reveal has already been voided.');
            }
            if ($current['revealed_at'] === null) {
                throw HttpException::conflict('This winner has not been revealed. Edit or remove the assignment instead.');
            }
            if (!PredrawnWinner::voidReveal($id, AuthService::currentUserId(), $reason)) {
                throw HttpException::conflict('This reveal has already been voided.');
            }

            return $current;
        });
        AuditLogger::log($request, 'predrawn.reveal_voided', sprintf(
            '%s reveal voided: letter %s, %s (%s)%s.', self::CATEGORY, $row['letter'], $row['attendee_code'], $row['full_name'], $reason !== null ? " - {$reason}" : ''
        ), (int) $event['id'], ['predrawn_winner_id' => $id, 'event_day_id' => $dayId, 'letter' => $row['letter'], 'attendee_id' => (int) $row['attendee_id'], 'reason' => $reason]);

        return ['assignment' => self::present((array) PredrawnWinner::findInDay($id, $dayId))];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function present(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'category' => self::CATEGORY,
            'letter' => $row['letter'],
            'prizeLabel' => $row['prize_label'],
            'status' => PredrawnWinner::status($row),
            'attendee' => [
                'id' => (int) $row['attendee_id'],
                'attendeeCode' => $row['attendee_code'],
                'fullName' => $row['full_name'],
                'company' => $row['company'],
                'department' => $row['department'],
                'active' => $row['attendee_status'] === Attendee::STATUS_ACTIVE,
            ],
            'assignedAt' => $row['assigned_at'],
            'assignedBy' => $row['assigned_by_name'],
            'revealedAt' => $row['revealed_at'],
            'revealedBy' => $row['revealed_by_name'],
            'voidedAt' => $row['voided_at'],
            'voidedBy' => $row['voided_by_name'],
            'voidReason' => $row['void_reason'],
        ];
    }

    /** A single letter A-Z (any case) -> upper case; 422 otherwise. */
    public static function letter(mixed $value): string
    {
        $letter = is_string($value) ? strtoupper(trim($value)) : '';
        if (!preg_match('/^[A-Z]$/', $letter)) {
            throw HttpException::validation(['letter' => ['Choose a single letter from A to Z.']], 'Choose a single letter from A to Z.');
        }

        return $letter;
    }

    /**
     * @param array<string, mixed> $input
     * @return array{0: string, 1: int, 2: ?string}
     */
    private static function validateInput(array $input): array
    {
        $errors = [];
        try {
            $letter = self::letter($input['letter'] ?? null);
        } catch (HttpException) {
            $letter = '';
            $errors['letter'][] = 'Choose a single letter from A to Z.';
        }
        $attendeeId = $input['attendee_id'] ?? null;
        if (!is_int($attendeeId) && !(is_string($attendeeId) && ctype_digit($attendeeId))) {
            $errors['attendee_id'][] = 'Choose the attendee who won.';
        }
        $prize = $input['prize_label'] ?? null;
        if ($prize !== null && !is_string($prize)) {
            $errors['prize_label'][] = 'The prize label must be text.';
            $prize = null;
        }
        $prize = $prize !== null ? trim((string) preg_replace('/\s+/u', ' ', $prize)) : null;
        if ($prize !== null && mb_strlen($prize) > self::PRIZE_MAX) {
            $errors['prize_label'][] = 'The prize label may not be longer than ' . self::PRIZE_MAX . ' characters.';
        }
        if ($errors !== []) {
            throw HttpException::validation($errors, 'Check the assignment.');
        }

        return [$letter, (int) $attendeeId, $prize !== '' ? $prize : null];
    }

    private static function checkAttendee(int $eventId, int $attendeeId): void
    {
        $attendee = $attendeeId > 0 ? Attendee::find($attendeeId) : null;
        if ($attendee === null || (int) $attendee['event_id'] !== $eventId) {
            throw HttpException::validation(['attendee_id' => ['This attendee is not in the active event.']], 'This attendee is not in the active event.');
        }
        if ($attendee['status'] !== Attendee::STATUS_ACTIVE) {
            throw HttpException::validation(['attendee_id' => ['This attendee is archived.']], 'This attendee is archived.');
        }
    }

    /** Letter and attendee must not be used by another LIVE row of the day. */
    private static function checkFree(int $dayId, string $letter, int $attendeeId, ?int $exceptId): void
    {
        $byLetter = PredrawnWinner::liveByLetter($dayId, $letter);
        if ($byLetter !== null && (int) $byLetter['id'] !== $exceptId) {
            throw new HttpException(409, 'LETTER_TAKEN', "Letter {$letter} is already assigned for this event day.", ['fields' => ['letter' => ["Letter {$letter} is already assigned."]]]);
        }
        $byAttendee = PredrawnWinner::liveByAttendee($dayId, $attendeeId);
        if ($byAttendee !== null && (int) $byAttendee['id'] !== $exceptId) {
            throw new HttpException(409, 'ATTENDEE_ASSIGNED', "This attendee is already assigned to letter {$byAttendee['letter']} for this event day.",
                ['fields' => ['attendee_id' => ["Already assigned to letter {$byAttendee['letter']}."]]]);
        }
    }

    /** @return array<string, mixed> */
    private static function liveUnrevealedOrFail(int $id, int $dayId): array
    {
        $row = PredrawnWinner::findInDay($id, $dayId);
        if ($row === null) {
            throw HttpException::notFound('Assignment not found for the current event day.');
        }
        if ($row['voided_at'] !== null || $row['revealed_at'] !== null) {
            throw HttpException::conflict('This winner has already been revealed: it can no longer be edited or removed (void the reveal instead).');
        }

        return $row;
    }

    /** Unique-key race (two admins at once) -> 409; anything else re-thrown. */
    private static function duplicateOr(\PDOException $exception): \Throwable
    {
        return $exception->getCode() === '23000'
            ? HttpException::conflict('That letter or attendee was just assigned by someone else. Reload the page.')
            : $exception;
    }
}
