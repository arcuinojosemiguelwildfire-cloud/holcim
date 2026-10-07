<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Models\Attendee;
use App\Models\EventDay;
use App\Models\RandomizerDraw;
use App\Models\RegistrationSession;
use App\Models\SessionRegistration;

/**
 * Admin "Manage / renew registration" (Phase 10): an attendee's registrations
 * per registration session, their change history and the resulting raffle
 * eligibility per event day.
 *
 * The admin only chooses WHICH SESSIONS the attendee is registered for.
 * Eligibility is never accepted from the client: it is always derived from
 * the stored registrations (see RandomizerDraw). Nothing here touches the
 * attendee record, its attendee_code or its QR token. Rows are never
 * deleted: a removal sets status = 'removed' and every change is appended to
 * session_registration_changes and the audit log.
 */
final class AttendeeRegistrationService
{
    /** @return array<string, mixed> */
    public static function overview(int $attendeeId): array
    {
        $attendee = self::attendeeOrFail($attendeeId);
        $eventId = (int) $attendee['event_id'];
        $registrations = [];
        foreach (SessionRegistration::forAttendee($attendeeId) as $row) {
            $registrations[(int) $row['registration_session_id']] = $row;
        }
        $sessionsByDay = [];
        foreach (RegistrationSession::forEvent($eventId) as $session) {
            $sessionsByDay[(int) $session['event_day_id']][] = $session;
        }

        $days = [];
        foreach (EventDay::forEvent($eventId) as $day) {
            $dayId = (int) $day['id'];
            $sessions = [];
            foreach ($sessionsByDay[$dayId] ?? [] as $session) {
                $row = $registrations[(int) $session['id']] ?? null;
                $sessions[] = [
                    'session' => RegistrationSession::toPublic($session),
                    'registration' => $row === null ? null : [
                        'status' => $row['status'],
                        'source' => $row['source'],
                        'registeredAt' => $row['registered_at'],
                        'registeredBy' => $row['registered_by_name'],
                        'removedAt' => $row['removed_at'],
                        'removedBy' => $row['removed_by_name'],
                        'removalReason' => $row['removal_reason'],
                        'createdAt' => $row['created_at'],
                        'updatedAt' => $row['updated_at'],
                    ],
                ];
            }
            $days[] = [
                'day' => EventDay::toPublic($day),
                'sessions' => $sessions,
            ] + self::eligibility($dayId, $attendee);
        }

        return [
            'attendee' => [
                'id' => $attendeeId,
                'attendeeCode' => $attendee['attendee_code'],
                'fullName' => $attendee['full_name'],
                'company' => $attendee['company'],
                'status' => $attendee['status'],
            ],
            'days' => $days,
            'history' => array_map(static fn (array $c): array => [
                'action' => $c['action'],
                'statusAfter' => $c['status_after'],
                'reason' => $c['reason'],
                'at' => $c['created_at'],
                'by' => $c['performed_by_name'],
                'sessionLabel' => $c['session_label'],
                'dayNumber' => (int) $c['day_number'],
            ], SessionRegistration::changesForAttendee($attendeeId)),
        ];
    }

