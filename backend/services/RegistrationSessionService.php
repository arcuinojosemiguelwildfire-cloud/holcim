<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Models\EventDay;
use App\Models\RegistrationSession;

/**
 * Registration sessions (Phase 10): configuration per event day and the
 * CURRENT session used by the scanners. Only admins change sessions or
 * switch the current one (routes); scanners never choose a session - the
 * server always records a scan in the current session of the active day.
 */
final class RegistrationSessionService
{
    /**
     * [active event, active day, current session of that day].
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, mixed>}
     * @throws HttpException 409 NO_ACTIVE_EVENT / NO_ACTIVE_DAY / NO_ACTIVE_SESSION
     */
    public static function activeContext(): array
    {
        [$event, $day] = EventDayService::activeContext();
        $session = RegistrationSession::currentForDay((int) $day['id']);
        if ($session === null) {
            throw new HttpException(409, 'NO_ACTIVE_SESSION', 'No registration session is active for the current event day. An admin must select the current registration session.');
        }

        return [$event, $day, $session];
    }

    /**
     * A later enabled session of the same day whose start time has passed
     * while an earlier session is still current - shown as a warning at the
     * registration desk ("ask an admin to switch"). Server clock, Asia/Manila.
     *
     * @return array<string, mixed>|null
     */
    public static function pendingSwitch(array $day, array $current): ?array
    {
        $now = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        $currentStart = $current['start_time'] !== null ? "{$current['session_date']} {$current['start_time']}" : null;
        $due = null;
        foreach (RegistrationSession::forDay((int) $day['id']) as $session) {
            if ((int) $session['id'] === (int) $current['id'] || !(bool) $session['is_enabled'] || $session['start_time'] === null) {
                continue;
            }
            $start = "{$session['session_date']} {$session['start_time']}";
            if ($start <= $now && ($currentStart === null || $start > $currentStart)) {
                if ($due === null || $start > "{$due['session_date']} {$due['start_time']}") {
                    $due = $session;
                }
            }
        }

        return $due !== null ? RegistrationSession::toDesk($due) : null;
    }

    /** @return list<array<string, mixed>> sessions of a day for the admin (with registration counts) */
    public static function listForDay(int $dayId): array
    {
        self::dayOrFail($dayId);

        return array_map(
            static fn (array $s): array => RegistrationSession::toPublic($s, RegistrationSession::countRegistrations((int) $s['id'])),
            RegistrationSession::forDay($dayId)
        );
    }

    /**
     * @param array<string, mixed> $data validated input
     * @return array<string, mixed>
     */
    public static function create(Request $request, int $dayId, array $data): array
    {
        $day = self::dayOrFail($dayId);
        $id = self::guardUnique(static function () use ($day, $dayId, $data): int {
            RegistrationSession::lockDay($dayId);
            $hasCurrent = RegistrationSession::currentForDay($dayId) !== null;

            return RegistrationSession::create((int) $day['event_id'], $dayId, [
                'label' => $data['label'],
                'session_date' => $data['session_date'] ?? $day['event_date'],
                'start_time' => $data['start_time'],
                'description' => $data['description'] ?? null,
                'sort_order' => $data['sort_order'] ?? RegistrationSession::nextSortOrder($dayId),
                'is_enabled' => $data['enabled'] ?? true,
                'counts_for_major' => $data['counts_for_major'] ?? false,
                // A day without a current session gets this one (if enabled).
                'is_current' => !$hasCurrent && ($data['enabled'] ?? true),
            ]);
        });
        $session = RegistrationSession::find($id) ?? [];

        AuditLogger::log(
            $request,
            'registration_session.created',
            "Added registration session \"{$session['label']}\" to " . EventDay::displayName($day) . ' (Major: ' . ((bool) $session['counts_for_major'] ? 'counts' : 'does not count') . ').',
            (int) $day['event_id'],
            ['registration_session_id' => $id, 'event_day_id' => $dayId, 'counts_for_major' => (bool) $session['counts_for_major']]
        );

        return RegistrationSession::toPublic($session, 0);
    }

    /**
     * @param array<string, mixed> $data validated input
     * @return array<string, mixed>
     */
    public static function update(Request $request, int $sessionId, array $data): array
    {
        $before = self::sessionOrFail($sessionId);
        $enabled = $data['enabled'] ?? (bool) $before['is_enabled'];
        if (!$enabled && (bool) $before['is_current']) {
            throw HttpException::conflict('This is the current registration session. Switch to another session before disabling it.');
        }
        self::guardUnique(static function () use ($before, $sessionId, $data, $enabled): void {
            RegistrationSession::lockDay((int) $before['event_day_id']);
            RegistrationSession::update($sessionId, [
                'label' => $data['label'],
                'session_date' => $data['session_date'] ?? $before['session_date'],
                'start_time' => $data['start_time'],
                'description' => array_key_exists('description', $data) ? $data['description'] : $before['description'],
                'sort_order' => $data['sort_order'] ?? (int) $before['sort_order'],
                'is_enabled' => $enabled,
                'counts_for_major' => $data['counts_for_major'] ?? (bool) $before['counts_for_major'],
            ]);
        });
        $after = self::sessionOrFail($sessionId);

        $changes = [];
        foreach (['label', 'session_date', 'start_time', 'description', 'sort_order', 'is_enabled', 'counts_for_major'] as $field) {
            if ((string) $before[$field] !== (string) $after[$field]) {
                $changes[$field] = ['from' => $before[$field], 'to' => $after[$field]];
            }
        }
        if ($changes !== []) {
            $majorNote = isset($changes['counts_for_major'])
                ? ' Major eligibility of this session ' . ((bool) $after['counts_for_major'] ? 'turned ON' : 'turned OFF') . '.'
                : '';
            AuditLogger::log(
                $request,
                'registration_session.updated',
                "Updated registration session \"{$after['label']}\".{$majorNote}",
                (int) $after['event_id'],
                ['registration_session_id' => $sessionId, 'event_day_id' => (int) $after['event_day_id'], 'changes' => $changes]
            );
        }

        return RegistrationSession::toPublic($after, RegistrationSession::countRegistrations($sessionId));
    }

    /**
     * Makes the session the CURRENT registration session of its day (admin).
     * Registrations already recorded in other sessions are never changed.
     *
     * @return array<string, mixed>
     */
    public static function activate(Request $request, int $sessionId): array
    {
        $session = self::sessionOrFail($sessionId);
        if (!(bool) $session['is_enabled']) {
            throw HttpException::conflict('This registration session is disabled. Enable it before making it current.');
        }
        $dayId = (int) $session['event_day_id'];
        $previous = Database::transaction(static function () use ($dayId, $sessionId): ?array {
            RegistrationSession::lockDay($dayId);
            $current = RegistrationSession::currentForDay($dayId);
            if ($current !== null && (int) $current['id'] === $sessionId) {
                return null;
            }
            RegistrationSession::setCurrent($dayId, $sessionId);

            return $current ?? [];
        });

        $after = self::sessionOrFail($sessionId);
        if ($previous !== null) {
            $day = EventDay::find($dayId) ?? [];
            AuditLogger::log(
                $request,
                'registration_session.changed',
                "Current registration session for " . EventDay::displayName($day) . " set to \"{$after['label']}\""
                    . ($previous !== [] ? " (was \"{$previous['label']}\")." : '.'),
                (int) $after['event_id'],
                ['registration_session_id' => $sessionId, 'previous_registration_session_id' => $previous !== [] ? (int) $previous['id'] : null, 'event_day_id' => $dayId]
            );
        }

        return RegistrationSession::toPublic($after, RegistrationSession::countRegistrations($sessionId));
    }

    /**
     * Default session for a NEW day (same as migration 033 for existing
     * days): one "Registration" session, current, counts for Major - the
     * behaviour before sessions existed. Admins rename / add sessions later.
     */
    public static function createDefaultForDay(int $eventId, int $dayId, string $date): void
    {
        RegistrationSession::create($eventId, $dayId, [
            'label' => RegistrationSession::DEFAULT_LABEL,
            'session_date' => $date,
            'start_time' => null,
            'description' => null,
            'sort_order' => 1,
            'is_enabled' => true,
            'counts_for_major' => true,
            'is_current' => true,
        ]);
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

    /** @return array<string, mixed> */
    private static function sessionOrFail(int $sessionId): array
    {
        $session = RegistrationSession::find($sessionId);
        if ($session === null) {
            throw HttpException::notFound('Registration session not found.');
        }

        return $session;
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
                throw HttpException::validation(['label' => ['This day already has a registration session with that label.']]);
            }
            throw $exception;
        }
    }
}