    /**
     * Applies the admin's desired registration state per session.
     *
     * @param list<array{session_id: int, registered: bool}> $changes
     * @return array<string, mixed> the updated overview + a summary of what changed
     */
    public static function update(Request $request, int $attendeeId, array $changes, ?string $reason): array
    {
        $attendee = self::attendeeOrFail($attendeeId);
        $eventId = (int) $attendee['event_id'];
        $userId = AuthService::currentUserId();

        // Validate every session first (all must belong to the attendee's event).
        $sessions = [];
        foreach ($changes as $change) {
            $session = RegistrationSession::find($change['session_id']);
            if ($session === null || (int) $session['event_id'] !== $eventId) {
                throw HttpException::validation(['sessions' => ['A selected registration session does not belong to this attendee\'s event.']]);
            }
            $sessions[(int) $session['id']] = $session;
        }
        $wantsAdd = array_filter($changes, static fn (array $c): bool => $c['registered']);
        if ($wantsAdd !== [] && $attendee['status'] !== Attendee::STATUS_ACTIVE) {
            throw new HttpException(422, 'ATTENDEE_INACTIVE', 'This attendee is archived. Restore the attendee before adding registrations.');
        }

        $dayIds = array_values(array_unique(array_map(static fn (array $s): int => (int) $s['event_day_id'], $sessions)));
        $before = [];
        foreach ($dayIds as $dayId) {
            $before[$dayId] = self::eligibility($dayId, $attendee);
        }

        $applied = Database::transaction(static function () use ($changes, $sessions, $attendeeId, $eventId, $userId, $reason): array {
            $applied = [];
            foreach ($changes as $change) {
                $session = $sessions[$change['session_id']];
                $sessionId = (int) $session['id'];
                $existing = SessionRegistration::findForSession($sessionId, $attendeeId);
                if ($change['registered']) {
                    if ($existing === null) {
                        try {
                            $id = SessionRegistration::create($eventId, (int) $session['event_day_id'], $sessionId, $attendeeId, SessionRegistration::SOURCE_MANUAL, $userId);
                        } catch (\PDOException $exception) {
                            if ($exception->getCode() !== '23000') {
                                throw $exception;
                            }
                            continue; // registered by a scanner at the same moment - already what the admin wants
                        }
                        SessionRegistration::addChange($id, $eventId, 'manually_added', SessionRegistration::STATUS_ACTIVE, $userId, $reason);
                        $applied[] = ['action' => 'added', 'session' => $session, 'restored' => false];
                    } elseif ($existing['status'] === SessionRegistration::STATUS_REMOVED) {
                        if (SessionRegistration::restore((int) $existing['id'], $userId)) {
                            SessionRegistration::addChange((int) $existing['id'], $eventId, 'restored', SessionRegistration::STATUS_ACTIVE, $userId, $reason);
                            $applied[] = ['action' => 'added', 'session' => $session, 'restored' => true];
                        }
                    }
                } elseif ($existing !== null && $existing['status'] === SessionRegistration::STATUS_ACTIVE) {
                    if ($reason === null || $reason === '') {
                        throw HttpException::validation(['reason' => ['A reason is required to remove a registration.']]);
                    }
                    if (SessionRegistration::remove((int) $existing['id'], $userId, $reason)) {
                        SessionRegistration::addChange((int) $existing['id'], $eventId, 'removed', SessionRegistration::STATUS_REMOVED, $userId, $reason);
                        $applied[] = ['action' => 'removed', 'session' => $session, 'restored' => false, 'source' => $existing['source']];
                    }
                }
            }

            return $applied;
        });

        $who = "{$attendee['attendee_code']} ({$attendee['full_name']})";
        foreach ($applied as $item) {
            $session = $item['session'];
            $added = $item['action'] === 'added';
            AuditLogger::log(
                $request,
                $added ? 'registration.manually_added' : 'registration.manually_removed',
                ($added ? ($item['restored'] ? 'Restored' : 'Manually added') . " registration of {$who} for \"{$session['label']}\""
                    : "Removed registration of {$who} for \"{$session['label']}\"")
                    . ($reason !== null && $reason !== '' ? " - {$reason}." : '.'),
                $eventId,
                [
                    'attendee_id' => $attendeeId, 'registration_session_id' => (int) $session['id'], 'event_day_id' => (int) $session['event_day_id'],
                    'restored' => $item['restored'], 'removed_source' => $item['source'] ?? null, 'reason' => $reason,
                ]
            );
        }
        foreach ($dayIds as $dayId) {
            $after = self::eligibility($dayId, $attendee);
            foreach (['minor', 'major'] as $type) {
                if ($before[$dayId]["{$type}Eligible"] !== $after["{$type}Eligible"]) {
                    $day = EventDay::find($dayId) ?? ['day_number' => '?', 'event_date' => '', 'label' => null];
                    AuditLogger::log(
                        $request,
                        'registration.eligibility_changed',
                        ucfirst($type) . " eligibility of {$who} for Day {$day['day_number']} is now "
                            . ($after["{$type}Eligible"] ? 'ELIGIBLE' : 'NOT eligible') . ' (registration change by admin).',
                        $eventId,
                        ['attendee_id' => $attendeeId, 'event_day_id' => $dayId, 'randomizer_type' => $type, 'eligible' => $after["{$type}Eligible"]]
                    );
                }
            }
        }

        return self::overview($attendeeId) + [
            'applied' => array_map(static fn (array $i): array => [
                'action' => $i['restored'] ? 'restored' : $i['action'],
                'sessionLabel' => $i['session']['label'],
            ], $applied),
        ];
    }

    /**
     * Eligibility of an attendee for one day, derived from stored rows only.
     *
     * @param array<string, mixed> $attendee
     * @return array{registered: bool, minorEligible: bool, majorEligible: bool, majorSource: ?string}
     */
    public static function eligibility(int $dayId, array $attendee): array
    {
        $attendeeId = (int) $attendee['id'];
        $active = $attendee['status'] === Attendee::STATUS_ACTIVE;
        $statement = Database::connection()->prepare(
            'SELECT EXISTS (' . SessionRegistration::eligibleSql(RandomizerDraw::TYPE_MINOR, 'd1') . ' AND sr.attendee_id = :a1) AS registered,
                    EXISTS (' . SessionRegistration::eligibleSql(RandomizerDraw::TYPE_MAJOR, 'd2') . ' AND sr.attendee_id = :a2) AS major_registration'
        );
        $statement->execute(['d1' => $dayId, 'a1' => $attendeeId, 'd2' => $dayId, 'a2' => $attendeeId]);
        $row = $statement->fetch() ?: ['registered' => 0, 'major_registration' => 0];
        $minor = $active && RandomizerDraw::inBasePool($dayId, RandomizerDraw::TYPE_MINOR, $attendeeId);
        $major = $active && RandomizerDraw::inBasePool($dayId, RandomizerDraw::TYPE_MAJOR, $attendeeId);

        return [
            'registered' => (bool) $row['registered'],
            'minorEligible' => $minor,
            'majorEligible' => $major,
            'majorSource' => !$major ? null : ((bool) $row['major_registration'] ? 'registration' : 'manual'),
        ];
    }

    /** @return array<string, mixed> */
    private static function attendeeOrFail(int $attendeeId): array
    {
        $attendee = Attendee::find($attendeeId);
        if ($attendee === null) {
            throw HttpException::notFound('Attendee not found.');
        }

        return $attendee;
    }
}
